<?php
/**
 * Copyright GenMagic. All rights reserved.
 * See LICENSE for license details.
 */
declare(strict_types=1);

namespace GenMagic\ProductPhotos\Model;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\Encryption\EncryptorInterface;

/**
 * Module settings (Stores > Configuration > GenMagic).
 */
class Config
{
    public const XML_PATH_ENABLED = 'genmagic/general/enabled';
    public const XML_PATH_API_KEY = 'genmagic/general/api_key';
    public const KEY_URL = 'https://genmagic.co/get-api-key';
    public const PRICING_URL = 'https://genmagic.co/pricing';

    /**
     * @var ScopeConfigInterface
     */
    private $scopeConfig;

    /**
     * @var EncryptorInterface
     */
    private $encryptor;

    /**
     * @param ScopeConfigInterface $scopeConfig
     * @param EncryptorInterface $encryptor
     */
    public function __construct(ScopeConfigInterface $scopeConfig, EncryptorInterface $encryptor)
    {
        $this->scopeConfig = $scopeConfig;
        $this->encryptor = $encryptor;
    }

    /**
     * Whether the product photos section is switched on.
     *
     * @return bool
     */
    public function isEnabled(): bool
    {
        return $this->scopeConfig->isSetFlag(self::XML_PATH_ENABLED);
    }

    /**
     * The saved API key, decrypted. Empty when none was saved.
     *
     * @return string
     */
    public function getApiKey(): string
    {
        $stored = trim((string)$this->scopeConfig->getValue(self::XML_PATH_API_KEY));
        if ($stored === '') {
            return '';
        }
        return trim($this->encryptor->decrypt($stored));
    }

    /**
     * Whether an API key was saved.
     *
     * @return bool
     */
    public function hasApiKey(): bool
    {
        return $this->getApiKey() !== '';
    }
}
