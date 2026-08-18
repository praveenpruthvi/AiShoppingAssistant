<?php

declare(strict_types=1);

namespace Aavirbhava\AiShoppingAssistant\Api\Chat;

use Aavirbhava\AiShoppingAssistant\Model\Dto\ChatMessage;
use Aavirbhava\AiShoppingAssistant\Model\Dto\ChatResponse;

/**
 * Store-scoped chat generation boundary.
 *
 * Implementations must activate and scope to a store view, read store-scoped
 * LLM configuration, resolve exactly the primary provider (never a
 * fallback), and never write anything. Requests to unavailable,
 * misconfigured, or unauthorized providers fail closed with sanitized
 * Provider* exceptions so a future fallback orchestrator can wrap a call to
 * this service, inspect the propagated exception with
 * FallbackEligibilityPolicyInterface, and retry against the fallback
 * provider without any change to this service.
 */
interface ChatGenerationServiceInterface
{
    /**
     * @param non-empty-list<ChatMessage> $messages
     * @param list<array<string, mixed>> $tools
     * @param array<string, mixed>|null $responseSchema
     */
    public function chat(int $storeId, array $messages, array $tools = [], ?array $responseSchema = null): ChatResponse;
}
