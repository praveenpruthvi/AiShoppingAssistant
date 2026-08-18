<?php

declare(strict_types=1);

namespace Aavirbhava\AiShoppingAssistant\Model\Ranking;

use InvalidArgumentException;

/**
 * Immutable context threaded through the ranking pipeline: the raw query and
 * store scope every signal may need, plus whether the store has reranking
 * configured on (read here so a later reranking task can consume it without
 * re-reading config; this pipeline does not call a reranker itself).
 */
final readonly class SearchContext
{
    public function __construct(
        public int $storeId,
        public string $queryText,
        public bool $rerankerRequested
    ) {
        if ($storeId < 1) {
            throw new InvalidArgumentException('A search context requires a positive store id.');
        }

        if ($queryText === '') {
            throw new InvalidArgumentException('A search context requires a non-empty query.');
        }
    }
}
