<?php

declare(strict_types=1);

namespace Aavirbhava\AiShoppingAssistant\Api\Ranking;

use Aavirbhava\AiShoppingAssistant\Model\Retrieval\SearchCandidate;

/**
 * Optional debug-capture seam for RankingPipelineInterface::rank(): when a
 * caller passes one in, it is told the candidate list's state immediately
 * after each signal ran, in registration order. Production callers
 * (ProductContextResolver) never pass one — RankingPipeline's own
 * behavior is completely unchanged when $collector is null, which is the
 * only reason this could be added without touching every existing caller.
 *
 * Built for the admin Playground (Task 9) so its "combined ranking" panel
 * can honestly show each signal's real, individual contribution to the
 * final score, rather than only the aggregate RankingPipeline itself
 * already returns.
 */
interface RankingSignalCollectorInterface
{
    /**
     * @param list<SearchCandidate> $candidates the list as it stood
     *     immediately after this signal was applied
     */
    public function recordStage(string $signalIdentifier, array $candidates): void;
}
