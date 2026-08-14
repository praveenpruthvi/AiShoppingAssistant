<?php

declare(strict_types=1);

namespace Aavirbhava\AiShoppingAssistant\Api\Embedding;

/**
 * An immutable, validated embedding batch result.
 *
 * Every returned vector is correlated to the requested inputs through the
 * inputIdentifiers list: the i-th identifier corresponds to the i-th vector and
 * to the i-th request input. Implementations must never carry configuration,
 * secrets, or raw provider response bodies.
 */
interface EmbeddingResultInterface
{
    /**
     * @return list<EmbeddingVectorInterface>
     */
    public function vectors(): array;

    /**
     * Deterministic identifiers, one per vector, in the same order.
     *
     * @return list<string>
     */
    public function inputIdentifiers(): array;

    public function model(): string;

    public function usage(): EmbeddingUsageInterface;
}
