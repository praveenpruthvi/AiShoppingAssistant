<?php

declare(strict_types=1);

namespace Aavirbhava\AiShoppingAssistant\Api\Cart;

use Aavirbhava\AiShoppingAssistant\Model\Cart\Exception\CartNotAvailableException;
use Magento\Quote\Api\Data\CartInterface;

/**
 * Resolves the cart (quote) for a store-scoped, cart-id-identified request
 * context — the only place cart tools are allowed to obtain a real
 * Magento\Quote\Api\Data\CartInterface.
 *
 * $cartId is a masked quote id (Magento's standard opaque, API-facing cart
 * identifier — the same id shape used by the guest cart REST endpoints).
 * Nothing in this module currently populates a real one: there is no
 * Controller/session layer yet that resolves an actual customer's or
 * guest's cart (the same gap already flagged for customerGroupId since
 * Task 3). $cartId therefore always arrives null in practice today, and
 * every cart tool must treat that as an honest "no cart available" outcome
 * — not an error to invent a workaround for.
 */
interface CartResolverInterface
{
    /**
     * @throws CartNotAvailableException when $cartId is null/blank, does
     *     not resolve to a real quote, or the resolved quote belongs to a
     *     different store than $storeId
     */
    public function resolve(int $storeId, ?string $cartId): CartInterface;
}
