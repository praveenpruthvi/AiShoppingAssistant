<?php

declare(strict_types=1);

namespace Aavirbhava\AiShoppingAssistant\Model\Diagnostics;

use Aavirbhava\AiShoppingAssistant\Api\Store\StoreScopeInterface;
use Magento\Catalog\Model\Product\Visibility;
use Magento\Catalog\Model\ResourceModel\Product\CollectionFactory as ProductCollectionFactory;
use Magento\CatalogInventory\Helper\Stock as StockHelper;
use Magento\Catalog\Model\Product\Attribute\Source\Status;

/**
 * The real, salable/visible/enabled SKU set for one store — the "should be
 * indexed" side of the index-coverage diagnostic (Console\Command\
 * IndexCoverageCommand, Task 23).
 *
 * Deliberately reuses Magento's own standard listing filters rather than a
 * hand-rolled MSI-aware query: enabled status, search-visible (matches
 * ProductIndexEligibilityPolicy's own visibility check), and
 * CatalogInventory\Helper\Stock::addIsInStockFilterToCollection() — the same
 * helper category/search listings use, which also respects the merchant's
 * own "Display Out of Stock Products" setting, so this reports what a real
 * shopper would actually see as salable on this store, not a stricter
 * stock-table-only definition. Simple and fast by design (this is a
 * diagnostic, not a full reconciliation tool): a single-source (default
 * stock) query, not a full multi-source-inventory reconciliation.
 *
 * Not final (unlike most of this module's classes): IndexCoverageChecker
 * depends on this concrete class directly rather than an Api interface —
 * a diagnostic-only feature with a single implementation didn't seem to
 * warrant one — so it needs to stay mockable in IndexCoverageChecker's
 * own unit tests.
 */
class CatalogSkuProvider
{
    public function __construct(
        private readonly ProductCollectionFactory $productCollectionFactory,
        private readonly StockHelper $stockHelper
    ) {
    }

    /**
     * @return list<string>
     */
    public function salableVisibleEnabledSkus(StoreScopeInterface $scope): array
    {
        $collection = $this->productCollectionFactory->create();
        $collection->addAttributeToSelect(['sku']);
        $collection->setStoreId($scope->storeId());
        $collection->addWebsiteFilter($scope->websiteId());
        $collection->addAttributeToFilter('status', Status::STATUS_ENABLED);
        $collection->addAttributeToFilter(
            'visibility',
            ['in' => [Visibility::VISIBILITY_IN_SEARCH, Visibility::VISIBILITY_BOTH]]
        );
        $this->stockHelper->addIsInStockFilterToCollection($collection);

        $skus = $collection->getColumnValues('sku');

        return array_values(array_unique(array_map('strval', $skus)));
    }
}
