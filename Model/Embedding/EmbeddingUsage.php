<?php

declare(strict_types=1);

namespace Aavirbhava\AiShoppingAssistant\Model\Embedding;

use Aavirbhava\AiShoppingAssistant\Api\Embedding\EmbeddingUsageInterface;
use InvalidArgumentException;

/**
 * Immutable token usage for a single embedding batch.
 *
 * A provider that does not report usage contributes zero.
 */
final readonly class EmbeddingUsage implements EmbeddingUsageInterface
{
    public function __construct(
        private int $inputTokens,
        private int $totalTokens
    ) {
        if ($inputTokens < 0 || $totalTokens < 0) {
            throw new InvalidArgumentException('Embedding token counts must not be negative.');
        }
    }

    public function inputTokens(): int
    {
        return $this->inputTokens;
    }

    public function totalTokens(): int
    {
        return $this->totalTokens;
    }
}