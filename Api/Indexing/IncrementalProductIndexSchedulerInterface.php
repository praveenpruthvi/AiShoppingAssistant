<?php

declare(strict_types=1);

namespace Aavirbhava\AiShoppingAssistant\Api\Indexing;

/**
 * Schedules product entity ids for incremental assistant-index updates.
 *
 * Implementations must never index, embed, or generate anything synchronously
 * inside the calling request. They publish identifiers for asynchronous
 * processing only. The queue publisher transport exists, but production DI
 * remains on the unavailable fail-closed scheduler until durable recovery and
 * reconciliation are implemented.
 */
interface IncrementalProductIndexSchedulerInterface
{
    /**
     * Schedules a single positive product entity id.
     *
     * @throws \Aavirbhava\AiShoppingAssistant\Model\Indexing\Exception\ProductIndexingException
     */
    public function schedule(int $productId): void;

    /**
     * Schedules a list of positive product entity ids.
     *
     * The list is deduplicated and sorted before scheduling.
     *
     * @param list<int> $productIds
     *
     * @throws \Aavirbhava\AiShoppingAssistant\Model\Indexing\Exception\ProductIndexingException
     */
    public function scheduleMany(array $productIds): void;
}
