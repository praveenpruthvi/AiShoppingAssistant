<?php

declare(strict_types=1);

namespace Aavirbhava\AiShoppingAssistant\Model\Ranking\Signal;

use Aavirbhava\AiShoppingAssistant\Api\Merchandising\ActiveBoostReaderInterface;
use Aavirbhava\AiShoppingAssistant\Api\Ranking\RankingSignalInterface;
use Aavirbhava\AiShoppingAssistant\Model\Merchandising\MerchandisingBoostRow;
use Aavirbhava\AiShoppingAssistant\Model\Ranking\SearchContext;
use Aavirbhava\AiShoppingAssistant\Model\Retrieval\SearchCandidate;

/**
 * Phase 2 signal: admin-configured, per-product merchandising boost,
 * additive to the existing 5 (text relevance, vector similarity,
 * attribute match, rating, availability).
 *
 * Reads live from MySQL (ActiveBoostReaderInterface), scoped to only the
 * product ids already present in this candidate set — never an
 * unconditional "every active boost" query — so a boost takes effect the
 * moment an admin saves it, with no reindex and no cron. Unlike
 * RatingSignal, no new SearchCandidate fields are needed: a boost is
 * looked up by SearchCandidate::entityId, a field withScore() already
 * threads through correctly (verified — see
 * SearchCandidateTest::testWithScoreReturnsANewInstanceWithEveryOtherFieldPreserved
 * and the Task 32 status report for the explicit re-check this task's own
 * requirements asked for).
 *
 * A boost can only ever raise a candidate that retrieval, live
 * revalidation, and availability have already surfaced as a legitimate
 * result for this query — it never injects a product retrieval didn't
 * find, and its contribution is capped at
 * MerchandisingBoostRow::MAX_BOOST_WEIGHT (1.0, roughly one full
 * relevance signal's own typical contribution) so a boost stays additive
 * rather than becoming a de facto override of genuine relevance. Never
 * registered after AvailabilitySignal — it remains the pipeline's last,
 * authoritative gate regardless of any boost.
 */
final class MerchandisingBoostSignal implements RankingSignalInterface
{
    public function __construct(
        private readonly ActiveBoostReaderInterface $activeBoostReader
    ) {
    }

    public function apply(SearchContext $context, array $candidates): array
    {
        if ($candidates === []) {
            return $candidates;
        }

        $productIds = array_map(static fn (SearchCandidate $candidate): int => $candidate->entityId, $candidates);
        $boosts = $this->activeBoostReader->forProductIds($productIds);

        if ($boosts === []) {
            return $candidates;
        }

        return array_map(
            static function (SearchCandidate $candidate) use ($boosts): SearchCandidate {
                $boost = $boosts[$candidate->entityId] ?? 0.0;
                $boost = min($boost, MerchandisingBoostRow::MAX_BOOST_WEIGHT);

                return $boost > 0.0 ? $candidate->withScore($candidate->score + $boost) : $candidate;
            },
            $candidates
        );
    }
}
