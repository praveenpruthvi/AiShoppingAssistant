<?php

declare(strict_types=1);

namespace Aavirbhava\AiShoppingAssistant\Model\Dto;

use InvalidArgumentException;

final readonly class ChatResponse
{
    /**
     * @param list<ToolCall> $toolCalls
     */
    public function __construct(
        public string $text,
        public array $toolCalls,
        public TokenUsage $usage,
        public string $provider,
        public string $model,
        public int $latencyMilliseconds
    ) {
        foreach ($toolCalls as $toolCall) {
            if (!$toolCall instanceof ToolCall) {
                throw new InvalidArgumentException('Every tool call must be a ToolCall.');
            }
        }

        if ($text === '' && $toolCalls === []) {
            throw new InvalidArgumentException('A chat response requires text or at least one tool call.');
        }

        if ($provider === '' || $model === '') {
            throw new InvalidArgumentException('Provider and model must not be empty.');
        }

        if ($latencyMilliseconds < 0) {
            throw new InvalidArgumentException('Latency must not be negative.');
        }
    }
}
