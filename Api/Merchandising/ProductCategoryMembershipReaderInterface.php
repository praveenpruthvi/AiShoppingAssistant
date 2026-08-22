<?php

declare(strict_types=1);

namespace Aavirbhava\AiShoppingAssistant\Api\Merchandising;

/**
 * Resolves which real categories each given product currently belongs to
 * — live from `catalog_category_product`, scoped to only the product ids
 * given (never an unconditional "every category assignment in the
 * catalog" query), the missing link needed to combine a product's own
 * merchandising boost with the boost(s) on the categories it belongs to
 * (see MerchandisingBoostSignal's own docblock). Deliberately its own,
 * separate reader — SearchCandidate::$categoryNames carries display
 * NAMES only, never category ids, so it cannot be reused for this lookup
 * (see MerchandisingBoostSignal's own docblock for the full reasoning).
 */
interface ProductCategoryMembershipReaderInterface
{
    /**
     * @param list<int> $productIds
     *
     * @return array<int, list<int>> product id => list of category ids it
     *     currently belongs to (an empty/absent entry means no category
     *     membership at all, not an error)
     */
    public function forProductIds(array $productIds): array;
}
