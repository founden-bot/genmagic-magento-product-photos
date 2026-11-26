<?php
/**
 * Copyright GenMagic. All rights reserved.
 * See LICENSE for license details.
 */
declare(strict_types=1);

namespace GenMagic\ProductPhotos\Controller\Adminhtml\Photo;

use GenMagic\ProductPhotos\Model\Api\ApiException;
use GenMagic\ProductPhotos\Model\Config;
use GenMagic\ProductPhotos\Model\PhotoService;
use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\Controller\Result\Json;
use Magento\Framework\Controller\Result\JsonFactory;
use Magento\Framework\Exception\LocalizedException;
use Psr\Log\LoggerInterface;

/**
 * Makes one photo of a product from its base image.
 *
 * POST only: Magento checks the admin form key before this runs.
 */
class Generate extends Action implements HttpPostActionInterface
{
    public const ADMIN_RESOURCE = 'GenMagic_ProductPhotos::generate';

    /**
     * @var JsonFactory
     */
    private $resultJsonFactory;

    /**
     * @var PhotoService
     */
    private $photoService;

    /**
     * @var LoggerInterface
     */
    private $logger;

    /**
     * @param Context $context
     * @param JsonFactory $resultJsonFactory
     * @param PhotoService $photoService
     * @param LoggerInterface $logger
     */
    public function __construct(
        Context $context,
        JsonFactory $resultJsonFactory,
        PhotoService $photoService,
        LoggerInterface $logger
    ) {
        parent::__construct($context);
        $this->resultJsonFactory = $resultJsonFactory;
        $this->photoService = $photoService;
        $this->logger = $logger;
    }

    /**
     * @inheritdoc
     */
    public function execute(): Json
    {
        $result = $this->resultJsonFactory->create();
        try {
            $request = $this->getRequest();
            $data = $this->photoService->generate(
                (int)$request->getParam('product_id'),
                (string)$request->getParam('style'),
                (string)$request->getParam('extra'),
                (string)$request->getParam('model')
            );
            return $result->setData(['success' => true] + $data);
        } catch (ApiException $e) {
            $payload = ['success' => false, 'message' => $this->failureMessage($e)];
            if ($e->isInsufficientCredit()) {
                $payload['pricing_url'] = Config::PRICING_URL;
            }
            return $result->setData($payload);
        } catch (LocalizedException $e) {
            return $result->setData(['success' => false, 'message' => $e->getMessage()]);
        } catch (\Throwable $e) {
            $this->logger->error('GenMagic product photos: ' . $e->getMessage());
            return $result->setData(
                ['success' => false, 'message' => (string)__('Something went wrong. Try again in a moment.')]
            );
        }
    }

    /**
     * GenMagic's own text, with a plain sentence about the balance when it is too low.
     *
     * @param ApiException $e
     * @return string
     */
    private function failureMessage(ApiException $e): string
    {
        if (!$e->isInsufficientCredit()) {
            return $e->getMessage();
        }
        return (string)__(
            'Your GenMagic balance is too low for this photo. Add credit at %1 and try again. (%2)',
            Config::PRICING_URL,
            $e->getMessage()
        );
    }
}
