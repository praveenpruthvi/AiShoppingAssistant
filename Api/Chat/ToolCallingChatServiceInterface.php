<?php

declare(strict_types=1);

namespace Aavirbhava\AiShoppingAssistant\Api\Chat;

use Aavirbhava\AiShoppingAssistant\Model\Chat\ToolCallingResult;
use Aavirbhava\AiShoppingAssistant\Model\Dto\ChatMessage;

/**
 * Sits above ChatGenerationServiceInterface and handles the tool-call
 * round-trip: offer the store's enabled commerce tools, execute whatever
 * the model requests, feed results back, repeat up to a bounded number of
 * rounds, and return the model's final text response.
 *
 * ChatGenerationServiceInterface itself (and the retry/circuit-breaker/
 * fallback logic behind it) is unaware tools exist — each round is just
 * one more call to it. This is the only new caller of that interface for
 * a conversation turn; ChatEntryPipeline calls this service instead of
 * ChatGenerationServiceInterface directly.
 */
interface ToolCallingChatServiceInterface
{
    /**
     * $collector (Task 9) is an optional debug-capture seam — pass one to
     * observe every round's raw ChatResponse and every tool's raw
     * ToolResult; every existing caller passes nothing and sees no
     * change in behavior.
     *
     * @param list<ChatMessage> $messages
     * @param array<string, mixed>|null $responseSchema
     */
    public function converse(
        int $storeId,
        ?int $customerGroupId,
        ?string $cartId,
        array $messages,
        ?array $responseSchema,
        ?ToolCallingDebugCollectorInterface $collector = null
    ): ToolCallingResult;
}
