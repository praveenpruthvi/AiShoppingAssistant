<?php

declare(strict_types=1);

namespace Aavirbhava\AiShoppingAssistant\Model\Dto;

use InvalidArgumentException;

final readonly class TokenUsage
{
    public function __construct(
        public int $inputTokens,
        public int $outputTokens,
        public int $cachedInputTokens = 0
    ) {
        if ($inputTokens < 0 || $outputTokens < 0 || $cachedInputTokens < 0) {
            throw new InvalidArgumentException('Token counts must not be negative.');
        }

        if ($cachedInputTokens > $inputTokens) {
            throw new InvalidArgumentException('Cached input tokens cannot exceed total input tokens.');
        }
    }

    public function total(): int
    {
        return $this->inputTokens + $this->outputTokens;
    }
}
