<?php

declare(strict_types=1);

namespace Aavirbhava\AiShoppingAssistant\Model\Chat;

use Aavirbhava\AiShoppingAssistant\Api\Chat\ChatGenerationServiceInterface;
use Aavirbhava\AiShoppingAssistant\Api\Chat\ToolCallingChatServiceInterface;
use Aavirbhava\AiShoppingAssistant\Api\Chat\ToolCallingDebugCollectorInterface;
use Aavirbhava\AiShoppingAssistant\Api\Config\ConfigurationReaderInterface;
use Aavirbhava\AiShoppingAssistant\Api\Tool\CommerceToolInterface;
use Aavirbhava\AiShoppingAssistant\Api\Tool\CommerceToolRegistryInterface;
use Aavirbhava\AiShoppingAssistant\Model\Dto\ChatMessage;
use Aavirbhava\AiShoppingAssistant\Model\Dto\ToolCall;
use Aavirbhava\AiShoppingAssistant\Model\Revalidation\RevalidatedProduct;
use Aavirbhava\AiShoppingAssistant\Model\Tool\Exception\ToolAuthorizationException;
use Aavirbhava\AiShoppingAssistant\Model\Tool\ToolContext;

/**
 * Runs the tool-call round-trip around ChatGenerationServiceInterface:
 * offer the enabled tools -> if the model requests one or more, execute
 * each, feed the results back as `tool` messages, and call again -> once
 * the model responds with no further tool calls (or the round cap is
 * hit), return its final response.
 *
 * Bounded by guardrails.max_tool_calls — the existing, already-configurable
 * "Circuit-Breaker"-adjacent guardrail field reserved for exactly this
 * purpose since Milestone 1B and never consumed until now (default 4,
 * range 1-10) — not a new constant, per the task's "configurable-or-
 * constant, document which" instruction.
 *
 * An unrecognized tool name, or one whose capability toggle is off, never
 * reaches execute(): the registry's has() check and each tool's
 * authorize() both fail closed, and the model is told so via a sanitized
 * tool-result error message rather than the whole turn failing.
 *
 * $cartId (Task 8) is threaded straight into ToolContext — this class has
 * no opinion on where it came from (a real masked quote id from
 * ChatIdentityResolverInterface, or null when nothing resolved one).
 */
final class ToolCallingChatService implements ToolCallingChatServiceInterface
{
    public function __construct(
        private readonly ChatGenerationServiceInterface $chatGenerationService,
        private readonly CommerceToolRegistryInterface $toolRegistry,
        private readonly ConfigurationReaderInterface $configurationReader
    ) {
    }

    public function converse(
        int $storeId,
        ?int $customerGroupId,
        ?string $cartId,
        array $messages,
        ?array $responseSchema,
        ?ToolCallingDebugCollectorInterface $collector = null
    ): ToolCallingResult {
        $context = new ToolContext($storeId, $customerGroupId, $cartId);
        $toolDefinitions = $this->offeredToolDefinitions($context);
        $maxRounds = $this->configurationReader->readGuardrails($storeId)->maxToolCalls();

        $conversation = $messages;
        $appended = [];
        $verifiedProducts = [];
        $verifiedProductPromotions = [];
        $verifiedCartPromotions = [];

        for ($round = 0; $round < $maxRounds; $round++) {
            $response = $this->chatGenerationService->chat($storeId, $conversation, $toolDefinitions, $responseSchema);
            $collector?->recordRound($round, $response);

            if ($response->toolCalls === []) {
                return new ToolCallingResult($response, $verifiedProducts, $appended, $verifiedProductPromotions, $verifiedCartPromotions);
            }

            $assistantMessage = new ChatMessage('assistant', $response->text, null, $response->toolCalls);
            $conversation[] = $assistantMessage;
            $appended[] = $assistantMessage;

            foreach ($response->toolCalls as $toolCall) {
                $toolMessage = $this->executeToolCall(
                    $toolCall,
                    $context,
                    $verifiedProducts,
                    $verifiedProductPromotions,
                    $verifiedCartPromotions,
                    $collector
                );
                $conversation[] = $toolMessage;
                $appended[] = $toolMessage;
            }
        }

        // Round cap reached with tools still being requested: ask once
        // more without offering any tools, forcing a text answer instead
        // of looping indefinitely.
        $finalResponse = $this->chatGenerationService->chat($storeId, $conversation, [], $responseSchema);
        $collector?->recordRound($maxRounds, $finalResponse);

        return new ToolCallingResult($finalResponse, $verifiedProducts, $appended, $verifiedProductPromotions, $verifiedCartPromotions);
    }

    /**
     * @return list<array{name: string, description: string, parameters: array<string, mixed>}>
     */
    private function offeredToolDefinitions(ToolContext $context): array
    {
        $definitions = [];

        foreach ($this->toolRegistry->all() as $tool) {
            if (!$this->isAuthorized($tool, $context)) {
                continue;
            }

            $definitions[] = [
                'name' => $tool->name(),
                'description' => $tool->description(),
                'parameters' => $tool->inputSchema(),
            ];
        }

        return $definitions;
    }

    private function isAuthorized(CommerceToolInterface $tool, ToolContext $context): bool
    {
        try {
            $tool->authorize($context);

            return true;
        } catch (ToolAuthorizationException) {
            return false;
        }
    }

    /**
     * Appends any RevalidatedProducts/promotion facts the tool call
     * touched onto the accumulators (by reference — each spans every
     * round and every tool call within a round) and returns the `tool`
     * role ChatMessage to add to the conversation.
     *
     * @param list<RevalidatedProduct> $verifiedProducts
     * @param list<\Aavirbhava\AiShoppingAssistant\Api\Promotion\ProductPromotionInterface> $verifiedProductPromotions
     * @param list<\Aavirbhava\AiShoppingAssistant\Api\Promotion\CartPromotionInterface> $verifiedCartPromotions
     */
    private function executeToolCall(
        ToolCall $toolCall,
        ToolContext $context,
        array &$verifiedProducts,
        array &$verifiedProductPromotions,
        array &$verifiedCartPromotions,
        ?ToolCallingDebugCollectorInterface $collector = null
    ): ChatMessage {
        if (!$this->toolRegistry->has($toolCall->name)) {
            return $this->toolResultMessage($toolCall, ['error' => 'unknown_tool']);
        }

        $tool = $this->toolRegistry->get($toolCall->name);

        try {
            $tool->authorize($context);
        } catch (ToolAuthorizationException) {
            return $this->toolResultMessage($toolCall, ['error' => 'tool_not_authorized']);
        }

        try {
            $result = $tool->execute($context, $toolCall->arguments);
        } catch (\Throwable) {
            return $this->toolResultMessage($toolCall, ['error' => 'tool_execution_failed']);
        }

        $collector?->recordToolExecution($toolCall, $result);

        foreach ($result->verifiedProducts as $product) {
            $verifiedProducts[] = $product;
        }

        foreach ($result->verifiedProductPromotions as $promotion) {
            $verifiedProductPromotions[] = $promotion;
        }

        foreach ($result->verifiedCartPromotions as $promotion) {
            $verifiedCartPromotions[] = $promotion;
        }

        return $this->toolResultMessage($toolCall, $result->data);
    }

    /**
     * @param array<string, mixed> $data
     */
    private function toolResultMessage(ToolCall $toolCall, array $data): ChatMessage
    {
        try {
            $content = json_encode($data, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            $content = json_encode(['error' => 'tool_result_encoding_failed']);
        }

        return new ChatMessage('tool', $content !== false ? $content : '{"error":"tool_result_encoding_failed"}', $toolCall->id);
    }
}
