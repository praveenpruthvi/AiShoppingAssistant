<?php

declare(strict_types=1);

namespace Aavirbhava\AiShoppingAssistant\Model\Retrieval;

use InvalidArgumentException;

/**
 * One product candidate surfaced by hybrid retrieval, carrying only index
 * data (never price, stock, or customer-group data — those are deliberately
 * excluded from the assistant index and must be revalidated live before
 * anything reaches a customer, by a later task).
 *
 * Immutable. bm25Score/vectorScore are the raw retrieval-time scores from
 * each query (0.0 when the candidate was not found by that query); score is
 * the running ranking-pipeline score, threaded through RankingSignalInterface
 * stages via withScore() since every value here is otherwise read-only.
 */
final readonly class SearchCandidate
{
    /**
     * @param list<string> $categoryNames
     * @param list<array{code: string, label: string, values: list<string>}> $attributes
     */
    public function __construct(
        public int $entityId,
        public string $sku,
        public int $storeId,
        public string $name,
        public string $shortDescription,
        public array $categoryNames,
        public array $attributes,
        public bool $isEnabled,
        public int $visibility,
        public float $bm25Score,
        public float $vectorScore,
        public float $score = 0.0
    ) {
        if ($entityId < 1) {
            throw new InvalidArgumentException('A search candidate requires a positive entity id.');
        }

        if ($sku === '') {
            throw new InvalidArgumentException('A search candidate requires a non-empty SKU.');
        }

        if ($storeId < 1) {
            throw new InvalidArgumentException('A search candidate requires a positive store id.');
        }

        if ($bm25Score < 0.0 || $vectorScore < 0.0) {
            throw new InvalidArgumentException('Search candidate retrieval scores must not be negative.');
        }
    }

    public function withScore(float $score): self
    {
        return new self(
            $this->entityId,
            $this->sku,
            $this->storeId,
            $this->name,
            $this->shortDescription,
            $this->categoryNames,
            $this->attributes,
            $this->isEnabled,
            $this->visibility,
            $this->bm25Score,
            $this->vectorScore,
            $score
        );
    }
}
