<?php

declare(strict_types=1);

namespace Aavirbhava\AiShoppingAssistant\Model\Merchandising;

use Aavirbhava\AiShoppingAssistant\Api\Merchandising\ProductCategoryMembershipReaderInterface;
use Magento\Framework\App\ResourceConnection;

/**
 * Live-reads real category memberships straight from
 * `catalog_category_product`, scoped to only the given product ids —
 * mirrors ActiveBoostReader/ActiveCategoryBoostReader's own "no ORM in
 * the runtime hot path" convention (a raw ResourceConnection query, not
 * the EAV category collection) and per-instance-only memoization (no
 * invalidation logic needed: a product's category assignment change
 * always happens in a separate PHP-FPM request/process from any later
 * ranking read, exactly the same reasoning as those two readers' own
 * docblocks).
 *
 * Deliberately does NOT filter by the category's own is_active/enabled
 * status here — that is a distinct Magento CATEGORY-entity concept from
 * this module's own boost `is_active` flag (ActiveCategoryBoostReader
 * already filters ON that). A product's real membership in a category is
 * reported as-is; whether a boost on that category currently applies is
 * a separate question this reader has no opinion on.
 */
final class ProductCategoryMembershipReader implements ProductCategoryMembershipReaderInterface
{
    private const TABLE = 'catalog_category_product';

    /**
     * @var array<string, array<int, list<int>>>
     */
    private array $cache = [];

    public function __construct(
        private readonly ResourceConnection $resource
    ) {
    }

    public function forProductIds(array $productIds): array
    {
        $ids = $this->normalizeIds($productIds);

        if ($ids === []) {
            return [];
        }

        $cacheKey = implode(',', $ids);
        if (isset($this->cache[$cacheKey])) {
            return $this->cache[$cacheKey];
        }

        $connection = $this->resource->getConnection();

        $select = $connection->select()
            ->from($this->resource->getTableName(self::TABLE), ['product_id', 'category_id'])
            ->where('product_id IN (?)', $ids);

        $result = [];
        foreach ($connection->fetchAll($select) as $row) {
            $productId = (int) $row['product_id'];
            $result[$productId][] = (int) $row['category_id'];
        }

        $this->cache[$cacheKey] = $result;

        return $result;
    }

    /**
     * @param list<int> $productIds
     *
     * @return list<int>
     */
    private function normalizeIds(array $productIds): array
    {
        $ids = [];
        foreach ($productIds as $productId) {
            if (is_int($productId) && $productId > 0) {
                $ids[] = $productId;
            }
        }

        $ids = array_values(array_unique($ids));
        sort($ids);

        return $ids;
    }
}
