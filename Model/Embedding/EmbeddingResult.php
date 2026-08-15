<?php

declare(strict_types=1);

namespace Aavirbhava\AiShoppingAssistant\Model\Embedding;

use Aavirbhava\AiShoppingAssistant\Api\Embedding\EmbeddingResultInterface;
use Aavirbhava\AiShoppingAssistant\Api\Embedding\EmbeddingUsageInterface;
use Aavirbhava\AiShoppingAssistant\Api\Embedding\EmbeddingVectorInterface;
use InvalidArgumentException;

/**
 * Immutable, validated embedding batch result.
 *
 * The i-th vector corresponds to the i-th identifier and to the i-th request
 * input. Vectors must be non-empty, identifiers must be non-empty and unique,
 * and identifier count must match vector count.
 */
final readonly class EmbeddingResult implements EmbeddingResultInterface
{
    /**
     * @param list<EmbeddingVectorInterface> $vectors
     * @param list<string>                   $inputIdentifiers
     */
    public function __construct(
        private array $vectors,
        private array $inputIdentifiers,
        private string $model,
        private EmbeddingUsageInterface $usage
    ) {
        if ($vectors === []) {
            throw new InvalidArgumentException('Embedding results must contain at least one vector.');
        }

        if (count($inputIdentifiers) !== count($vectors)) {
            throw new InvalidArgumentException('Embedding identifiers must match vectors one-to-one.');
        }

        foreach ($vectors as $vector) {
            if (!$vector instanceof EmbeddingVectorInterface) {
                throw new InvalidArgumentException('Embedding result vectors must implement EmbeddingVectorInterface.');
            }
        }

        foreach ($inputIdentifiers as $identifier) {
            if (!is_string($identifier) || $identifier === '') {
                throw new InvalidArgumentException('Embedding input identifiers must be non-empty strings.');
            }
        }

        if (count(array_unique($inputIdentifiers)) !== count($inputIdentifiers)) {
            throw new InvalidArgumentException('Embedding input identifiers must be unique.');
        }

        if ($model === '') {
            throw new InvalidArgumentException('Embedding result model must not be empty.');
        }
    }

    public function vectors(): array
    {
        return $this->vectors;
    }

    public function inputIdentifiers(): array
    {
        return $this->inputIdentifiers;
    }

    public function model(): string
    {
        return $this->model;
    }

    public function usage(): EmbeddingUsageInterface
    {
        return $this->usage;
    }
}
