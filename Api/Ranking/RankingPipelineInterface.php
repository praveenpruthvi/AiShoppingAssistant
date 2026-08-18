<?php

declare(strict_types=1);

namespace Aavirbhava\AiShoppingAssistant\Api\Ranking;

use Aavirbhava\AiShoppingAssistant\Model\Ranking\SearchContext;
use Aavirbhava\AiShoppingAssistant\Model\Retrieval\SearchCandidate;

/**
 * Runs a candidate list through every registered RankingSignalInterface in
 * sequence and returns the final ranked list, capped at the store's
 * configured final_products count.
 */
interface RankingPipelineInterface
{
    /**
     * $collector (Task 9) is an optional debug-capture seam — pass one to
     * observe the candidate list's state after each signal; every
     * existing caller passes nothing and sees no change in behavior.
     *
     * @param list<SearchCandidate> $candidates
     *
     * @return list<SearchCandidate>
     */
    public function rank(SearchContext $context, array $candidates, ?RankingSignalCollectorInterface $collector = null): array;
}
