<?php

declare(strict_types=1);

namespace Aavirbhava\AiShoppingAssistant\Api\Chat;

use Aavirbhava\AiShoppingAssistant\Api\Promotion\CartPromotionInterface;
use Aavirbhava\AiShoppingAssistant\Api\Promotion\ProductPromotionInterface;
use Aavirbhava\AiShoppingAssistant\Model\Chat\Response\OutputValidationResult;
use Aavirbhava\AiShoppingAssistant\Model\Dto\ChatResponse;
use Aavirbhava\AiShoppingAssistant\Model\Revalidation\RevalidatedProduct;

/**
 * Gates every provider response before it can reach a customer: the
 * response must be well-formed structured output, must not leak a URL in
 * free text, and every SKU it mentions (in products or actions) must be
 * present in the already-live-revalidated set. Any single fabrication
 * invalidates the whole response — a response that hallucinated once isn't
 * trusted for the rest of its content either.
 *
 * $verifiedProductPromotions/$verifiedCartPromotions (Task 34) are real,
 * live-read discount facts — either resolved proactively for this turn's
 * candidates or returned by a get_active_promotions tool call — that a
 * response's free text is allowed to state a percentage/coupon-code claim
 * against (fabricated_discount). Both default to empty so every existing
 * caller/implementation keeps compiling unchanged.
 */
interface OutputValidatorInterface
{
    /**
     * @param list<RevalidatedProduct> $verifiedProducts
     * @param list<ProductPromotionInterface> $verifiedProductPromotions
     * @param list<CartPromotionInterface> $verifiedCartPromotions
     */
    public function validate(
        ChatResponse $response,
        array $verifiedProducts,
        array $verifiedProductPromotions = [],
        array $verifiedCartPromotions = []
    ): OutputValidationResult;
}
