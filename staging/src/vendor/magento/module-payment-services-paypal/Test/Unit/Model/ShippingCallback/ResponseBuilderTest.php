<?php
/**
 * ADOBE CONFIDENTIAL
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
 */

declare(strict_types=1);

namespace Magento\PaymentServicesPaypal\Test\Unit\Model\ShippingCallback;

use Exception;
use Magento\Framework\Exception\LocalizedException;
use Magento\Payment\Model\InfoInterface;
use Magento\PaymentServicesBase\Model\MerchantService;
use Magento\PaymentServicesBase\Model\ScopeHeadersBuilder;
use Magento\PaymentServicesPaypal\Helper\OrderHelper;
use Magento\PaymentServicesPaypal\Model\ShippingCallback\ResponseBuilder;
use Magento\PaymentServicesPaypal\Model\ShippingCallback\ShippingProcessor;
use Magento\Quote\Api\Data\CurrencyInterface;
use Magento\Quote\Model\Quote;
use Magento\Quote\Model\Quote\Address;
use Magento\Store\Api\Data\StoreInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use ReflectionClass;

/**
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 */
class ResponseBuilderTest extends TestCase
{
    /**
     * @var ScopeHeadersBuilder|MockObject
     */
    private $scopeHeaderBuilder;

    /**
     * @var OrderHelper|MockObject
     */
    private $orderHelper;

    /**
     * @var ShippingProcessor|MockObject
     */
    private $shippingProcessor;

    /**
     * @var MerchantService|MockObject
     */
    private $merchantService;

    /**
     * @var LoggerInterface|MockObject
     */
    private $logger;

    /**
     * @var ResponseBuilder
     */
    private ResponseBuilder $responseBuilder;

    protected function setUp(): void
    {
        $this->scopeHeaderBuilder = $this->createMock(ScopeHeadersBuilder::class);
        $this->orderHelper = $this->createMock(OrderHelper::class);
        $this->shippingProcessor = $this->createMock(ShippingProcessor::class);
        $this->merchantService = $this->createMock(MerchantService::class);
        $this->logger = $this->createMock(LoggerInterface::class);

        $this->responseBuilder = new ResponseBuilder(
            $this->scopeHeaderBuilder,
            $this->orderHelper,
            $this->shippingProcessor,
            $this->merchantService,
            $this->logger
        );
    }

    public function testBuildResponseMarksSelectedShippingMethodAndBuildsPayPalResponse(): void
    {
        $shippingMethods = $this->getShippingMethods();
        $quote = $this->createQuote('fedex_FEDEX_GROUND');
        $requestData = [
            'purchase_units' => [
                ['reference_id' => 'default']
            ]
        ];

        $this->expectShippingMethods($quote, $shippingMethods);
        $this->expectShippingMethodCodes();
        $this->expectMerchantId();
        $this->expectOrderDetails($quote);

        $this->assertSame(
            $this->getExpectedResponse(),
            $this->responseBuilder->buildResponse($quote, $requestData)
        );
    }

    public function testBuildResponseLogsAndWrapsException(): void
    {
        $quote = $this->createQuote(null);
        $exception = new Exception('Unable to estimate shipping');

        $this->shippingProcessor->expects($this->once())
            ->method('getShippingMethods')
            ->willThrowException($exception);
        $this->logger->expects($this->once())
            ->method('error')
            ->with($this->identicalTo($exception));

        $this->expectException(LocalizedException::class);
        $this->expectExceptionMessage('Unable to estimate shipping');

        $this->responseBuilder->buildResponse(
            $quote,
            ['purchase_units' => [['reference_id' => 'default']]]
        );
    }

    private function createQuote(?string $selectedShippingMethod): Quote
    {
        $shippingAddress = $this->createAddress();
        $shippingAddress->setShippingMethod($selectedShippingMethod);

        $payment = $this->createMock(InfoInterface::class);
        $payment->expects($this->any())
            ->method('setAdditionalInformation')
            ->with('paypal_order_amount', 42.15)
            ->willReturnSelf();

        $currency = $this->createMock(CurrencyInterface::class);
        $currency->method('getBaseCurrencyCode')
            ->willReturn('USD');

        $quote = $this->getMockBuilder(Quote::class)
            ->disableOriginalConstructor()
            ->onlyMethods([
                'getShippingAddress',
                'collectTotals',
                'getPayment',
                'save',
                'getStore',
                'getCurrency',
                'getReservedOrderId'
            ])
            ->getMock();
        $quote->setData('base_grand_total', 42.15);
        $quote->method('getShippingAddress')
            ->willReturn($shippingAddress);
        $quote->expects($this->any())
            ->method('collectTotals')
            ->willReturnSelf();
        $quote->method('getPayment')
            ->willReturn($payment);
        $quote->expects($this->any())
            ->method('save')
            ->willReturnSelf();
        $quote->method('getStore')
            ->willReturn($this->createMock(StoreInterface::class));
        $quote->method('getCurrency')
            ->willReturn($currency);
        $quote->method('getReservedOrderId')
            ->willReturn('100000001');

        return $quote;
    }

    /**
     * Expect shipping methods
     *
     * @param Quote $quote
     * @param array $shippingMethods
     * @return void
     */
    private function expectShippingMethods(Quote $quote, array $shippingMethods): void
    {
        $this->shippingProcessor->expects($this->once())
            ->method('getShippingMethods')
            ->with($this->identicalTo($quote))
            ->willReturn($shippingMethods);
        $this->shippingProcessor->expects($this->once())
            ->method('setDefaultShippingMethod')
            ->with(
                $this->identicalTo($quote),
                $this->callback(function (array $methods): bool {
                    $this->assertFalse($methods[0]['selected']);
                    $this->assertTrue($methods[1]['selected']);
                    return true;
                })
            )
            ->willReturnCallback(
                static function (Quote $quote, array $methods): array {
                    return $methods;
                }
            );
    }

    /**
     * Expect shipping method codes
     *
     * @return void
     */
    private function expectShippingMethodCodes(): void
    {
        $shippingMethodCodes = [
            'flatrate|flatrate' => 'flatrate_flatrate',
            'fedex|FEDEX_GROUND' => 'fedex_FEDEX_GROUND'
        ];

        $this->shippingProcessor->expects($this->exactly(2))
            ->method('getShippingMethodCode')
            ->willReturnCallback(
                static function (string $shippingOptionId) use ($shippingMethodCodes): string {
                    return $shippingMethodCodes[$shippingOptionId];
                }
            );
    }

    /**
     * Expect Merchant ID
     *
     * @return void
     */
    private function expectMerchantId(): void
    {
        $this->scopeHeaderBuilder->expects($this->once())
            ->method('buildScopeHeaders')
            ->willReturn([
                'x-scope-type' => 'website',
                'x-scope-id' => '2'
            ]);
        $this->merchantService->expects($this->once())
            ->method('getMerchantAndPartnerInformation')
            ->with('website', 2)
            ->willReturn(['merchantIdentifier' => 'merchant-id']);
    }

    /**
     * Expect order details
     *
     * @param Quote $quote
     * @return void
     */
    private function expectOrderDetails(Quote $quote): void
    {
        $this->orderHelper->expects($this->once())
            ->method('formatAmount')
            ->with(42.15)
            ->willReturn('42.15');
        $this->orderHelper->expects($this->once())
            ->method('getAmountBreakdown')
            ->with($this->identicalTo($quote), '100000001')
            ->willReturn(['item_total' => ['value' => '32.15', 'currency_code' => 'USD']]);
        $this->orderHelper->expects($this->once())
            ->method('getLineItems')
            ->with($this->identicalTo($quote), '100000001')
            ->willReturn([['name' => 'Test Product']]);
    }

    /**
     * Get shipping methods
     *
     * @return array
     */
    private function getShippingMethods(): array
    {
        return [
            [
                'id' => 'flatrate|flatrate',
                'label' => 'Flat Rate',
                'type' => 'SHIPPING',
                'selected' => false,
                'amount' => ['value' => 5.00, 'currency_code' => 'USD']
            ],
            [
                'id' => 'fedex|FEDEX_GROUND',
                'label' => 'FedEx - Ground',
                'type' => 'SHIPPING',
                'selected' => false,
                'amount' => ['value' => 10.25, 'currency_code' => 'USD']
            ]
        ];
    }

    /**
     * Get expected response
     *
     * @return array
     */
    private function getExpectedResponse(): array
    {
        return [
            'id' => 'merchant-id',
            'purchase_units' => [
                [
                    'reference_id' => 'default',
                    'amount' => [
                        'currency_code' => 'USD',
                        'value' => '42.15',
                        'breakdown' => ['item_total' => ['value' => '32.15', 'currency_code' => 'USD']]
                    ],
                    'items' => [['name' => 'Test Product']],
                    'shipping_options' => [
                        [
                            'id' => 'flatrate|flatrate',
                            'label' => 'Flat Rate',
                            'type' => 'SHIPPING',
                            'selected' => false,
                            'amount' => ['value' => 5.00, 'currency_code' => 'USD']
                        ],
                        [
                            'id' => 'fedex|FEDEX_GROUND',
                            'label' => 'FedEx - Ground',
                            'type' => 'SHIPPING',
                            'selected' => true,
                            'amount' => ['value' => 10.25, 'currency_code' => 'USD']
                        ]
                    ]
                ]
            ]
        ];
    }

    private function createAddress(): Address
    {
        $reflection = new ReflectionClass(Address::class);

        return $reflection->newInstanceWithoutConstructor();
    }
}
