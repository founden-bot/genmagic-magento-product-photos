<?php
/**
 * Copyright GenMagic. All rights reserved.
 * See LICENSE for license details.
 */
declare(strict_types=1);

namespace GenMagic\ProductPhotos\Model;

use GenMagic\ProductPhotos\Model\Api\ApiException;
use GenMagic\ProductPhotos\Model\Api\Client;
use Magento\Backend\Model\Auth\Session as AuthSession;
use Magento\Catalog\Api\ProductRepositoryInterface;
use Magento\Catalog\Model\Product;
use Magento\Catalog\Model\ResourceModel\Product as ProductResource;
use Magento\Framework\App\Filesystem\DirectoryList;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Framework\Filesystem;
use Magento\Framework\Lock\LockManagerInterface;
use Magento\Framework\UrlInterface;
use Magento\Store\Model\Store;
use Magento\Store\Model\StoreManagerInterface;
use Psr\Log\LoggerInterface;

/**
 * Makes a new photo of a product from its base image and adds a chosen result to the product media gallery.
 *
 * A result is kept as a temporary file in pub/media/tmp/genmagic until the merchant adds it (or it is cleaned up
 * after a day). It is named after the product and the admin user, so only the same user can add it, to the same
 * product.
 */
class PhotoService
{
    public const DEFAULT_MODEL = 'google/gemini-nano-banana-2.1';
    public const IMAGE_SIZE = '1024x1024';
    public const MAX_RESULT_BYTES = 15000000;
    public const TMP_DIR = 'tmp/genmagic';
    public const TMP_MAX_AGE = 86400;

    private const TOKEN_PATTERN = '/^(\d+)-(\d+)-[a-f0-9]{32}\.(jpg|png|webp)$/';
    private const ROLES = ['image', 'small_image', 'thumbnail'];

    /**
     * @var Config
     */
    private $config;

    /**
     * @var Client
     */
    private $client;

    /**
     * @var PhotoStyles
     */
    private $styles;

    /**
     * @var SourceImage
     */
    private $sourceImage;

    /**
     * @var ProductRepositoryInterface
     */
    private $productRepository;

    /**
     * @var ProductResource
     */
    private $productResource;

    /**
     * @var Filesystem
     */
    private $filesystem;

    /**
     * @var LockManagerInterface
     */
    private $lockManager;

    /**
     * @var AuthSession
     */
    private $authSession;

    /**
     * @var StoreManagerInterface
     */
    private $storeManager;

    /**
     * @var LoggerInterface
     */
    private $logger;

    /**
     * @param Config $config
     * @param Client $client
     * @param PhotoStyles $styles
     * @param SourceImage $sourceImage
     * @param ProductRepositoryInterface $productRepository
     * @param ProductResource $productResource
     * @param Filesystem $filesystem
     * @param LockManagerInterface $lockManager
     * @param AuthSession $authSession
     * @param StoreManagerInterface $storeManager
     * @param LoggerInterface $logger
     * @SuppressWarnings(PHPMD.ExcessiveParameterList)
     */
    public function __construct(
        Config $config,
        Client $client,
        PhotoStyles $styles,
        SourceImage $sourceImage,
        ProductRepositoryInterface $productRepository,
        ProductResource $productResource,
        Filesystem $filesystem,
        LockManagerInterface $lockManager,
        AuthSession $authSession,
        StoreManagerInterface $storeManager,
        LoggerInterface $logger
    ) {
        $this->config = $config;
        $this->client = $client;
        $this->styles = $styles;
        $this->sourceImage = $sourceImage;
        $this->productRepository = $productRepository;
        $this->productResource = $productResource;
        $this->filesystem = $filesystem;
        $this->lockManager = $lockManager;
        $this->authSession = $authSession;
        $this->storeManager = $storeManager;
        $this->logger = $logger;
    }

    /**
     * The image models that work from a photo, with their estimated price, and the default pick.
     *
     * @return array{models: array<int, array<string, mixed>>, default: string}
     * @throws LocalizedException
     */
    public function getModels(): array
    {
        $models = $this->client->getEditingModels();
        $picked = $this->pickModel($models, '');
        return ['models' => $models, 'default' => $picked['id']];
    }

    /**
     * Makes one photo of the product and keeps it as a temporary result.
     *
     * @param int $productId
     * @param string $style
     * @param string $extra The merchant's own words, optional.
     * @param string $requestedModel Model id, optional.
     * @return array<string, mixed>
     * @throws LocalizedException
     */
    public function generate(int $productId, string $style, string $extra, string $requestedModel): array
    {
        if (!$this->config->isEnabled()) {
            throw new LocalizedException(__('GenMagic product photos is switched off in the configuration.'));
        }
        if (!$this->config->hasApiKey()) {
            throw new ApiException($this->client->noKeyMessage());
        }
        if (!$this->styles->isValid($style)) {
            throw new LocalizedException(__('Choose one of the photo styles.'));
        }
        $product = $this->loadProduct($productId);
        $image = $this->sourceImage->getDataUrl($product);
        $model = $this->pickModel($this->client->getEditingModels(), $requestedModel);

        $lockName = 'genmagic_photos_' . $this->getAdminUserId();
        if (!$this->lockManager->lock($lockName, 0)) {
            throw new LocalizedException(
                __('Another image is still being generated. Wait for it to finish first.')
            );
        }
        try {
            $generated = $this->client->createImage(
                $this->styles->buildPrompt($style, $extra),
                $model['id'],
                self::IMAGE_SIZE,
                $image
            );
            $token = $this->storeResult($productId, $this->client->download($generated['url'], self::MAX_RESULT_BYTES));
        } finally {
            $this->lockManager->unlock($lockName);
        }

        return [
            'token' => $token,
            'preview_url' => $this->getTmpUrl($token),
            'model_name' => $model['name'],
            'cost_usd' => $this->centsToUsd($generated['cost_cents']),
            'balance_usd' => $this->centsToUsd($generated['credits_cents']),
        ];
    }

    /**
     * Adds a result to the product media gallery. The base, small and thumbnail roles are only set when asked for.
     *
     * @param int $productId
     * @param string $token The name of the temporary result.
     * @param bool $useAsMain Also use it as the base, small and thumbnail image.
     * @return void
     * @throws LocalizedException
     */
    public function addToGallery(int $productId, string $token, bool $useAsMain): void
    {
        $matches = [];
        if (!preg_match(self::TOKEN_PATTERN, $token, $matches)
            || (int)$matches[1] !== $productId
            || (int)$matches[2] !== $this->getAdminUserId()
        ) {
            throw new LocalizedException(__('This photo is no longer available to add. Make it again.'));
        }
        $tmp = $this->filesystem->getDirectoryWrite(DirectoryList::MEDIA);
        $relative = self::TMP_DIR . '/' . $token;
        if (!$tmp->isFile($relative)) {
            throw new LocalizedException(__('This photo is no longer available to add. Make it again.'));
        }

        $product = $this->loadProduct($productId);
        try {
            $product->setStoreId(Store::DEFAULT_STORE_ID);
            $product->setData('_edit_mode', true);
            $product->addImageToMediaGallery(
                $tmp->getAbsolutePath($relative),
                $useAsMain ? self::ROLES : null,
                true,
                false
            );
            $this->productResource->save($product);
        } catch (\Throwable $e) {
            $this->logger->error('GenMagic could not add a photo to product ' . $productId . ': ' . $e->getMessage());
            throw new LocalizedException(
                __('The photo could not be added to the product gallery: %1', $e->getMessage())
            );
        }
    }

    /**
     * Loads the product in the admin scope.
     *
     * @param int $productId
     * @return Product
     * @throws LocalizedException
     */
    private function loadProduct(int $productId): Product
    {
        try {
            $product = $this->productRepository->getById($productId, true, Store::DEFAULT_STORE_ID, true);
        } catch (NoSuchEntityException $e) {
            throw new LocalizedException(__('Save the product first, then make photos for it.'));
        }
        if (!$product instanceof Product) {
            throw new LocalizedException(__('This product cannot be changed here.'));
        }
        return $product;
    }

    /**
     * The requested model when it works from a photo, else the default, else the first that does.
     *
     * @param array $models Models as the client lists them.
     * @param string $requested
     * @return array{id: string, name: string, est_usd: float|null}
     * @throws LocalizedException
     */
    private function pickModel(array $models, string $requested): array
    {
        if ($models === []) {
            throw new LocalizedException(__('No image model that works from a photo is available right now.'));
        }
        foreach ([$requested, self::DEFAULT_MODEL] as $id) {
            foreach ($models as $model) {
                if ($id !== '' && $model['id'] === $id) {
                    return $model;
                }
            }
            if ($id !== '' && $id === $requested) {
                // A model the merchant picked that cannot work from a photo is refused, never swapped silently.
                throw new LocalizedException(__('That model cannot make a photo from a photo. Pick another one.'));
            }
        }
        return $models[0];
    }

    /**
     * Writes a downloaded result to the temporary folder and returns its name.
     *
     * @param int $productId
     * @param string $bytes
     * @return string
     * @throws LocalizedException
     */
    private function storeResult(int $productId, string $bytes): string
    {
        $extension = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'][
            $this->sourceImage->detectMime($bytes)
        ] ?? '';
        if ($extension === '') {
            throw new LocalizedException(__('GenMagic returned a file that is not a JPEG, PNG or WebP image.'));
        }
        $media = $this->filesystem->getDirectoryWrite(DirectoryList::MEDIA);
        $media->create(self::TMP_DIR);
        $this->cleanTmp();
        $token = $productId . '-' . $this->getAdminUserId() . '-' . bin2hex(random_bytes(16)) . '.' . $extension;
        $media->writeFile(self::TMP_DIR . '/' . $token, $bytes);
        return $token;
    }

    /**
     * Deletes temporary results older than a day.
     *
     * @return void
     */
    private function cleanTmp(): void
    {
        $media = $this->filesystem->getDirectoryWrite(DirectoryList::MEDIA);
        try {
            foreach ($media->read(self::TMP_DIR) as $path) {
                $stat = $media->stat($path);
                if ((int)($stat['mtime'] ?? 0) < time() - self::TMP_MAX_AGE) {
                    $media->delete($path);
                }
            }
        } catch (\Throwable $e) {
            $this->logger->warning('GenMagic could not clean its temporary photos: ' . $e->getMessage());
        }
    }

    /**
     * The address of a temporary result, for the preview.
     *
     * @param string $token
     * @return string
     */
    private function getTmpUrl(string $token): string
    {
        $base = $this->storeManager->getStore()->getBaseUrl(UrlInterface::URL_TYPE_MEDIA);
        return $base . self::TMP_DIR . '/' . $token;
    }

    /**
     * The signed-in admin user's id.
     *
     * @return int
     */
    private function getAdminUserId(): int
    {
        $user = $this->authSession->getUser();
        return $user ? (int)$user->getId() : 0;
    }

    /**
     * Cents as a plain dollar amount, or null when GenMagic did not say.
     *
     * @param float|null $cents
     * @return string|null
     */
    private function centsToUsd(?float $cents): ?string
    {
        return $cents === null ? null : number_format($cents / 100, 2, '.', '');
    }
}
