<?php

declare(strict_types=1);

namespace Aavirbhava\AiShoppingAssistant\Model\Cart;

use Aavirbhava\AiShoppingAssistant\Api\Cart\CartResolverInterface;
use Aavirbhava\AiShoppingAssistant\Model\Cart\Exception\CartNotAvailableException;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Phrase;
use Magento\Quote\Api\CartRepositoryInterface;
use Magento\Quote\Api\Data\CartInterface;
use Magento\Quote\Model\MaskedQuoteIdToQuoteIdInterface;

/**
 * Converts a masked quote id to a real, store-scope-checked
 * Magento\Quote\Api\Data\CartInterface via Magento's own public cart APIs —
 * no low-level Quote model manipulation, no second/custom cart-lookup path.
 *
 * Every failure mode (no id supplied, malformed id, id doesn't resolve, or
 * resolves to a cart belonging to a different store) is normalized into the
 * same CartNotAvailableException, since none of them are attacker-
 * distinguishable information a cart tool should leak to the model/customer
 * — "no cart available" is the only fact that matters downstream.
 */
final class CartResolver implements CartResolverInterface
{
    public function __construct(
        private readonly MaskedQuoteIdToQuoteIdInterface $maskedQuoteIdToQuoteId,
        private readonly CartRepositoryInterface $cartRepository
    ) {
    }

    public function resolve(int $storeId, ?string $cartId): CartInterface
    {
        if ($cartId === null || trim($cartId) === '') {
            throw new CartNotAvailableException(
                new Phrase('No cart is currently available for this conversation.')
            );
        }

        try {
            $quoteId = $this->maskedQuoteIdToQuoteId->execute($cartId);
            $cart = $this->cartRepository->get($quoteId);
        } catch (LocalizedException $exception) {
            throw new CartNotAvailableException(
                new Phrase('No cart is currently available for this conversation.'),
                $exception
            );
        }

        if ((int) $cart->getStoreId() !== $storeId) {
            throw new CartNotAvailableException(
                new Phrase('No cart is currently available for this conversation.')
            );
        }

        return $cart;
    }
}
