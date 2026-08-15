<?php

declare(strict_types=1);

namespace Aavirbhava\AiShoppingAssistant\Model\Indexing\Queue;

use Aavirbhava\AiShoppingAssistant\Api\Indexing\IncrementalProductIndexSchedulerInterface;
use Aavirbhava\AiShoppingAssistant\Api\Indexing\IncrementalWorkLedgerInterface;
use Aavirbhava\AiShoppingAssistant\Model\Indexing\Exception\InvalidProductIndexEntityIdsException;

/**
 * Staged durable scheduler. Not the production DI preference until 2C2B2B.
 */
final class DurableIncrementalProductIndexScheduler implements IncrementalProductIndexSchedulerInterface
{
    public function __construct(
        private readonly IncrementalWorkLedgerInterface $ledger,
        private readonly MagentoIncrementalProductIndexScheduler $queuePublisher
    ) {
    }

    public function schedule(int $productId): void
    {
        $this->scheduleMany([$productId]);
    }

    public function scheduleMany(array $productIds): void
    {
        $ids = $this->normalizeIds($productIds);
        $this->ledger->recordProductChanges($ids);

        foreach ($ids as $productId) {
            $this->queuePublisher->schedule($productId);
        }
    }

    /**
     * @param array<mixed> $productIds
     *
     * @return list<int>
     */
    private function normalizeIds(array $productIds): array
    {
        if ($productIds === []) {
            throw new InvalidProductIndexEntityIdsException();
        }

        $ids = [];
        foreach ($productIds as $productId) {
            if (!is_int($productId) || $productId < 1) {
                throw new InvalidProductIndexEntityIdsException();
            }
            $ids[] = $productId;
        }

        $ids = array_values(array_unique($ids));
        sort($ids);

        return $ids;
    }
}
