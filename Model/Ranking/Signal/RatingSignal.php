<?php

declare(strict_types=1);

namespace Aavirbhava\AiShoppingAssistant\Model\Ranking\Signal;

use Aavirbhava\AiShoppingAssistant\Api\Config\ConfigurationReaderInterface;
use Aavirbhava\AiShoppingAssistant\Api\Ranking\RankingSignalInterface;
use Aavirbhava\AiShoppingAssistant\Model\Ranking\SearchContext;
use Aavirbhava\AiShoppingAssistant\Model\Retrieval\SearchCandidate;

/**
 * Phase 2 signal: product rating, as a Bayesian/IMDB-style weighted average
 * rather than each candidate's raw rating average.
 *
 * A raw average would let a single 5-star review outrank a product with 500
 * reviews averaging 4.7 stars — clearly wrong. Instead this blends each
 * candidate's own average (weighted by its own review count) with the
 * catalogue-wide average (weighted by a fixed smoothing constant), so a
 * low-review-count product's score sits close to the catalogue mean until it
 * accumulates enough reviews to pull away from it:
 *
 *   WR = (v / (v + m)) * R + (m / (v + m)) * C
 *
 * where R is the candidate's own average rating, v its review count, C the
 * catalogue-wide mean rating (denormalized onto every candidate at index
 * time — see ProductRatingResolverInterface::catalogAverage()), and m a
 * fixed minimum-votes constant. A product with zero reviews has v=0, so WR
 * reduces exactly to C with no special-case branch needed.
 *
 * m is an internal smoothing constant, not admin-configurable — only the
 * overall contribution of this signal to the running score is (see
 * RetrievalConfigInterface::ratingSignalWeight()), consistent with how
 * AttributeMatchSignal's own boost curve is fixed while its place in the
 * pipeline is configurable via the same weight mechanism.
 */
final class RatingSignal implements RankingSignalInterface
{
    /**
     * Minimum-votes smoothing constant: how many "catalogue-average" reviews
     * a candidate's own rating is blended against. Higher values require
     * more of a candidate's own reviews before its score meaningfully
     * departs from the catalogue mean.
     */
    private const MINIMUM_VOTES = 10.0;

    private const RATING_SCALE_MAX = 5.0;

    public function __construct(
        private readonly ConfigurationReaderInterface $configurationReader
    ) {
    }

    public function apply(SearchContext $context, array $candidates): array
    {
        $weight = $this->configurationReader->readRetrieval($context->storeId)->ratingSignalWeight();

        if ($weight <= 0.0) {
            return $candidates;
        }

        return array_map(
            function (SearchCandidate $candidate) use ($weight): SearchCandidate {
                $weightedRating = $this->weightedRating($candidate);
                $normalized = $weightedRating / self::RATING_SCALE_MAX;

                return $candidate->withScore($candidate->score + $normalized * $weight);
            },
            $candidates
        );
    }

    private function weightedRating(SearchCandidate $candidate): float
    {
        $v = (float) $candidate->reviewCount;
        $r = $candidate->ratingAverage;
        $c = $candidate->catalogRatingAverage;
        $m = self::MINIMUM_VOTES;

        return ($v / ($v + $m)) * $r + ($m / ($v + $m)) * $c;
    }
}
