<?php

declare(strict_types=1);

namespace Aavirbhava\AiShoppingAssistant\Model\Dto;

use InvalidArgumentException;

final readonly class EmbeddingBatch
{
    /**
     * @param list<list<float>> $vectors
     */
    public function __construct(
        public array $vectors,
        public int $dimensions,
        public string $provider,
        public string $model,
        public int $inputTokens = 0
    ) {
        if ($dimensions < 1) {
            throw new InvalidArgumentException('Embedding dimensions must be greater than zero.');
        }

        if ($provider === '' || $model === '') {
            throw new InvalidArgumentException('Embedding provider and model must not be empty.');
        }

        if ($inputTokens < 0) {
            throw new InvalidArgumentException('Input tokens must not be negative.');
        }

        foreach ($vectors as $vector) {
            if (count($vector) !== $dimensions) {
                throw new InvalidArgumentException('Every embedding vector must match the declared dimensions.');
            }

            foreach ($vector as $value) {
                if (!is_float($value) && !is_int($value)) {
                    throw new InvalidArgumentException('Embedding vectors may contain only numeric values.');
                }
            }
        }
    }

    public function count(): int
    {
        return count($this->vectors);
    }
}
