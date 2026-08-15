<?php

declare(strict_types=1);

namespace Aavirbhava\AiShoppingAssistant\Api\Indexing;

interface IncrementalProductReconciliationInterface
{
    /**
     * Schedule one bounded pass of product ids for idempotent incremental reconciliation.
     *
     * @return int number of product ids scheduled
     *
     * @throws \Aavirbhava\AiShoppingAssistant\Model\Indexing\Exception\ProductIndexingException
     */
    public function reconcile(int $limit = 50): int;

    /**
     * Request a fresh bounded pass from the start of the catalogue.
     *
     * @throws \Aavirbhava\AiShoppingAssistant\Model\Indexing\Exception\ProductIndexingException
     */
    public function requestFullPass(): void;
}
