<?php

declare(strict_types=1);

namespace Aavirbhava\AiShoppingAssistant\Api\Chat;

use Aavirbhava\AiShoppingAssistant\Model\Dto\ChatResponse;
use Aavirbhava\AiShoppingAssistant\Model\Dto\ToolCall;
use Aavirbhava\AiShoppingAssistant\Model\Tool\ToolResult;

/**
 * Optional debug-capture seam for ToolCallingChatServiceInterface::converse():
 * when a caller passes one in, it is told every raw ChatResponse the
 * tool-call round-trip produced (one per round — carries that round's own
 * token usage/latency/provider/model) and every tool's raw ToolResult
 * (richer than the JSON-encoded ChatMessage the model actually sees).
 * Production callers (ChatEntryPipeline) never pass one — behavior is
 * completely unchanged when $collector is null.
 *
 * Built for the admin Playground (Task 9) so it can show real per-round
 * token/latency figures and real per-tool-call structured results, not
 * just the final response's own usage.
 */
interface ToolCallingDebugCollectorInterface
{
    public function recordRound(int $round, ChatResponse $response): void;

    public function recordToolExecution(ToolCall $toolCall, ToolResult $result): void;
}
