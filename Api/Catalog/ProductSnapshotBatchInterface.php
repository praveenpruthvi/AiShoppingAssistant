<?php

declare(strict_types=1);

namespace Aavirbhava\AiShoppingAssistant\Api\Catalog;

/**
 * Immutable result of loading one batch of product snapshots for a store scope.
 */
interface ProductSnapshotBatchInterface
{
    /**
     * Loaded snapshots in ascending entity-id order.
     *
     * @return list<ProductSnapshotInterface>
     */
    public function snapshots(): array;

    /**
     * Product ids that were requested but could not be loaded for the scope.
     * These products are missing, invisible, disabled, or not assigned to the
     * website. Missing products are never treated as an error.
     *
     * @return list<int>
     */
    public function missingProductIds(): array;
}