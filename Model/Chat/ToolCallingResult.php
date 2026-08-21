<?php

declare(strict_types=1);

namespace Aavirbhava\AiShoppingAssistant\Model\Chat;

use Aavirbhava\AiShoppingAssistant\Api\Promotion\CartPromotionInterface;
use Aavirbhava\AiShoppingAssistant\Api\Promotion\ProductPromotionInterface;
use Aavirbhava\AiShoppingAssistant\Model\Dto\ChatMessage;
use Aavirbhava\AiShoppingAssistant\Model\Dto\ChatResponse;
use Aavirbhava\AiShoppingAssistant\Model\Revalidation\RevalidatedProduct;

/**
 * ToolCallingChatServiceInterface::converse()'s outcome: the final
 * (non-tool-call) ChatResponse, every RevalidatedProduct any tool call
 * touched along the way, and (Task 8) every assistant-tool-call/tool-result
 * message the round-trip appended beyond the messages it was given.
 *
 * ChatEntryPipeline merges verifiedProducts into the retrieval-derived
 * verified set before calling OutputValidator — a SKU a tool looked up
 * mid-conversation must be just as eligible to appear in the final answer
 * as one the initial retrieval surfaced. It persists toolRoundTripMessages
 * (alongside the user's message and the final validated response text) so
 * a later turn's model can see, e.g., a confirmation_token a mutating
 * cart tool returned this turn — the mechanism that makes the Task 7
 * cart-confirmation gate reachable across two real, separate conversation
 * turns instead of only by direct construction.
 *
 * verifiedProductPromotions/verifiedCartPromotions (Task 34) are the
 * promotion-domain equivalent of verifiedProducts, accumulated from
 * get_active_promotions calls the same way, so ChatEntryPipeline can
 * merge them into what OutputValidator's fabricated_discount check
 * treats as real.
 */
final readonly class ToolCallingResult
{
    /**
     * @param list<RevalidatedProduct> $verifiedProducts
     * @param list<ChatMessage> $toolRoundTripMessages
     * @param list<ProductPromotionInterface> $verifiedProductPromotions
     * @param list<CartPromotionInterface> $verifiedCartPromotions
     */
    public function __construct(
        public ChatResponse $response,
        public array $verifiedProducts,
        public array $toolRoundTripMessages = [],
        public array $verifiedProductPromotions = [],
        public array $verifiedCartPromotions = []
    ) {
    }
}
