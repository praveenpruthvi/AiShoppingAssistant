<?php

declare(strict_types=1);

namespace Aavirbhava\AiShoppingAssistant\Model\Playground;

use Aavirbhava\AiShoppingAssistant\Api\Ranking\RankingSignalCollectorInterface;
use Aavirbhava\AiShoppingAssistant\Model\Retrieval\SearchCandidate;

/**
 * Records the candidate list's state after every ranking signal, for the
 * admin Playground's "combined ranking" panel. Not a DI-registered
 * service — the Playground constructs one fresh per query.
 */
final class PlaygroundRankingCollector implements RankingSignalCollectorInterface
{
    /**
     * @var list<array{signal: string, candidates: list<SearchCandidate>}>
     */
    public array $stages = [];

    public function recordStage(string $signalIdentifier, array $candidates): void
    {
        $this->stages[] = ['signal' => $signalIdentifier, 'candidates' => $candidates];
    }
}
