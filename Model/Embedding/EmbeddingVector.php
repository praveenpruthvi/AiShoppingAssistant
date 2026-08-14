<?php

declare(strict_types=1);

namespace Aavirbhava\AiShoppingAssistant\Model\Embedding;

use Aavirbhava\AiShoppingAssistant\Api\Embedding\EmbeddingVectorInterface;
use InvalidArgumentException;

/**
 * Immutable numeric vector.
 *
 * Rejects empty vectors, non-numeric members, dimension/count mismatch, and
 * non-finite values (NaN, INF) so invalid provider output fails fast.
 */
final readonly class EmbeddingVector implements EmbeddingVectorInterface
{
    /**
     * @param list<float> $values
     */
    public function __construct(
        private array $values,
        private int $dimension
    ) {
        if ($dimension < 1) {
            throw new InvalidArgumentException('Embedding vector dimension must be greater than zero.');
        }

        if ($values === []) {
            throw new InvalidArgumentException('Embedding vectors must not be empty.');
        }

        if (count($values) !== $dimension) {
            throw new InvalidArgumentException('Embedding vector length must match the declared dimension.');
        }

        foreach ($values as $value) {
            if (!is_int($value) && !is_float($value)) {
                throw new InvalidArgumentException('Embedding vectors may contain only numeric values.');
            }

            if (!is_finite((float) $value)) {
                throw new InvalidArgumentException('Embedding vectors must not contain non-finite values.');
            }
        }
    }

    public function values(): array
    {
        return $this->values;
    }

    public function dimension(): int
    {
        return $this->dimension;
    }
}