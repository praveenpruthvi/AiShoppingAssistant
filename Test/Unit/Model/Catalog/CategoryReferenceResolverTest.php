<?php

declare(strict_types=1);

namespace Aavirbhava\AiShoppingAssistant\Test\Unit\Model\Catalog;

use Aavirbhava\AiShoppingAssistant\Api\Catalog\CategoryReferenceInterface;
use Aavirbhava\AiShoppingAssistant\Api\Store\StoreScopeInterface;
use Aavirbhava\AiShoppingAssistant\Model\Catalog\CategoryReferenceResolver;
use Magento\Catalog\Api\Data\CategoryInterface;
use Magento\Catalog\Model\ResourceModel\Category\Collection;
use Magento\Catalog\Model\ResourceModel\Category\CollectionFactory as CategoryCollectionFactory;
use Magento\Store\Model\Store;
use Magento\Store\Model\StoreManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(CategoryReferenceResolver::class)]
final class CategoryReferenceResolverTest extends TestCase
{
    /**
     * @param list<CategoryInterface> $requested
     * @param list<CategoryInterface> $ancestors
     */
    private function resolver(array $requested, array $ancestors): CategoryReferenceResolver
    {
        $factory = $this->createMock(CategoryCollectionFactory::class);
        $factory->method('create')
            ->willReturnCallback(
                function () use ($requested, $ancestors) {
                    static $call = 0;
                    $items = $call++ === 0 ? $requested : $ancestors;

                    $collection = $this->createMock(Collection::class);
                    $collection->method('setStoreId')->willReturnSelf();
                    $collection->method('addAttributeToSelect')->willReturnSelf();
                    $collection->method('addIdFilter')->willReturnSelf();
                    $collection->method('load')->willReturnSelf();
                    $collection->method('getItems')->willReturn($items);

                    return $collection;
                }
            );

        $store = $this->createMock(Store::class);
        $store->method('getRootCategoryId')->willReturn(2);

        $storeManager = $this->createMock(StoreManagerInterface::class);
        $storeManager->method('getStore')->willReturn($store);

        return new CategoryReferenceResolver($factory, $storeManager);
    }

    private function category(int $id, string $name, string $path, bool $active): CategoryInterface
    {
        $category = $this->createMock(CategoryInterface::class);
        $category->method('getId')->willReturn($id);
        $category->method('getName')->willReturn($name);
        $category->method('getPath')->willReturn($path);
        $category->method('getIsActive')->willReturn($active ? 1 : 0);

        return $category;
    }

    private function scope(): StoreScopeInterface
    {
        $scope = $this->createMock(StoreScopeInterface::class);
        $scope->method('storeId')->willReturn(1);
        $scope->method('websiteId')->willReturn(1);

        return $scope;
    }

    public function testResolvesReferencesWithStoreRelativePaths(): void
    {
        $accessories = $this->category(5, 'Accessories', '1/2/5', true);
        $bags = $this->category(7, 'Bags', '1/2/5/7', true);
        $laptopBags = $this->category(9, 'Laptop Bags', '1/2/5/7/9', true);

        $resolver = $this->resolver([$bags, $laptopBags], [$accessories]);

        $references = $resolver->resolve($this->scope(), [9, 7]);

        self::assertCount(2, $references);

        $first = $references[0];
        self::assertInstanceOf(CategoryReferenceInterface::class, $first);
        self::assertSame(7, $first->categoryId());
        self::assertSame('Bags', $first->name());
        self::assertSame('Accessories / Bags', $first->path());

        $second = $references[1];
        self::assertSame(9, $second->categoryId());
        self::assertSame('Laptop Bags', $second->name());
        self::assertSame('Accessories / Bags / Laptop Bags', $second->path());
    }

    public function testSkipsMissingAndInactiveCategories(): void
    {
        $inactive = $this->category(6, 'Old', '1/2/6', false);

        $resolver = $this->resolver([$inactive], []);

        $references = $resolver->resolve($this->scope(), [6, 999]);

        self::assertSame([], $references);
    }

    public function testEmptyInputReturnsEmptyResult(): void
    {
        $resolver = $this->resolver([], []);

        self::assertSame([], $resolver->resolve($this->scope(), []));
    }

    public function testDeduplicatesInput(): void
    {
        $bags = $this->category(7, 'Bags', '1/2/7', true);

        $resolver = $this->resolver([$bags], []);

        $references = $resolver->resolve($this->scope(), [7, 7, 7]);

        self::assertCount(1, $references);
        self::assertSame(7, $references[0]->categoryId());
    }
}
