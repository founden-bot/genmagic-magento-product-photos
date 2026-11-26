<?php
/**
 * Copyright GenMagic. All rights reserved.
 * See LICENSE for license details.
 */
declare(strict_types=1);

namespace GenMagic\ProductPhotos\Model\Api;

use GenMagic\ProductPhotos\Model\Config;
use Laminas\Uri\Http;
use Magento\Framework\App\CacheInterface;
use Magento\Framework\HTTP\Client\CurlFactory;
use Magento\Framework\Serialize\Serializer\Json;
use Psr\Log\LoggerInterface;

/**
 * The only code that talks to the GenMagic API (https://genmagic.co/api/v1). Errors carry GenMagic's own message,
 * because that message says what to do (for example how to top up after a 402).
 */
class Client
{
    public const BASE = 'https://genmagic.co/api/v1';
    public const CLIENT_ID = 'magento-product-photos/1.0.0';
    public const MODELS_TTL = 21600;
    public const MODELS_CACHE_ID = 'genmagic_photos_models_v2_image';
    public const MAX_REDIRECTS = 3;

    /**
     * Hosts GenMagic serves generated files from. Files are only ever downloaded from these.
     */
    private const MEDIA_HOSTS = ['genmagic.co', 'tlwdqzfibvgektzgdfqa.supabase.co'];

    /**
     * @var CurlFactory
     */
    private $curlFactory;

    /**
     * @var Config
     */
    private $config;

    /**
     * @var Json
     */
    private $json;

    /**
     * @var CacheInterface
     */
    private $cache;

    /**
     * @var LoggerInterface
     */
    private $logger;

    /**
     * @param CurlFactory $curlFactory
     * @param Config $config
     * @param Json $json
     * @param CacheInterface $cache
     * @param LoggerInterface $logger
     */
    public function __construct(
        CurlFactory $curlFactory,
        Config $config,
        Json $json,
        CacheInterface $cache,
        LoggerInterface $logger
    ) {
        $this->curlFactory = $curlFactory;
        $this->config = $config;
        $this->json = $json;
        $this->cache = $cache;
        $this->logger = $logger;
    }

    /**
     * The message shown when no key has been saved.
     *
     * @return \Magento\Framework\Phrase
     */
    public function noKeyMessage(): \Magento\Framework\Phrase
    {
        return __(
            'Add your GenMagic API key under Stores > Configuration > GenMagic before making photos. '
            . 'Get a key at %1.',
            Config::KEY_URL
        );
    }

    /**
     * The image models that work from a photo, from the cache or from GenMagic. The list needs no key.
     *
     * @return array<int, array{id: string, name: string, est_usd: float|null}>
     * @throws ApiException
     */
    public function getEditingModels(): array
    {
        $cached = $this->cache->load(self::MODELS_CACHE_ID);
        if (is_string($cached) && $cached !== '') {
            $decoded = $this->decode($cached);
            if ($decoded !== []) {
                return $decoded;
            }
        }

        $response = $this->request('GET', '/models?category=image', null, false, 20);
        $list = $response['data']['data'] ?? [];
        $models = [];
        if (is_array($list)) {
            foreach ($list as $raw) {
                $model = is_array($raw) ? $this->normalizeModel($raw) : null;
                if ($model !== null) {
                    $models[] = $model;
                }
            }
        }
        if ($models === []) {
            throw new ApiException(__('GenMagic returned no models. Try again in a moment.'), 502);
        }
        $this->cache->save($this->json->serialize($models), self::MODELS_CACHE_ID, [], self::MODELS_TTL);
        return $models;
    }

    /**
     * Generates one image from a picture. GenMagic answers when the image is ready, so this can take a while.
     *
     * @param string $prompt
     * @param string $model Model id.
     * @param string $size
     * @param string $image A data:image URL of the product photo.
     * @return array{url: string, cost_cents: float|null, credits_cents: float|null}
     * @throws ApiException
     */
    public function createImage(string $prompt, string $model, string $size, string $image): array
    {
        $response = $this->request(
            'POST',
            '/images/generations',
            ['prompt' => $prompt, 'model' => $model, 'size' => $size, 'image' => $image],
            true,
            120
        );
        $url = $response['data']['data'][0]['url'] ?? '';
        if (!is_string($url) || !$this->isAllowedMediaUrl($url)) {
            throw new ApiException(__('GenMagic did not return a usable image address.'), 502);
        }
        return [
            'url' => $url,
            'cost_cents' => $response['headers']['cost_cents'],
            'credits_cents' => $response['headers']['credits_cents'],
        ];
    }

    /**
     * Downloads a generated file from a GenMagic media host.
     *
     * Every address, including redirects, must be on a GenMagic media host. The API key is never sent to it.
     *
     * @param string $url
     * @param int $maxBytes
     * @return string The file contents.
     * @throws ApiException
     */
    public function download(string $url, int $maxBytes): string
    {
        $hops = 0;
        do {
            if (!$this->isAllowedMediaUrl($url)) {
                throw new ApiException(__('The file is not on a GenMagic address, so it was not downloaded.'));
            }
            $curl = $this->curlFactory->create();
            $curl->setTimeout(120);
            $curl->setOption(CURLOPT_FOLLOWLOCATION, false);
            $curl->setHeaders(['X-GenMagic-Client' => self::CLIENT_ID]);
            try {
                $curl->get($url);
            } catch (\Throwable $e) {
                $this->logger->warning('GenMagic download failed: ' . $e->getMessage());
                throw new ApiException(__('Could not download the result from GenMagic: %1', $e->getMessage()));
            }
            $status = (int)$curl->getStatus();
            $headers = array_change_key_case($curl->getHeaders(), CASE_LOWER);
            $location = $headers['location'] ?? '';
            $redirect = in_array($status, [301, 302, 303, 307, 308], true) && is_string($location) && $location !== '';
            if ($redirect) {
                $url = $location;
                $hops++;
            }
        } while ($redirect && $hops <= self::MAX_REDIRECTS);

        if ($status !== 200 || $redirect) {
            throw new ApiException(__('Could not download the result from GenMagic (HTTP %1).', $status), $status);
        }
        $body = (string)$curl->getBody();
        if ($body === '' || strlen($body) > $maxBytes) {
            throw new ApiException(__('The downloaded file is empty or larger than this extension accepts.'));
        }
        return $body;
    }

    /**
     * Whether a URL points at a place GenMagic serves generated files from (https, no credentials, standard port).
     *
     * @param string $url
     * @return bool
     */
    public function isAllowedMediaUrl(string $url): bool
    {
        try {
            $uri = new Http($url);
        } catch (\InvalidArgumentException $e) {
            return false;
        }
        if ($uri->getScheme() !== 'https' || $uri->getUserInfo() !== null) {
            return false;
        }
        $port = $uri->getPort();
        if ($port !== null && $port !== 443) {
            return false;
        }
        return in_array(strtolower((string)$uri->getHost()), self::MEDIA_HOSTS, true);
    }

    /**
     * Sends one request to the GenMagic API.
     *
     * @param string $method GET or POST.
     * @param string $path Path under the API base, starting with a slash.
     * @param array|null $body JSON body for POST.
     * @param bool $auth Whether to send the API key.
     * @param int $timeout Seconds to wait.
     * @return array{status: int, data: array<mixed>, headers: array{cost_cents: float|null, credits_cents: float|null}}
     * @throws ApiException
     */
    private function request(string $method, string $path, ?array $body, bool $auth, int $timeout): array
    {
        $headers = [
            'Accept' => 'application/json',
            'X-GenMagic-Client' => self::CLIENT_ID,
        ];
        if ($auth) {
            $key = $this->config->getApiKey();
            if ($key === '') {
                throw new ApiException($this->noKeyMessage());
            }
            $headers['Authorization'] = 'Bearer ' . $key;
        }

        // No redirects: the key is only ever sent to the address built here, never to one a reply points to.
        $curl = $this->curlFactory->create();
        $curl->setTimeout($timeout);
        $curl->setOption(CURLOPT_FOLLOWLOCATION, false);
        $url = self::BASE . $path;
        try {
            if ($method === 'POST') {
                $headers['Content-Type'] = 'application/json';
                $curl->setHeaders($headers);
                $curl->post($url, $this->json->serialize($body ?? new \stdClass()));
            } else {
                $curl->setHeaders($headers);
                $curl->get($url);
            }
        } catch (\Throwable $e) {
            $this->logger->warning('GenMagic request failed: ' . $e->getMessage());
            throw new ApiException(__('Could not reach GenMagic: %1', $e->getMessage()), 502);
        }

        $status = (int)$curl->getStatus();
        $data = $this->decode((string)$curl->getBody());
        if ($status < 200 || $status >= 300) {
            [$message, $code] = $this->errorDetails($data);
            if ($message === '') {
                $message = (string)__('GenMagic answered with HTTP %1.', $status);
            }
            throw new ApiException(__('%1', mb_substr($message, 0, 600)), $status, $code);
        }

        $responseHeaders = array_change_key_case($curl->getHeaders(), CASE_LOWER);
        return [
            'status' => $status,
            'data' => $data,
            'headers' => [
                'cost_cents' => $this->numberHeader($responseHeaders, 'x-cost-cents'),
                'credits_cents' => $this->numberHeader($responseHeaders, 'x-credits-remaining'),
            ],
        ];
    }

    /**
     * GenMagic's own error message and code from an error reply.
     *
     * @param array<mixed> $data
     * @return array{0: string, 1: string}
     */
    private function errorDetails(array $data): array
    {
        $message = '';
        $code = '';
        $error = $data['error'] ?? null;
        if (is_array($error)) {
            $message = is_string($error['message'] ?? null) ? $error['message'] : '';
            $rawCode = is_string($error['code'] ?? null) ? $error['code'] : '';
            $code = strtolower((string)preg_replace('/[^A-Za-z0-9_-]/', '', $rawCode));
        } elseif (is_string($error)) {
            $message = $error;
        }
        return [$message, $code];
    }

    /**
     * Reads a numeric response header.
     *
     * @param array<mixed> $headers Lowercase header names.
     * @param string $name
     * @return float|null
     */
    private function numberHeader(array $headers, string $name): ?float
    {
        $value = $headers[$name] ?? null;
        return (is_string($value) || is_numeric($value)) && is_numeric($value) ? (float)$value : null;
    }

    /**
     * Decodes a JSON body into an array, an empty array when it is not JSON.
     *
     * @param string $body
     * @return array<mixed>
     */
    private function decode(string $body): array
    {
        if ($body === '') {
            return [];
        }
        try {
            $data = $this->json->unserialize($body);
        } catch (\InvalidArgumentException $e) {
            return [];
        }
        return is_array($data) ? $data : [];
    }

    /**
     * Keeps the fields this extension uses from one model of GET /models.
     *
     * Only models that take a picture as input are kept, so they can restyle a product photo.
     *
     * @param array<mixed> $raw
     * @return array{id: string, name: string, est_usd: float|null}|null
     */
    private function normalizeModel(array $raw): ?array
    {
        $id = is_string($raw['id'] ?? null) ? $raw['id'] : '';
        if (!preg_match('/^[A-Za-z0-9._:\/@+-]{1,120}$/', $id)) {
            return null;
        }
        $inputs = is_array($raw['input_modalities'] ?? null) ? $raw['input_modalities'] : [];
        if (!in_array('image', $inputs, true)) {
            return null;
        }
        $pricing = is_array($raw['pricing'] ?? null) ? $raw['pricing'] : [];
        $name = is_string($raw['name'] ?? null) && $raw['name'] !== '' ? $raw['name'] : $id;
        $estimate = $pricing['est_per_generation_usd'] ?? null;
        return [
            'id' => $id,
            'name' => mb_substr(trim(strip_tags($name)), 0, 120),
            'est_usd' => is_numeric($estimate) ? (float)$estimate : null,
        ];
    }
}
