<?php

declare(strict_types=1);

namespace Aavirbhava\AiShoppingAssistant\Model\Chat;

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
 */
final readonly class ToolCallingResult
{
    /**
     * @param list<RevalidatedProduct> $verifiedProducts
     * @param list<ChatMessage> $toolRoundTripMessages
     */
    public function __construct(
        public ChatResponse $response,
        public array $verifiedProducts,
        public array $toolRoundTripMessages = []
    ) {
    }
}
