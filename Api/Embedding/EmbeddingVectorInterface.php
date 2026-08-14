<?php

declare(strict_types=1);

namespace Aavirbhava\AiShoppingAssistant\Api\Embedding;

/**
 * An immutable numeric vector produced by an embedding provider.
 *
 * Implementations must reject empty vectors, non-numeric members, and
 * non-finite values (NaN, INF) so that invalid provider output can never enter
 * the retrieval pipeline.
 */
interface EmbeddingVectorInterface
{
    /**
     * @return list<float>
     */
    public function values(): array;

    public function dimension(): int;
}
