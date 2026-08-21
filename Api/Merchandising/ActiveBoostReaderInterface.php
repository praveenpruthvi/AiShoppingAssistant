<?php

declare(strict_types=1);

namespace Aavirbhava\AiShoppingAssistant\Api\Merchandising;

/**
 * The ranking pipeline's read path for merchandising boosts — reads live
 * from MySQL, scoped to only the product ids already in the current
 * candidate set (never an unconditional "all boosts" query), and never
 * indexed into OpenSearch: unlike product rating, a boost is sparse,
 * merchant-intent-driven, time-scoped, and expected to take effect the
 * moment it's saved, so a batch/cron-refreshed denormalized copy would be
 * the wrong tradeoff here.
 */
interface ActiveBoostReaderInterface
{
    /**
     * Resolves each given product id's current effective boost weight,
     * evaluated against real current time — a boost outside its own
     * start_date/end_date, or with is_active=0, contributes nothing. A
     * product id with no row, or no currently-active row, is simply
     * absent from the returned array (never a zero-valued entry).
     *
     * @param list<int> $productIds
     *
     * @return array<int, float> product id => effective boost weight
     */
    public function forProductIds(array $productIds): array;
}
