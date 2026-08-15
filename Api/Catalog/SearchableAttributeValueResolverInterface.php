<?php

declare(strict_types=1);

namespace Aavirbhava\AiShoppingAssistant\Api\Catalog;

use Aavirbhava\AiShoppingAssistant\Api\Config\IndexingConfigInterface;
use Aavirbhava\AiShoppingAssistant\Api\Store\StoreScopeInterface;
use Magento\Catalog\Api\Data\ProductInterface;

/**
 * Resolves configured searchable attribute values for one product in a store scope.
 *
 * Option-based attributes (select, multiselect, boolean) are resolved to their
 * store-view option labels. Raw attribute data is untrusted input for the
 * normalization pipeline.
 */
interface SearchableAttributeValueResolverInterface
{
    /**
     * @return list<SearchableAttributeInterface> sorted by attribute code ascending
     */
    public function resolve(
        StoreScopeInterface $scope,
        IndexingConfigInterface $config,
        ProductInterface $product
    ): array;
}
