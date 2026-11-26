<?php
/**
 * Copyright GenMagic. All rights reserved.
 * See LICENSE for license details.
 */
declare(strict_types=1);

namespace GenMagic\ProductPhotos\Ui\DataProvider\Product\Form\Modifier;

use GenMagic\ProductPhotos\Model\Config;
use GenMagic\ProductPhotos\Model\PhotoStyles;
use Magento\Catalog\Model\Locator\LocatorInterface;
use Magento\Catalog\Ui\DataProvider\Product\Form\Modifier\AbstractModifier;
use Magento\Framework\AuthorizationInterface;
use Magento\Framework\UrlInterface;
use Magento\Ui\Component\Container;
use Magento\Ui\Component\Form\Fieldset;

/**
 * Adds the "GenMagic product photos" section to the admin product form. The section is a UI component whose
 * template escapes everything it shows.
 */
class ProductPhotos extends AbstractModifier
{
    public const GROUP = 'genmagic_product_photos';

    /**
     * @var LocatorInterface
     */
    private $locator;

    /**
     * @var Config
     */
    private $config;

    /**
     * @var PhotoStyles
     */
    private $styles;

    /**
     * @var UrlInterface
     */
    private $urlBuilder;

    /**
     * @var AuthorizationInterface
     */
    private $authorization;

    /**
     * @param LocatorInterface $locator
     * @param Config $config
     * @param PhotoStyles $styles
     * @param UrlInterface $urlBuilder
     * @param AuthorizationInterface $authorization
     */
    public function __construct(
        LocatorInterface $locator,
        Config $config,
        PhotoStyles $styles,
        UrlInterface $urlBuilder,
        AuthorizationInterface $authorization
    ) {
        $this->locator = $locator;
        $this->config = $config;
        $this->styles = $styles;
        $this->urlBuilder = $urlBuilder;
        $this->authorization = $authorization;
    }

    /**
     * @inheritdoc
     */
    public function modifyData(array $data)
    {
        return $data;
    }

    /**
     * @inheritdoc
     */
    public function modifyMeta(array $meta)
    {
        if (!$this->config->isEnabled() || !$this->authorization->isAllowed('GenMagic_ProductPhotos::generate')) {
            return $meta;
        }
        $canConfigure = $this->authorization->isAllowed('GenMagic_ProductPhotos::config');

        $meta[self::GROUP] = [
            'arguments' => [
                'data' => [
                    'config' => [
                        'label' => __('GenMagic product photos'),
                        'componentType' => Fieldset::NAME,
                        'collapsible' => true,
                        'opened' => false,
                        'dataScope' => 'data.' . self::GROUP,
                        'sortOrder' => 200,
                    ],
                ],
            ],
            'children' => [
                'genmagic_photos_panel' => [
                    'arguments' => [
                        'data' => [
                            'config' => [
                                'componentType' => Container::NAME,
                                'component' => 'GenMagic_ProductPhotos/js/photos',
                                'template' => 'GenMagic_ProductPhotos/photos',
                                'sortOrder' => 10,
                                'productId' => (int)$this->locator->getProduct()->getId(),
                                'hasKey' => $this->config->hasApiKey(),
                                'keyUrl' => Config::KEY_URL,
                                'pricingUrl' => Config::PRICING_URL,
                                'settingsUrl' => $canConfigure ? $this->getSettingsUrl() : '',
                                'styleOptions' => $this->styles->getOptions(),
                                'maxExtra' => PhotoStyles::MAX_EXTRA_LENGTH,
                                'modelsUrl' => $this->urlBuilder->getUrl('genmagic_photos/photo/models'),
                                'generateUrl' => $this->urlBuilder->getUrl('genmagic_photos/photo/generate'),
                                'addUrl' => $this->urlBuilder->getUrl('genmagic_photos/photo/add'),
                            ],
                        ],
                    ],
                ],
            ],
        ];
        return $meta;
    }

    /**
     * The address of the GenMagic settings page.
     *
     * @return string
     */
    private function getSettingsUrl(): string
    {
        return $this->urlBuilder->getUrl('adminhtml/system_config/edit', ['section' => 'genmagic']);
    }
}
