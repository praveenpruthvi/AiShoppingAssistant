<?php

declare(strict_types=1);

namespace Aavirbhava\AiShoppingAssistant\Api\Merchandising;

/**
 * The ranking pipeline's read path for category-level merchandising
 * boosts — mirrors ActiveBoostReaderInterface exactly, just keyed by
 * category id instead of product id. Reads live from MySQL, scoped to
 * only the category ids actually present among the current candidate
 * set's own real category memberships (see
 * ProductCategoryMembershipReaderInterface) — never an unconditional
 * "all boosted categories" query.
 */
interface ActiveCategoryBoostReaderInterface
{
    /**
     * Resolves each given category id's current effective boost weight,
     * evaluated against real current time — a boost outside its own
     * start_date/end_date, or with is_active=0, contributes nothing. A
     * category id with no row, or no currently-active row, is simply
     * absent from the returned array (never a zero-valued entry).
     *
     * @param list<int> $categoryIds
     *
     * @return array<int, float> category id => effective boost weight
     */
    public function forCategoryIds(array $categoryIds): array;
}
