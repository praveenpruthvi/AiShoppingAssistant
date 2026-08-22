<?php

declare(strict_types=1);

namespace Aavirbhava\AiShoppingAssistant\Model\Ranking\Signal;

use Aavirbhava\AiShoppingAssistant\Api\Merchandising\ActiveBoostReaderInterface;
use Aavirbhava\AiShoppingAssistant\Api\Merchandising\ActiveCategoryBoostReaderInterface;
use Aavirbhava\AiShoppingAssistant\Api\Merchandising\ProductCategoryMembershipReaderInterface;
use Aavirbhava\AiShoppingAssistant\Api\Ranking\RankingSignalInterface;
use Aavirbhava\AiShoppingAssistant\Model\Merchandising\MerchandisingBoostRow;
use Aavirbhava\AiShoppingAssistant\Model\Ranking\SearchContext;
use Aavirbhava\AiShoppingAssistant\Model\Retrieval\SearchCandidate;

/**
 * Phase 2 signal: admin-configured merchandising boost, additive to the
 * existing 5 (text relevance, vector similarity, attribute match, rating,
 * availability). Combines TWO boost sources, per-product (Task 32) and
 * per-category (Task 33):
 *
 *   final boost = min(MAX_BOOST_WEIGHT, productBoost + max(active boost
 *                      among the product's own categories))
 *
 * — MAX (never sum) across a product's own multiple boosted categories,
 * since belonging to 3 differently-boosted categories should not let a
 * merchant accidentally stack an unbounded score just by cross-assigning
 * a product into more categories; the single strongest applicable
 * category boost is what counts, exactly like ActiveBoostReader/
 * ActiveCategoryBoostReader's own MAX(boost_weight) SQL already does for
 * multiple OVERLAPPING boosts on the very same product/category. Product
 * and category boosts DO sum together (they are deliberately two
 * different, independent merchant decisions — "this product" and "this
 * whole category" — not two instances of the same decision), capped once
 * at MAX_BOOST_WEIGHT AFTER summing, not once per source.
 *
 * Extended in place (Task 33) rather than adding a second, parallel
 * signal: the capping-after-summing formula above genuinely REQUIRES
 * knowing both a candidate's product boost AND its category boost
 * together before capping — two independent signals, each independently
 * additive-and-capped in the RankingSignalInterface pipeline's normal
 * sequential fashion, cannot express "cap the COMBINED total" without one
 * signal reaching into the other's own state (breaking the pipeline's
 * "every signal is a pure, independent, order-applied transformation"
 * architecture) or silently allowing the combined contribution to exceed
 * MAX_BOOST_WEIGHT (each source capping only its own share, e.g.
 * 0.8 product + 0.9 category = 1.7 added to score, when the real
 * requirement is min(1.0, 0.8+0.9) = 1.0). One signal, reading both boost
 * sources together, is the only way to implement the spec correctly.
 *
 * Reads live from MySQL (ActiveBoostReaderInterface,
 * ActiveCategoryBoostReaderInterface, ProductCategoryMembershipReaderInterface),
 * each scoped to only the product/category ids already relevant to this
 * candidate set — never an unconditional "every active boost in the
 * store" query — so a boost (product or category) takes effect the
 * moment an admin saves it, with no reindex and no cron.
 *
 * No new SearchCandidate fields were needed for this task — audited
 * explicitly (Task 33's own requirement, given Task 31's original "a
 * field silently dropped on withScore() reconstruction" bug class):
 * both product and category boosts are looked up purely by
 * SearchCandidate::entityId (already present, already correctly threaded
 * through withScore() since Task 32), and category membership is
 * resolved via ProductCategoryMembershipReaderInterface, a live query
 * keyed by that same entityId — not from any field carried on
 * SearchCandidate itself. SearchCandidate::$categoryNames (display names
 * only, no ids) was explicitly considered and rejected as a lookup key
 * for this purpose — see ProductCategoryMembershipReaderInterface's own
 * docblock. withScore() itself was not touched; see
 * SearchCandidateTest::testWithScoreReturnsANewInstanceWithEveryOtherFieldPreserved.
 *
 * A boost (of either kind) can only ever raise a candidate that
 * retrieval, live revalidation, and availability have already surfaced
 * as a legitimate result for this query — it never injects a product
 * retrieval didn't find. Never registered after AvailabilitySignal — it
 * remains the pipeline's last, authoritative gate regardless of any
 * boost.
 */
final class MerchandisingBoostSignal implements RankingSignalInterface
{
    public function __construct(
        private readonly ActiveBoostReaderInterface $activeBoostReader,
        private readonly ActiveCategoryBoostReaderInterface $activeCategoryBoostReader,
        private readonly ProductCategoryMembershipReaderInterface $categoryMembershipReader
    ) {
    }

    public function apply(SearchContext $context, array $candidates): array
    {
        if ($candidates === []) {
            return $candidates;
        }

        $productIds = array_map(static fn (SearchCandidate $candidate): int => $candidate->entityId, $candidates);

        $productBoosts = $this->activeBoostReader->forProductIds($productIds);
        $categoryMemberships = $this->categoryMembershipReader->forProductIds($productIds);

        $allCategoryIds = [];
        foreach ($categoryMemberships as $categoryIds) {
            foreach ($categoryIds as $categoryId) {
                $allCategoryIds[] = $categoryId;
            }
        }

        $categoryBoosts = $allCategoryIds !== []
            ? $this->activeCategoryBoostReader->forCategoryIds(array_values(array_unique($allCategoryIds)))
            : [];

        if ($productBoosts === [] && $categoryBoosts === []) {
            return $candidates;
        }

        return array_map(
            function (SearchCandidate $candidate) use ($productBoosts, $categoryMemberships, $categoryBoosts): SearchCandidate {
                $productBoost = $productBoosts[$candidate->entityId] ?? 0.0;

                $maxCategoryBoost = 0.0;
                foreach ($categoryMemberships[$candidate->entityId] ?? [] as $categoryId) {
                    $maxCategoryBoost = max($maxCategoryBoost, $categoryBoosts[$categoryId] ?? 0.0);
                }

                $combinedBoost = min($productBoost + $maxCategoryBoost, MerchandisingBoostRow::MAX_BOOST_WEIGHT);

                return $combinedBoost > 0.0 ? $candidate->withScore($candidate->score + $combinedBoost) : $candidate;
            },
            $candidates
        );
    }
}
