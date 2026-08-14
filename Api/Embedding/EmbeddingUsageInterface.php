<?php

declare(strict_types=1);

namespace Aavirbhava\AiShoppingAssistant\Api\Embedding;

/**
 * Immutable token usage for a single embedding batch.
 *
 * Values are never negative. A provider that does not report usage contributes
 * zero.
 */
interface EmbeddingUsageInterface
{
    public function inputTokens(): int;

    public function totalTokens(): int;
}
