<?php

declare(strict_types=1);

namespace Aavirbhava\AiShoppingAssistant\Model\Merchandising;

use Aavirbhava\AiShoppingAssistant\Api\Merchandising\ActiveBoostReaderInterface;
use Aavirbhava\AiShoppingAssistant\Model\Indexing\Clock\ClockInterface;
use Aavirbhava\AiShoppingAssistant\Model\ResourceModel\MerchandisingBoost as MerchandisingBoostResource;
use Magento\Framework\App\ResourceConnection;

/**
 * Live-reads active merchandising boosts straight from MySQL, scoped to
 * only the given product ids — deliberately raw ResourceConnection, not
 * the admin grid's AbstractCollection, matching this module's established
 * "no ORM in the runtime hot path" convention (see DbConversationHistoryStore)
 * and avoiding the collection layer's per-row hydration overhead for what
 * is, every chat turn, a single scoped SELECT over ~10-30 ids.
 *
 * Memoized per instance (a plain array keyed by the exact, sorted set of
 * product ids requested) purely to avoid a duplicate identical query
 * within the same PHP request — e.g. if something calls
 * RankingPipeline::rank() more than once for the same candidate set in
 * one request. This intentionally has NO invalidation logic: a saved
 * boost always happens in a separate PHP-FPM request/process from any
 * later ranking read, and this memoization array is a plain instance
 * property that does not survive past the request that created it (no
 * cache pool, no shared storage, nothing to invalidate) — so the very
 * next request, the one that would actually observe an admin's save,
 * starts with a fresh, empty cache and reads the current data. This is
 * live-verified (not just asserted) in the Task 32 status report.
 */
final class ActiveBoostReader implements ActiveBoostReaderInterface
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
        $now = $this->clock->now()->format('Y-m-d H:i:s');

        $select = $connection->select()
            ->from(
                $this->resource->getTableName(MerchandisingBoostResource::TABLE),
                ['product_id', 'boost_weight' => new \Zend_Db_Expr('MAX(boost_weight)')]
            )
            ->where('product_id IN (?)', $ids)
            ->where('is_active = ?', 1)
            ->where('(start_date IS NULL OR start_date <= ?)', $now)
            ->where('(end_date IS NULL OR end_date >= ?)', $now)
            ->group('product_id');

        $rows = $connection->fetchPairs($select);

        $result = [];
        foreach ($rows as $productId => $boostWeight) {
            $result[(int) $productId] = (float) $boostWeight;
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
