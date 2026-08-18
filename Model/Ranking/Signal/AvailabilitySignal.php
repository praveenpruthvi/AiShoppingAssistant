<?php

declare(strict_types=1);

namespace Aavirbhava\AiShoppingAssistant\Model\Ranking\Signal;

use Aavirbhava\AiShoppingAssistant\Api\Ranking\RankingSignalInterface;
use Aavirbhava\AiShoppingAssistant\Model\Ranking\SearchContext;
use Aavirbhava\AiShoppingAssistant\Model\Retrieval\SearchCandidate;
use Magento\Catalog\Model\Product\Visibility;

/**
 * Phase 1 signal: index-time availability (is_enabled/visibility), not live
 * stock — live stock/salability revalidation is a later task, paired with
 * the Output Validator.
 *
 * Retrieval already filters on is_enabled=true at query time and the
 * indexing eligibility policy already excludes non-search-visible products
 * at write time, so in the common case every candidate here already passes.
 * This signal is still real defense-in-depth, not just a formality: async
 * indexing means the index can briefly lag a product being disabled or
 * hidden in Magento, and this is the last point before the ranking pipeline
 * hands candidates to an LLM where such a stale-but-matched candidate would
 * otherwise be demoted nowhere. Registered last in the pipeline so it is the
 * authoritative gate regardless of what upstream signals scored.
 */
final class AvailabilitySignal implements RankingSignalInterface
{
    /**
     * @var list<int>
     */
    private const SEARCH_VISIBLE = [Visibility::VISIBILITY_IN_SEARCH, Visibility::VISIBILITY_BOTH];

    public function apply(SearchContext $context, array $candidates): array
    {
        return array_map(
            static function (SearchCandidate $candidate): SearchCandidate {
                $available = $candidate->isEnabled && in_array($candidate->visibility, self::SEARCH_VISIBLE, true);

                return $available ? $candidate : $candidate->withScore(0.0);
            },
            $candidates
        );
    }
}
