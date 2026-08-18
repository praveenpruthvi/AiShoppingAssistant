<?php

declare(strict_types=1);

namespace Aavirbhava\AiShoppingAssistant\Api\Retrieval;

use Aavirbhava\AiShoppingAssistant\Model\Retrieval\SearchCandidate;

/**
 * Store-scoped hybrid (BM25 + vector) retrieval against the assistant index.
 *
 * Implementations must activate and scope to a store view, execute a keyword
 * query and a k-NN vector query against the store's read alias, merge and
 * deduplicate the two result sets by product entity id, and cap the result
 * at the configured merged-candidate count. Candidates carry index data only
 * (never price, stock, or customer-group data) and are not yet ranked —
 * ranking is RankingPipelineInterface's job, not this service's.
 */
interface HybridRetrievalServiceInterface
{
    /**
     * @return list<SearchCandidate>
     */
    public function retrieve(int $storeId, string $queryText): array;
}
