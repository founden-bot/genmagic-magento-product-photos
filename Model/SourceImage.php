<?php
/**
 * Copyright GenMagic. All rights reserved.
 * See LICENSE for license details.
 */
declare(strict_types=1);

namespace GenMagic\ProductPhotos\Model;

use Magento\Catalog\Model\Product;
use Magento\Catalog\Model\Product\Media\Config as MediaConfig;
use Magento\Framework\App\Filesystem\DirectoryList;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Filesystem;

/**
 * Reads a product's base image file from the store's media directory and turns it into the data:image URL that is
 * sent to GenMagic as the picture to work from (so it also works on stores that are not public, such as staging).
 */
class SourceImage
{
    /**
     * GenMagic takes an inline picture under 4,000,000 characters, and base64 makes a file a third larger.
     */
    public const MAX_BYTES = 2900000;

    /**
     * @var Filesystem
     */
    private $filesystem;

    /**
     * @var MediaConfig
     */
    private $mediaConfig;

    /**
     * @param Filesystem $filesystem
     * @param MediaConfig $mediaConfig
     */
    public function __construct(Filesystem $filesystem, MediaConfig $mediaConfig)
    {
        $this->filesystem = $filesystem;
        $this->mediaConfig = $mediaConfig;
    }

    /**
     * The product's base image as a data:image URL.
     *
     * @param Product $product
     * @return string
     * @throws LocalizedException With a message a merchant can act on.
     */
    public function getDataUrl(Product $product): string
    {
        $file = (string)$product->getData('image');
        if ($file === '' || $file === 'no_selection' || str_contains($file, '..')) {
            throw new LocalizedException(
                __('Set a base image first and save the product: the new photo is made from it.')
            );
        }
        $media = $this->filesystem->getDirectoryRead(DirectoryList::MEDIA);
        $path = $this->mediaConfig->getMediaPath($file);
        if (!$media->isFile($path) || !$media->isReadable($path)) {
            throw new LocalizedException(__('The product image file could not be read.'));
        }
        $stat = $media->stat($path);
        $size = (int)($stat['size'] ?? 0);
        if ($size <= 0 || $size > self::MAX_BYTES) {
            throw new LocalizedException(__('The product image is too large to send. Use an image under 2.9 MB.'));
        }
        $bytes = $media->readFile($path);
        $mime = $this->detectMime($bytes);
        if ($mime === '') {
            throw new LocalizedException(__('The product image must be a JPEG, PNG, WebP or GIF.'));
        }
        return 'data:' . $mime . ';base64,' . base64_encode($bytes);
    }

    /**
     * The image type from the first bytes of the file, an empty string when it is not a supported image.
     *
     * @param string $bytes
     * @return string
     */
    public function detectMime(string $bytes): string
    {
        if (str_starts_with($bytes, "\xFF\xD8\xFF")) {
            return 'image/jpeg';
        }
        if (str_starts_with($bytes, "\x89PNG\r\n\x1A\n")) {
            return 'image/png';
        }
        if (str_starts_with($bytes, 'GIF87a') || str_starts_with($bytes, 'GIF89a')) {
            return 'image/gif';
        }
        if (str_starts_with($bytes, 'RIFF') && substr($bytes, 8, 4) === 'WEBP') {
            return 'image/webp';
        }
        return '';
    }
}
