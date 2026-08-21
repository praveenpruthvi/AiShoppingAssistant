<?php

declare(strict_types=1);

namespace Aavirbhava\AiShoppingAssistant\Model\Config;

use Aavirbhava\AiShoppingAssistant\Api\Config\RetrievalConfigInterface;
use InvalidArgumentException;

final readonly class RetrievalConfig implements RetrievalConfigInterface
{
    public function __construct(
        private int $keywordCandidates,
        private int $vectorCandidates,
        private int $mergedCandidates,
        private int $finalProducts,
        private bool $rerankerEnabled,
        private float $ratingSignalWeight
    ) {
        foreach ([$keywordCandidates, $vectorCandidates, $mergedCandidates, $finalProducts] as $candidate) {
            if ($candidate < 1) {
                throw new InvalidArgumentException('Retrieval candidate counts must be greater than zero.');
            }
        }

        if ($ratingSignalWeight < 0.0) {
            throw new InvalidArgumentException('The rating signal weight must not be negative.');
        }
    }

    public function keywordCandidates(): int
    {
        return $this->keywordCandidates;
    }

    public function vectorCandidates(): int
    {
        return $this->vectorCandidates;
    }

    public function mergedCandidates(): int
    {
        return $this->mergedCandidates;
    }

    public function finalProducts(): int
    {
        return $this->finalProducts;
    }

    public function isRerankerEnabled(): bool
    {
        return $this->rerankerEnabled;
    }

    public function ratingSignalWeight(): float
    {
        return $this->ratingSignalWeight;
    }
}
