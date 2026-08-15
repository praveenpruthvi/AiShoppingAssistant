<?php

declare(strict_types=1);

namespace Aavirbhava\AiShoppingAssistant\Api\Catalog;

use Aavirbhava\AiShoppingAssistant\Api\Config\IndexingConfigInterface;
use Aavirbhava\AiShoppingAssistant\Api\Store\StoreScopeInterface;

/**
 * Loads raw catalogue snapshots for one store scope without N+1 queries.
 *
 * The returned data is untrusted input for the normalization pipeline. Price,
 * stock, salability, URLs, and customer-group data are deliberately excluded.
 */
interface ProductSnapshotProviderInterface
{
    /**
     * @param list<int> $productIds positive entity ids
     *
     * @throws \Aavirbhava\AiShoppingAssistant\Model\Catalog\Exception\CatalogException
     */
    public function load(
        StoreScopeInterface $scope,
        IndexingConfigInterface $config,
        array $productIds
    ): ProductSnapshotBatchInterface;
}
