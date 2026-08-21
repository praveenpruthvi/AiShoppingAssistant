<?php

declare(strict_types=1);

namespace Aavirbhava\AiShoppingAssistant\Model\Chat;

use Aavirbhava\AiShoppingAssistant\Api\Chat\ChatGenerationServiceInterface;
use Aavirbhava\AiShoppingAssistant\Model\CostCap\CostUsageRecorder;
use Aavirbhava\AiShoppingAssistant\Model\Dto\ChatResponse;

/**
 * Wraps FallbackChatGenerationService with LLM cost-cap usage tracking —
 * a pure decorator, the same shape FallbackChatGenerationService itself
 * uses to wrap the undecorated ChatGenerationService: it depends on the
 * concrete FallbackChatGenerationService class (not this interface) to
 * avoid a DI cycle, and every existing caller of
 * ChatGenerationServiceInterface (ChatEntryPipeline via ToolCallingChatService,
 * PlaygroundQueryRunner) gets usage recording transparently with no code
 * change on their side, per etc/di.xml swapping the interface preference
 * to this class.
 *
 * Recording happens only after chat() returns successfully — a failed
 * call (an exception propagating from FallbackChatGenerationService) is
 * never billed, since nothing was actually spent. CostUsageRecorder
 * itself is fail-open (a tracking failure is logged and swallowed), so a
 * broken tracker can never turn a successful chat response into a failed
 * one here.
 */
final class CostTrackingChatGenerationService implements ChatGenerationServiceInterface
{
    public function __construct(
        private readonly FallbackChatGenerationService $decorated,
        private readonly CostUsageRecorder $costUsageRecorder
    ) {
    }

    public function chat(int $storeId, array $messages, array $tools = [], ?array $responseSchema = null): ChatResponse
    {
        $response = $this->decorated->chat($storeId, $messages, $tools, $responseSchema);

        $this->costUsageRecorder->record($storeId, $response->provider, $response->usage);

        return $response;
    }
}
