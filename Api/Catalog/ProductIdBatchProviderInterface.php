<?php

declare(strict_types=1);

namespace Aavirbhava\AiShoppingAssistant\Api\Catalog;

use Aavirbhava\AiShoppingAssistant\Api\Store\StoreScopeInterface;

/**
 * Yields bounded, ascending lists of product entity ids for one store scope.
 *
 * Batching uses a keyset over the entity id column so the whole catalogue is
 * never held in memory at once. The caller supplies the batch size from the
 * validated indexing configuration.
 */
interface ProductIdBatchProviderInterface
{
    /**
     * @return iterable<list<int>> ascending, disjoint lists of positive product ids
     *
     * @throws \InvalidArgumentException when the batch size is out of the supported range.
     */
    public function batches(StoreScopeInterface $scope, int $batchSize): iterable;
}