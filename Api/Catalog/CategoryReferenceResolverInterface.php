<?php

declare(strict_types=1);

namespace Aavirbhava\AiShoppingAssistant\Api\Catalog;

use Aavirbhava\AiShoppingAssistant\Api\Store\StoreScopeInterface;

/**
 * Resolves category references for one store scope from a batch of ids.
 *
 * References carry the store-view category name and a store-relative category
 * path. Missing, inactive, root, and global categories are excluded.
 */
interface CategoryReferenceResolverInterface
{
    /**
     * @param list<int> $categoryIds
     *
     * @return list<CategoryReferenceInterface> sorted by category id ascending
     */
    public function resolve(StoreScopeInterface $scope, array $categoryIds): array;
}