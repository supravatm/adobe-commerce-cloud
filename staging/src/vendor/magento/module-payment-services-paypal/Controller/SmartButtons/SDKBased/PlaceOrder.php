<?php
/************************************************************************
 *
 * Copyright 2026 Adobe
 * All Rights Reserved.
 *
 * NOTICE: All information contained herein is, and remains
 * the property of Adobe and its suppliers, if any. The intellectual
 * and technical concepts contained herein are proprietary to Adobe
 * and its suppliers and are protected by all applicable intellectual
 * property laws, including trade secret and copyright laws.
 * Dissemination of this information or reproduction of this material
 * is strictly forbidden unless prior written permission is obtained
 * from Adobe.
 * ************************************************************************
 */
declare(strict_types=1);

namespace Magento\PaymentServicesPaypal\Controller\SmartButtons\SDKBased;

use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\Controller\Result\Json;
use Magento\Framework\Controller\ResultFactory;
use Magento\Framework\Controller\ResultInterface;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Framework\Exception\SessionException;
use Magento\Framework\Message\ManagerInterface as MessageManagerInterface;
use Magento\Framework\UrlInterface;
use Magento\PaymentServicesPaypal\Model\CancellationService;
use Magento\PaymentServicesPaypal\Model\SmartButtons\Checkout;

class PlaceOrder implements HttpPostActionInterface
{
    /**
     * @param RequestInterface $request
     * @param ResultFactory $resultFactory
     * @param Checkout $checkout
     * @param UrlInterface $url
     * @param CancellationService $cancellationService
     * @param MessageManagerInterface $messageManager
     */
    public function __construct(
        readonly RequestInterface $request,
        readonly ResultFactory $resultFactory,
        readonly Checkout $checkout,
        readonly UrlInterface $url,
        readonly CancellationService $cancellationService,
        readonly MessageManagerInterface $messageManager,
    ) {
    }

    /**
     * @inheritdoc
     */
    public function execute(): ResultInterface
    {
        $result = $this->resultFactory->create(ResultFactory::TYPE_JSON);
        try {
            $this->refreshCheckout();
            $this->checkout->validateQuote();
            $this->tryPlaceOrderOrCancel();
            $redirectUrl = $this->url->getUrl($this->checkout->getSuccessPageUri());
            $result->setHttpResponseCode(200)->setData(['redirectUrl' => $redirectUrl]);
        } catch (\Exception $e) {
            $this->processException($e, $result);
        }
        return $result;
    }

    /**
     * Attempts to place the order or cancels it if the order placement fails.
     *
     * @return void
     * @throws LocalizedException
     * @throws NoSuchEntityException
     * @throws SessionException
     */
    private function tryPlaceOrderOrCancel(): void
    {
        try {
            $this->checkout->placeOrder();
        } catch (\Exception $e) {
            $this->cancellationService->execute((int)$this->checkout->getQuote()->getId());
            throw $e;
        }
    }

    /**
     * Refresh PayPal checkout session:
     *  - Update location with optional "location" override from request body
     *  - Unset quote id override for locations other than PDP, where no shadow quote is created
     *
     * @return void
     */
    private function refreshCheckout(): void
    {
        $locationOverride = $this->request->getPost('location');
        if ($locationOverride) {
            $this->checkout->setLocation($locationOverride);
        }
        if ($this->checkout->getLocation() !== $this->checkout::LOCATION_PRODUCT_PAGE) {
            $this->checkout->unsetQuote();
        }
    }

    /**
     * Prepares JSON response for unexpected errors and adds an error customer message.
     *
     * @param \Exception $exception
     * @param Json $result
     * @return void
     */
    private function processException(\Exception $exception, Json $result): void
    {
        $message = ($exception instanceof LocalizedException)
            ? $exception->getMessage()
            : __('We can\'t process the order right now. Please try again later.')->getText();
        $this->messageManager->addExceptionMessage($exception, $message);
        $result->setHttpResponseCode(500)->setData(['error' => $message]);
    }
}
