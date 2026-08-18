<?php

declare(strict_types=1);

namespace Aavirbhava\AiShoppingAssistant\Api\Chat;

use Aavirbhava\AiShoppingAssistant\Model\Chat\ChatRequestIdentity;

/**
 * Resolves the current storefront request's chat identity from Magento's
 * own session/cart mechanisms — never from anything the client supplies
 * directly, which is what makes cross-customer leakage structurally
 * impossible rather than merely unlikely.
 */
interface ChatIdentityResolverInterface
{
    /**
     * conversationId is created once per browser session and reused for
     * every message in it (Model\Session\ChatSession). customerGroupId
     * comes from Magento\Customer\Model\Session — the real group for a
     * logged-in customer, Magento's own NOT_LOGGED_IN group for a guest,
     * exactly as Magento's session already resolves it. cartId is a real
     * masked quote id (Magento's own guest-cart identifier shape, the
     * same shape CartResolverInterface already expects) resolved from
     * Magento\Checkout\Model\Session's active quote — but only when
     * guardrails.cart_mutations_enabled is on for $storeId, so a store
     * that never offers cart tools never pays the cost of creating one.
     */
    public function resolve(int $storeId): ChatRequestIdentity;
}
