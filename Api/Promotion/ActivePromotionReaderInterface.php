<?php

declare(strict_types=1);

namespace Aavirbhava\AiShoppingAssistant\Api\Promotion;

use Aavirbhava\AiShoppingAssistant\Model\Revalidation\RevalidatedProduct;

/**
 * Reads real, currently-active promotions live from Magento at request
 * time — never indexed into OpenSearch, never batch/cron-refreshed. Both
 * Catalog Price Rules and Cart Price Rules are date/merchant-scoped and
 * expected to reflect real current state the moment a rule's schedule or
 * an admin's edit takes effect, the same reasoning
 * MerchandisingBoostSignal (Task 32) already established for boosts —
 * no reindex, no MAPPING_VERSION bump, no expiry cron.
 */
interface ActivePromotionReaderInterface
{
    /**
     * Scoped to only the given products (never an unconditional "every
     * catalog rule" query) — mirrors ActiveBoostReaderInterface's own
     * "read live, scoped to the current candidate set" discipline.
     * $products carries real, already-live-revalidated data (entity id,
     * sku, regular price) so this never has to re-resolve a product or
     * re-derive its regular price from anywhere else.
     *
     * @param list<RevalidatedProduct> $products
     *
     * @return array<string, ProductPromotionInterface> sku => promotion,
     *     present only for a sku with a genuinely active discount
     */
    public function catalogRuleDiscounts(int $storeId, ?int $customerGroupId, array $products): array;

    /**
     * Every Cart Price Rule currently active for this store's website +
     * customer group (real current time, real website/customer-group
     * scoping) — not scoped to specific products, since a cart rule is a
     * store/order-level promotion, not a per-product one.
     *
     * @return list<CartPromotionInterface>
     */
    public function activeCartRules(int $storeId, ?int $customerGroupId): array;
}
