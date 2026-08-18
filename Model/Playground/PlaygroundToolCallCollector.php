<?php

declare(strict_types=1);

namespace Aavirbhava\AiShoppingAssistant\Model\Playground;

use Aavirbhava\AiShoppingAssistant\Api\Chat\ToolCallingDebugCollectorInterface;
use Aavirbhava\AiShoppingAssistant\Model\Dto\ChatResponse;
use Aavirbhava\AiShoppingAssistant\Model\Dto\ToolCall;
use Aavirbhava\AiShoppingAssistant\Model\Tool\ToolResult;

/**
 * Records every round's raw ChatResponse (token usage/latency/provider/
 * model included) and every tool's raw ToolResult, for the admin
 * Playground's "tool calls" and "tokens/latency" panels. Not a
 * DI-registered service — the Playground constructs one fresh per query.
 */
final class PlaygroundToolCallCollector implements ToolCallingDebugCollectorInterface
{
    /**
     * @var list<array{round: int, response: ChatResponse}>
     */
    public array $rounds = [];

    /**
     * @var list<array{call: ToolCall, result: ToolResult}>
     */
    public array $toolExecutions = [];

    public function recordRound(int $round, ChatResponse $response): void
    {
        $this->rounds[] = ['round' => $round, 'response' => $response];
    }

    public function recordToolExecution(ToolCall $toolCall, ToolResult $result): void
    {
        $this->toolExecutions[] = ['call' => $toolCall, 'result' => $result];
    }
}
