<?php

declare(strict_types=1);

namespace Aavirbhava\AiShoppingAssistant\Test\Unit\Model\Diagnostics;

use Aavirbhava\AiShoppingAssistant\Api\Store\StoreScopeInterface;
use Aavirbhava\AiShoppingAssistant\Model\Diagnostics\CatalogSkuProvider;
use Magento\Catalog\Model\Product\Attribute\Source\Status;
use Magento\Catalog\Model\Product\Visibility;
use Magento\Catalog\Model\ResourceModel\Product\Collection;
use Magento\Catalog\Model\ResourceModel\Product\CollectionFactory as ProductCollectionFactory;
use Magento\CatalogInventory\Helper\Stock as StockHelper;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(CatalogSkuProvider::class)]
final class CatalogSkuProviderTest extends TestCase
{
    private function scope(): StoreScopeInterface
    {
        $scope = $this->createMock(StoreScopeInterface::class);
        $scope->method('storeId')->willReturn(1);
        $scope->method('websiteId')->willReturn(1);

        return $scope;
    }

    public function testAppliesTheStandardEnabledVisibleWebsiteFiltersAndTheStockHelper(): void
    {
        $collection = $this->createMock(Collection::class);
        $collection->expects(self::once())->method('setStoreId')->with(1);
        $collection->expects(self::once())->method('addWebsiteFilter')->with(1);
        $collection->expects(self::exactly(2))->method('addAttributeToFilter')
            ->willReturnCallback(function (string $attribute, $condition) use ($collection) {
                if ($attribute === 'status') {
                    self::assertSame(Status::STATUS_ENABLED, $condition);
                } elseif ($attribute === 'visibility') {
                    self::assertSame(
                        ['in' => [Visibility::VISIBILITY_IN_SEARCH, Visibility::VISIBILITY_BOTH]],
                        $condition
                    );
                } else {
                    self::fail('Unexpected attribute filter: ' . $attribute);
                }

                return $collection;
            });
        $collection->method('getColumnValues')->with('sku')->willReturn(['SKU-1', 'SKU-2']);

        $stockHelper = $this->createMock(StockHelper::class);
        $stockHelper->expects(self::once())->method('addIsInStockFilterToCollection')->with($collection);

        $factory = $this->createMock(ProductCollectionFactory::class);
        $factory->method('create')->willReturn($collection);

        $provider = new CatalogSkuProvider($factory, $stockHelper);

        self::assertSame(['SKU-1', 'SKU-2'], $provider->salableVisibleEnabledSkus($this->scope()));
    }

    public function testDeduplicatesSkusReturnedByTheCollection(): void
    {
        $collection = $this->createMock(Collection::class);
        $collection->method('getColumnValues')->willReturn(['SKU-1', 'SKU-1', 'SKU-2']);

        $stockHelper = $this->createMock(StockHelper::class);

        $factory = $this->createMock(ProductCollectionFactory::class);
        $factory->method('create')->willReturn($collection);

        $provider = new CatalogSkuProvider($factory, $stockHelper);

        self::assertSame(['SKU-1', 'SKU-2'], $provider->salableVisibleEnabledSkus($this->scope()));
    }
}
