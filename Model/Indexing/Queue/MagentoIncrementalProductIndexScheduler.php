<?php

declare(strict_types=1);

namespace Aavirbhava\AiShoppingAssistant\Model\Indexing\Queue;

use Aavirbhava\AiShoppingAssistant\Api\Indexing\IncrementalProductIndexSchedulerInterface;
use Aavirbhava\AiShoppingAssistant\Model\Indexing\Exception\IncrementalQueuePublishFailedException;
use Aavirbhava\AiShoppingAssistant\Model\Indexing\Exception\InvalidProductIndexEntityIdsException;
use Magento\Framework\MessageQueue\PublisherInterface;

/**
 * Publishes product ids to Magento's asynchronous incremental-index queue.
 */
final class MagentoIncrementalProductIndexScheduler implements IncrementalProductIndexSchedulerInterface
{
    public function __construct(
        private readonly PublisherInterface $publisher
    ) {
    }

    public function schedule(int $productId): void
    {
        $this->scheduleMany([$productId]);
    }

    public function scheduleMany(array $productIds): void
    {
        $this->assertValidIds($productIds);

        foreach ($this->normalizeIds($productIds) as $productId) {
            $this->publish($productId);
        }
    }

    /**
     * @param array<mixed> $productIds
     */
    private function assertValidIds(array $productIds): void
    {
        if ($productIds === []) {
            throw new InvalidProductIndexEntityIdsException();
        }

        foreach ($productIds as $productId) {
            if (!is_int($productId) || $productId < 1) {
                throw new InvalidProductIndexEntityIdsException();
            }
        }
    }

    /**
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

    private function publish(int $productId): void
    {
        try {
            $this->publisher->publish(IncrementalProductIndexQueue::TOPIC, (string)$productId);
        } catch (\Throwable $throwable) {
            throw new IncrementalQueuePublishFailedException();
        }
    }
}
