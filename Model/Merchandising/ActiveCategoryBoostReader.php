<?php

declare(strict_types=1);

namespace Aavirbhava\AiShoppingAssistant\Model\Merchandising;

use Aavirbhava\AiShoppingAssistant\Api\Merchandising\ActiveCategoryBoostReaderInterface;
use Aavirbhava\AiShoppingAssistant\Model\Indexing\Clock\ClockInterface;
use Aavirbhava\AiShoppingAssistant\Model\ResourceModel\CategoryBoost as CategoryBoostResource;
use Magento\Framework\App\ResourceConnection;

/**
 * Live-reads active category boosts straight from MySQL, scoped to only
 * the given category ids — mirrors ActiveBoostReader exactly (same raw
 * ResourceConnection query shape, same real-current-time date evaluation,
 * same per-instance-only memoization with no invalidation logic and no
 * cross-request staleness risk — see that class's own docblock for the
 * full reasoning, which applies identically here).
 */
final class ActiveCategoryBoostReader implements ActiveCategoryBoostReaderInterface
{
    /**
     * @var array<string, array<int, float>>
     */
    private array $cache = [];

    public function __construct(
        private readonly ResourceConnection $resource,
        private readonly ClockInterface $clock
    ) {
    }

    public function forCategoryIds(array $categoryIds): array
    {
        $ids = $this->normalizeIds($categoryIds);

        if ($ids === []) {
            return [];
        }

        $cacheKey = implode(',', $ids);
        if (isset($this->cache[$cacheKey])) {
            return $this->cache[$cacheKey];
        }

        $connection = $this->resource->getConnection();
        $now = $this->clock->now()->format('Y-m-d H:i:s');

        $select = $connection->select()
            ->from(
                $this->resource->getTableName(CategoryBoostResource::TABLE),
                ['category_id', 'boost_weight' => new \Zend_Db_Expr('MAX(boost_weight)')]
            )
            ->where('category_id IN (?)', $ids)
            ->where('is_active = ?', 1)
            ->where('(start_date IS NULL OR start_date <= ?)', $now)
            ->where('(end_date IS NULL OR end_date >= ?)', $now)
            ->group('category_id');

        $rows = $connection->fetchPairs($select);

        $result = [];
        foreach ($rows as $categoryId => $boostWeight) {
            $result[(int) $categoryId] = (float) $boostWeight;
        }

        $this->cache[$cacheKey] = $result;

        return $result;
    }

    /**
     * @param list<int> $categoryIds
     *
     * @return list<int>
     */
    private function normalizeIds(array $categoryIds): array
    {
        $ids = [];
        foreach ($categoryIds as $categoryId) {
            if (is_int($categoryId) && $categoryId > 0) {
                $ids[] = $categoryId;
            }
        }

        $ids = array_values(array_unique($ids));
        sort($ids);

        return $ids;
    }
}
