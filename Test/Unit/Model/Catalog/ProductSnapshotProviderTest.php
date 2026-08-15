<?php

declare(strict_types=1);

namespace Aavirbhava\AiShoppingAssistant\Test\Unit\Model\Catalog;

use Aavirbhava\AiShoppingAssistant\Api\Catalog\CategoryReferenceInterface;
use Aavirbhava\AiShoppingAssistant\Api\Catalog\CategoryReferenceResolverInterface;
use Aavirbhava\AiShoppingAssistant\Api\Catalog\ProductSnapshotInterface;
use Aavirbhava\AiShoppingAssistant\Api\Catalog\SearchableAttributeInterface;
use Aavirbhava\AiShoppingAssistant\Api\Catalog\SearchableAttributeValueResolverInterface;
use Aavirbhava\AiShoppingAssistant\Api\Config\IndexingConfigInterface;
use Aavirbhava\AiShoppingAssistant\Api\Store\StoreScopeInterface;
use Aavirbhava\AiShoppingAssistant\Model\Catalog\ProductSnapshotProvider;
use Magento\Catalog\Model\Product;
use Magento\Catalog\Model\ResourceModel\Product\Collection;
use Magento\Catalog\Model\ResourceModel\Product\CollectionFactory as ProductCollectionFactory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(ProductSnapshotProvider::class)]
final class ProductSnapshotProviderTest extends TestCase
{
    public function testLoadsSnapshotsWithCategoryAndAttributeResolution(): void
    {
        $productA = $this->createMock(Product::class);
        $productA->method('getId')->willReturn(10);
        $productA->method('getSku')->willReturn('SKU-A');
        $productA->method('getTypeId')->willReturn('simple');
        $productA->method('getName')->willReturn('Widget');
        $productA->method('getStatus')->willReturn(1);
        $productA->method('getVisibility')->willReturn(4);
        $productA->method('getUpdatedAt')->willReturn('2026-01-01 00:00:00');
        $productA->method('getData')
            ->willReturnCallback(
                static fn (string $key): mixed => match ($key) {
                    'short_description' => 'Short',
                    'description' => 'Long',
                    'category_ids' => [5],
                    default => null,
                }
            );

        $collection = $this->createMock(Collection::class);
        $collection->method('setStoreId')->willReturnSelf();
        $collection->method('addWebsiteFilter')->willReturnSelf();
        $collection->method('addIdFilter')->willReturnSelf();
        $collection->method('addAttributeToSelect')->willReturnSelf();
        $collection->method('addCategoryIds')->willReturnSelf();
        $collection->method('setOrder')->willReturnSelf();
        $collection->method('setPageSize')->willReturnSelf();
        $collection->method('setCurPage')->willReturnSelf();
        $collection->method('load')->willReturnSelf();
        $collection->method('getItems')->willReturn([$productA]);

        $factory = $this->createMock(ProductCollectionFactory::class);
        $factory->method('create')->willReturn($collection);

        $category = $this->createMock(CategoryReferenceInterface::class);
        $category->method('categoryId')->willReturn(5);
        $category->method('name')->willReturn('Gadgets');
        $category->method('path')->willReturn('Gadgets');

        $categoryResolver = $this->createMock(CategoryReferenceResolverInterface::class);
        $categoryResolver->method('resolve')->willReturn([$category]);

        $attribute = $this->createMock(SearchableAttributeInterface::class);
        $attribute->method('code')->willReturn('color');
        $attribute->method('label')->willReturn('Color');
        $attribute->method('values')->willReturn(['Blue']);

        $attributeResolver = $this->createMock(SearchableAttributeValueResolverInterface::class);
        $attributeResolver->method('resolve')->willReturn([$attribute]);

        $provider = new ProductSnapshotProvider($factory, $categoryResolver, $attributeResolver);

        $config = $this->createMock(IndexingConfigInterface::class);
        $config->method('includeShortDescription')->willReturn(true);
        $config->method('includeLongDescription')->willReturn(true);
        $config->method('searchableAttributeCodes')->willReturn(['color']);
        $config->method('maxAttributeValuesPerProduct')->willReturn(100);

        $scope = $this->createMock(StoreScopeInterface::class);
        $scope->method('storeId')->willReturn(2);
        $scope->method('websiteId')->willReturn(1);

        $batch = $provider->load($scope, $config, [10, 99]);

        self::assertCount(1, $batch->snapshots());
        self::assertSame([99], $batch->missingProductIds());

        $snapshot = $batch->snapshots()[0];
        self::assertInstanceOf(ProductSnapshotInterface::class, $snapshot);
        self::assertSame(10, $snapshot->entityId());
        self::assertSame('SKU-A', $snapshot->sku());
        self::assertSame(2, $snapshot->storeId());
        self::assertSame([1], $snapshot->websiteIds());
        self::assertSame('simple', $snapshot->productType());
        self::assertSame('Widget', $snapshot->name());
        self::assertSame('Short', $snapshot->shortDescription());
        self::assertSame('Long', $snapshot->longDescription());
        self::assertTrue($snapshot->isEnabled());
        self::assertSame(4, $snapshot->visibility());
        self::assertCount(1, $snapshot->categories());
        self::assertSame([$attribute], $snapshot->attributes());
        self::assertSame('2026-01-01 00:00:00', $snapshot->updatedAt());
    }

    public function testEmptyInputReturnsEmptyBatch(): void
    {
        $factory = $this->createMock(ProductCollectionFactory::class);
        $categoryResolver = $this->createMock(CategoryReferenceResolverInterface::class);
        $attributeResolver = $this->createMock(SearchableAttributeValueResolverInterface::class);

        $provider = new ProductSnapshotProvider($factory, $categoryResolver, $attributeResolver);

        $config = $this->createMock(IndexingConfigInterface::class);
        $scope = $this->createMock(StoreScopeInterface::class);

        $batch = $provider->load($scope, $config, []);
        self::assertSame([], $batch->snapshots());
        self::assertSame([], $batch->missingProductIds());
    }

    public function testDescriptionFlagsControlIncludedFields(): void
    {
        $product = $this->createMock(Product::class);
        $product->method('getId')->willReturn(10);
        $product->method('getSku')->willReturn('SKU-A');
        $product->method('getTypeId')->willReturn('simple');
        $product->method('getName')->willReturn('Widget');
        $product->method('getStatus')->willReturn(1);
        $product->method('getVisibility')->willReturn(4);
        $product->method('getUpdatedAt')->willReturn(null);
        $product->method('getData')
            ->willReturnCallback(
                static fn (string $key): mixed => $key === 'category_ids' ? [] : null
            );

        $collection = $this->createMock(Collection::class);
        $collection->method('setStoreId')->willReturnSelf();
        $collection->method('addWebsiteFilter')->willReturnSelf();
        $collection->method('addIdFilter')->willReturnSelf();
        $collection->method('addAttributeToSelect')->willReturnSelf();
        $collection->method('addCategoryIds')->willReturnSelf();
        $collection->method('setOrder')->willReturnSelf();
        $collection->method('setPageSize')->willReturnSelf();
        $collection->method('setCurPage')->willReturnSelf();
        $collection->method('load')->willReturnSelf();
        $collection->method('getItems')->willReturn([$product]);

        $factory = $this->createMock(ProductCollectionFactory::class);
        $factory->method('create')->willReturn($collection);

        $categoryResolver = $this->createMock(CategoryReferenceResolverInterface::class);
        $categoryResolver->method('resolve')->willReturn([]);
        $attributeResolver = $this->createMock(SearchableAttributeValueResolverInterface::class);
        $attributeResolver->method('resolve')->willReturn([]);

        $provider = new ProductSnapshotProvider($factory, $categoryResolver, $attributeResolver);

        $config = $this->createMock(IndexingConfigInterface::class);
        $config->method('includeShortDescription')->willReturn(false);
        $config->method('includeLongDescription')->willReturn(false);
        $config->method('searchableAttributeCodes')->willReturn([]);
        $config->method('maxAttributeValuesPerProduct')->willReturn(100);

        $scope = $this->createMock(StoreScopeInterface::class);
        $scope->method('storeId')->willReturn(2);
        $scope->method('websiteId')->willReturn(1);

        $batch = $provider->load($scope, $config, [10]);

        $snapshot = $batch->snapshots()[0];
        self::assertSame('', $snapshot->shortDescription());
        self::assertSame('', $snapshot->longDescription());
        self::assertNull($snapshot->updatedAt());
    }
}
