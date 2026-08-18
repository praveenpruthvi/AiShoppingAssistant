<?php

declare(strict_types=1);

namespace Aavirbhava\AiShoppingAssistant\Api\Ranking;

use Aavirbhava\AiShoppingAssistant\Model\Ranking\SearchContext;
use Aavirbhava\AiShoppingAssistant\Model\Retrieval\SearchCandidate;

/**
 * One stage in the extensible ranking pipeline.
 *
 * Implementations must be pure and side-effect-free: given a context and the
 * candidate list produced by the previous stage, return a new candidate list
 * (SearchCandidate is immutable — use withScore() to carry an updated
 * score). Phase 2 signals (promotion, margin, popularity, personalization,
 * clearance, campaign) implement this same interface and are added purely
 * through a new class plus a di.xml registration — RankingPipeline and the
 * four Phase-1 signals never need to change.
 */
interface RankingSignalInterface
{
    /**
     * @param list<SearchCandidate> $candidates
     *
     * @return list<SearchCandidate>
     */
    public function apply(SearchContext $context, array $candidates): array;
}
