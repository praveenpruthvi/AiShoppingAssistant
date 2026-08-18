<?php

declare(strict_types=1);

namespace Aavirbhava\AiShoppingAssistant\Model\Ranking\Signal;

use Aavirbhava\AiShoppingAssistant\Api\Ranking\RankingSignalInterface;
use Aavirbhava\AiShoppingAssistant\Model\Ranking\SearchContext;
use Aavirbhava\AiShoppingAssistant\Model\Retrieval\SearchCandidate;

/**
 * Phase 1 signal: keyword (BM25) relevance.
 *
 * Uses the raw BM25 score already produced by retrieval's keyword query
 * (SearchCandidate::bm25Score) — this signal does not re-run text matching.
 * BM25 scores are unbounded and vary with corpus size, so they are
 * normalized to [0, 1) via score / (score + 1) before contributing to the
 * running rank score, keeping this signal's contribution comparable in
 * magnitude to the other Phase-1 signals.
 */
final class TextRelevanceSignal implements RankingSignalInterface
{
    public function apply(SearchContext $context, array $candidates): array
    {
        return array_map(
            static function (SearchCandidate $candidate): SearchCandidate {
                $normalized = $candidate->bm25Score / ($candidate->bm25Score + 1.0);

                return $candidate->withScore($candidate->score + $normalized);
            },
            $candidates
        );
    }
}
