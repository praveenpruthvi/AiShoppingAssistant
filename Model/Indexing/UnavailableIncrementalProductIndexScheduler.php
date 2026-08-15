<?php

declare(strict_types=1);

namespace Aavirbhava\AiShoppingAssistant\Model\Indexing;

use Aavirbhava\AiShoppingAssistant\Api\Indexing\IncrementalProductIndexSchedulerInterface;
use Aavirbhava\AiShoppingAssistant\Model\Indexing\Exception\InvalidProductIndexEntityIdsException;
use Aavirbhava\AiShoppingAssistant\Model\Indexing\Exception\ProductIndexIncrementalSchedulerUnavailableException;

/**
 * Production scheduler until durable incremental recovery is implemented.
 *
 * Validates and normalizes the requested ids, then refuses explicitly with a
 * sanitized exception. It never indexes, embeds, or generates synchronously,
 * and it never silently discards a product id.
 */
final class UnavailableIncrementalProductIndexScheduler implements IncrementalProductIndexSchedulerInterface
{
    public function schedule(int $productId): void
    {
        $this->assertValidIds([$productId]);
        throw new ProductIndexIncrementalSchedulerUnavailableException();
    }

    public function scheduleMany(array $productIds): void
    {
        $this->assertValidIds($productIds);
        $this->normalizeIds($productIds);
        throw new ProductIndexIncrementalSchedulerUnavailableException();
    }

    /**
     * @param array<mixed> $productIds
     */
    private function assertValidIds(array $productIds): void
    {
        foreach ($productIds as $productId) {
            if (!is_int($productId) || $productId < 1) {
                throw new InvalidProductIndexEntityIdsException();
            }
        }
    }

    /**
     * Deduplicates and sorts a validated id list.
     *
     * @param list<int> $productIds
     *
     * @return list<int>
     */
    private function normalizeIds(array $productIds): array
    {
        $ids = array_values(array_unique($productIds));
        sort($ids);

        return $ids;
    }
}
