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

namespace Magento\PaymentServicesPaypal\Plugin\Checkout\CustomerData;

use Magento\Checkout\CustomerData\Cart as CustomerCartData;
use Magento\Checkout\Model\Session as CheckoutSession;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Framework\Math\Random;
use Magento\Quote\Model\QuoteIdMaskFactory;

/**
 * Plugin that adds "masked_quote_id" and "is_virtual" to the "cart" section of customer data.
 */
class MaskedQuoteIdAndIsVirtualResolver
{
    /**
     * @param CheckoutSession $checkoutSession
     * @param QuoteIdMaskFactory $quoteIdMaskFactory
     * @param Random $random
     */
    public function __construct(
        private readonly CheckoutSession $checkoutSession,
        private readonly QuoteIdMaskFactory $quoteIdMaskFactory,
        private readonly Random $random,
    ) {
    }

    /**
     * Loads or creates a masked quote id for the active quote and adds it to the cart section data.
     * Also adds an is_virtual flag to indicate whether the cart contains only virtual products.
     *
     * @param CustomerCartData $subject
     * @param array $result
     * @return array
     * @throws NoSuchEntityException
     * @throws LocalizedException
     */
    public function afterGetSectionData(CustomerCartData $subject, array $result): array
    {
        $quote = $this->checkoutSession->getQuote();
        if (!$quote || $quote->getId() == null) {
            $result['masked_quote_id'] = null;
            return $result;
        }

        $quoteId =  (int) $quote->getId();
        $mask = $this->quoteIdMaskFactory->create()->load($quoteId, 'quote_id');

        if (!$mask->getMaskedId()) {
            $mask->setQuoteId($quoteId);
            $mask->setMaskedId($this->random->getUniqueHash());
            $mask->save();
        }

        $result['is_virtual'] = $quote->isVirtual();

        $result['masked_quote_id'] = $mask->getMaskedId();
        return $result;
    }
}
