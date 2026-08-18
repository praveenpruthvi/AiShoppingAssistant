<?php

declare(strict_types=1);

namespace Aavirbhava\AiShoppingAssistant\Model\Ranking\Signal;

use Aavirbhava\AiShoppingAssistant\Api\Ranking\RankingSignalInterface;
use Aavirbhava\AiShoppingAssistant\Model\Ranking\SearchContext;
use Aavirbhava\AiShoppingAssistant\Model\Retrieval\SearchCandidate;

/**
 * Phase 1 signal: semantic (vector) similarity.
 *
 * Uses the raw k-NN score already produced by retrieval's vector query
 * (SearchCandidate::vectorScore) — this signal does not recompute cosine
 * similarity. OpenSearch's cosinesimil space type already returns a score in
 * [0, 1] ((1 + cosine_similarity) / 2), so it is clamped defensively and
 * added directly, without the BM25-style normalization TextRelevanceSignal
 * needs.
 */
final class VectorSimilaritySignal implements RankingSignalInterface
{
    public function apply(SearchContext $context, array $candidates): array
    {
        return array_map(
            static function (SearchCandidate $candidate): SearchCandidate {
                $clamped = max(0.0, min(1.0, $candidate->vectorScore));

                return $candidate->withScore($candidate->score + $clamped);
            },
            $candidates
        );
    }
}
