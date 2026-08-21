<?php

declare(strict_types=1);

namespace Aavirbhava\AiShoppingAssistant\Model\Tool;

use Aavirbhava\AiShoppingAssistant\Api\Promotion\CartPromotionInterface;
use Aavirbhava\AiShoppingAssistant\Api\Promotion\ProductPromotionInterface;
use Aavirbhava\AiShoppingAssistant\Model\Revalidation\RevalidatedProduct;

/**
 * One tool's execution outcome.
 *
 * `data` is JSON-encoded and fed back to the model as the tool-result
 * message content — it must never contain anything beyond what live
 * Magento data actually says. `verifiedProducts` carries every
 * RevalidatedProduct this call touched so the caller (ToolCallingChatService)
 * can fold them into the Output Validator's already-verified SKU set —
 * a SKU a tool looked up mid-conversation is just as trustworthy as one
 * that came from the original retrieval candidates, and the final answer
 * must be allowed to reference it.
 *
 * `verifiedProductPromotions`/`verifiedCartPromotions` are the promotion-
 * domain equivalent (Task 34), threaded through
 * ToolCallingChatService/ToolCallingResult the exact same way so
 * OutputValidatorInterface::validate()'s new fabricated_discount check
 * can tell a real, live-read discount claim from an invented one. Empty
 * for every tool other than get_active_promotions.
 */
final readonly class ToolResult
{
    /**
     * @param array<string, mixed> $data
     * @param list<RevalidatedProduct> $verifiedProducts
     * @param list<ProductPromotionInterface> $verifiedProductPromotions
     * @param list<CartPromotionInterface> $verifiedCartPromotions
     */
    public function __construct(
        public array $data,
        public array $verifiedProducts = [],
        public array $verifiedProductPromotions = [],
        public array $verifiedCartPromotions = []
    ) {
    }
}
