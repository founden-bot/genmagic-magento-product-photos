<?php
/**
 * Copyright GenMagic. All rights reserved.
 * See LICENSE for license details.
 */
declare(strict_types=1);

namespace GenMagic\ProductPhotos\Model\Api;

use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Phrase;

/**
 * A failed call to GenMagic. The message is GenMagic's own text when the API gave one.
 */
class ApiException extends LocalizedException
{
    /**
     * @var int
     */
    private $upstreamStatus;

    /**
     * @var string
     */
    private $upstreamCode;

    /**
     * @param Phrase $phrase
     * @param int $upstreamStatus HTTP status GenMagic answered with, 0 when it was not reached.
     * @param string $upstreamCode GenMagic's error code, when it gave one.
     * @param \Exception|null $cause
     */
    public function __construct(
        Phrase $phrase,
        int $upstreamStatus = 0,
        string $upstreamCode = '',
        ?\Exception $cause = null
    ) {
        parent::__construct($phrase, $cause);
        $this->upstreamStatus = $upstreamStatus;
        $this->upstreamCode = $upstreamCode;
    }

    /**
     * Whether GenMagic refused because the balance is too low.
     *
     * @return bool
     */
    public function isInsufficientCredit(): bool
    {
        return $this->upstreamStatus === 402 || str_contains($this->upstreamCode, 'insufficient');
    }
}
