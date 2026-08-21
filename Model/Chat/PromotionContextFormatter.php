<?php

declare(strict_types=1);

namespace Aavirbhava\AiShoppingAssistant\Model\Chat;

use Aavirbhava\AiShoppingAssistant\Api\Promotion\ProductPromotionInterface;
use Aavirbhava\AiShoppingAssistant\Model\Dto\ChatMessage;

/**
 * Formats real, live-read Catalog Price Rule discounts (Task 34) into a
 * system ChatMessage the LLM can ground a response on — the promotion
 * equivalent of ProductContextFormatter, added as a separate message
 * rather than a new field on it: ProductContextFormatter's own instructions
 * explicitly say "this list does not include price or stock information"
 * (that data isn't resolved until live revalidation runs, after
 * ProductContextFormatter's candidates are chosen), so a promotion — which
 * is itself a live price fact — belongs in its own message built from
 * already-live-revalidated data, not folded into that one.
 *
 * Proactive: built from whichever of this turn's candidates genuinely
 * have an active discount (ChatEntryPipeline resolves this before ever
 * calling the model), so a real discount gets mentioned even when the
 * shopper never explicitly asks — get_active_promotions (the tool)
 * exists for the cases this proactive path doesn't cover, not as the
 * only path.
 */
final class PromotionContextFormatter
{
    private const INSTRUCTIONS = <<<'TEXT'
The following products from this turn's results have a real, currently-
active discount. If you recommend or discuss one of these products,
mention its real discount naturally (e.g. "this is 20% off right now").
Never state a discount percentage, amount, or coupon code for any product
not listed here, and never invent a percentage/amount/code beyond exactly
what is stated below — if a customer asks about a discount for a product
not listed here, say you don't see an active discount for it, or call the
get_active_promotions tool to check.
TEXT;

    /**
     * @param array<string, ProductPromotionInterface> $promotions sku => promotion
     */
    public function format(array $promotions): ?ChatMessage
    {
        if ($promotions === []) {
            return null;
        }

        $lines = array_map(
            fn (ProductPromotionInterface $promotion): string => $this->formatPromotion($promotion),
            array_values($promotions)
        );

        return new ChatMessage('system', self::INSTRUCTIONS . "\n\n" . implode("\n", $lines));
    }

    private function formatPromotion(ProductPromotionInterface $promotion): string
    {
        return sprintf(
            '- SKU: %s | Regular price: %.2f | Discounted price: %.2f | %d%% off',
            $promotion->sku(),
            $promotion->regularPrice(),
            $promotion->discountedPrice(),
            (int) $promotion->percentOff()
        );
    }
}
