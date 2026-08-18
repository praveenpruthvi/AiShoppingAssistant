<?php

declare(strict_types=1);

namespace Aavirbhava\AiShoppingAssistant\Model\Chat;

use Aavirbhava\AiShoppingAssistant\Api\Chat\ChatIdentityResolverInterface;
use Aavirbhava\AiShoppingAssistant\Api\Config\ConfigurationReaderInterface;
use Aavirbhava\AiShoppingAssistant\Model\Session\ChatSession;
use Magento\Checkout\Model\Session as CheckoutSession;
use Magento\Customer\Model\Session as CustomerSession;
use Magento\Quote\Model\QuoteIdMaskFactory;

/**
 * Mirrors Magento's own conventions rather than inventing an identity
 * scheme: customer/guest identity comes from Magento\Customer\Model\
 * Session (the same session every other storefront customer-aware
 * feature already trusts); the cart is the browser's real active quote
 * via Magento\Checkout\Model\Session::getQuote() (guest-or-customer,
 * already correctly scoped by Magento's own session), converted to the
 * masked-quote-id shape CartResolverInterface (Task 7) already expects
 * via QuoteIdMaskFactory — the exact mechanism Magento's own guest-cart
 * REST endpoints use to hand a cart id to a stateless caller.
 */
final class ChatIdentityResolver implements ChatIdentityResolverInterface
{
    public function __construct(
        private readonly ChatSession $chatSession,
        private readonly CustomerSession $customerSession,
        private readonly CheckoutSession $checkoutSession,
        private readonly QuoteIdMaskFactory $quoteIdMaskFactory,
        private readonly ConfigurationReaderInterface $configurationReader
    ) {
    }

    public function resolve(int $storeId): ChatRequestIdentity
    {
        $conversationId = $this->chatSession->getConversationId();

        if ($conversationId === null) {
            $conversationId = bin2hex(random_bytes(16));
            $this->chatSession->setConversationId($conversationId);
        }

        $customerGroupId = (int) $this->customerSession->getCustomerGroupId();

        $cartId = $this->configurationReader->readGuardrails($storeId)->areCartMutationsEnabled()
            ? $this->resolveCartId()
            : null;

        return new ChatRequestIdentity($conversationId, $customerGroupId, $cartId);
    }

    private function resolveCartId(): ?string
    {
        $quoteId = (int) $this->checkoutSession->getQuote()->getId();

        if ($quoteId < 1) {
            return null;
        }

        $quoteIdMask = $this->quoteIdMaskFactory->create();
        $quoteIdMask->load($quoteId, 'quote_id');

        if (!$quoteIdMask->getId()) {
            $quoteIdMask->setQuoteId($quoteId);
            $quoteIdMask->save();
        }

        $maskedId = $quoteIdMask->getMaskedId();

        return is_string($maskedId) && $maskedId !== '' ? $maskedId : null;
    }
}
