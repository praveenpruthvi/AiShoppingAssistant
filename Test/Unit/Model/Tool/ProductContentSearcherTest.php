<?php

declare(strict_types=1);

namespace Aavirbhava\AiShoppingAssistant\Test\Unit\Model\Tool;

use Aavirbhava\AiShoppingAssistant\Model\Tool\ContentSearchTextUtility;
use Aavirbhava\AiShoppingAssistant\Model\Tool\ProductContentSearcher;
use ArrayIterator;
use IteratorAggregate;
use Magento\Catalog\Model\ResourceModel\Category\CollectionFactory as CategoryCollectionFactory;
use Magento\Catalog\Model\ResourceModel\Product\CollectionFactory as ProductCollectionFactory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Traversable;

#[CoversClass(ProductContentSearcher::class)]
final class ProductContentSearcherTest extends TestCase
{
    public function testReturnsSkusFromTheTextMatch(): void
    {
        $textCollection = new FakeProductRowCollection([new FakeProductRow('SKU-1'), new FakeProductRow('SKU-2')]);
        $searcher = $this->searcher($textCollection, new FakeCategoryIdCollection([]));

        $skus = $searcher->searchSkus(1, 'duffle bag', 5);

        self::assertSame(['SKU-1', 'SKU-2'], $skus);
        self::assertSame(1, $textCollection->storeFilterAppliedFor);
    }

    public function testDeduplicatesSkusFoundByBothTextAndCategory(): void
    {
        $textCollection = new FakeProductRowCollection([new FakeProductRow('SKU-1')]);
        $categoryCollection = new FakeCategoryIdCollection([10]);
        $byCategoryCollection = new FakeProductRowCollection([new FakeProductRow('SKU-1'), new FakeProductRow('SKU-3')]);

        $searcher = $this->searcher($textCollection, $categoryCollection, $byCategoryCollection);

        $skus = $searcher->searchSkus(1, 'bag', 5);

        self::assertSame(['SKU-1', 'SKU-3'], $skus);
    }

    public function testSkipsTheCategoryLookupOnceTheLimitIsAlreadyReached(): void
    {
        $textCollection = new FakeProductRowCollection([new FakeProductRow('SKU-1'), new FakeProductRow('SKU-2')]);
        $categoryFactory = $this->createMock(CategoryCollectionFactory::class);
        $categoryFactory->expects(self::never())->method('create');

        $productFactory = $this->createMock(ProductCollectionFactory::class);
        $productFactory->method('create')->willReturn($textCollection);

        $searcher = new ProductContentSearcher($productFactory, $categoryFactory, new ContentSearchTextUtility());

        self::assertSame(['SKU-1', 'SKU-2'], $searcher->searchSkus(1, 'bag', 2));
    }

    public function testCapsTheMergedResultAtTheLimit(): void
    {
        $textCollection = new FakeProductRowCollection([
            new FakeProductRow('SKU-1'),
            new FakeProductRow('SKU-2'),
            new FakeProductRow('SKU-3'),
        ]);
        $searcher = $this->searcher($textCollection, new FakeCategoryIdCollection([]));

        self::assertSame(['SKU-1', 'SKU-2'], $searcher->searchSkus(1, 'bag', 2));
    }

    private function searcher(
        FakeProductRowCollection $textCollection,
        FakeCategoryIdCollection $categoryCollection,
        ?FakeProductRowCollection $byCategoryCollection = null
    ): ProductContentSearcher {
        $collections = [$textCollection];
        if ($byCategoryCollection !== null) {
            $collections[] = $byCategoryCollection;
        }

        $productFactory = $this->createMock(ProductCollectionFactory::class);
        $productFactory->method('create')->willReturnCallback(
            static function () use (&$collections): FakeProductRowCollection {
                return array_shift($collections) ?? new FakeProductRowCollection([]);
            }
        );

        $categoryFactory = $this->createMock(CategoryCollectionFactory::class);
        $categoryFactory->method('create')->willReturn($categoryCollection);

        return new ProductContentSearcher($productFactory, $categoryFactory, new ContentSearchTextUtility());
    }
}

final class FakeProductRow
{
    public function __construct(private readonly string $sku)
    {
    }

    public function getSku(): string
    {
        return $this->sku;
    }
}

final class FakeProductRowCollection implements IteratorAggregate
{
    public ?int $storeFilterAppliedFor = null;

    /**
     * @param list<FakeProductRow> $rows
     */
    public function __construct(private readonly array $rows)
    {
    }

    public function addAttributeToSelect(array $attributes): self
    {
        return $this;
    }

    public function addStoreFilter(int $storeId): self
    {
        $this->storeFilterAppliedFor = $storeId;

        return $this;
    }

    public function addAttributeToFilter(array $conditions): self
    {
        return $this;
    }

    public function addCategoriesFilter(array $filter): self
    {
        return $this;
    }

    public function setPageSize(int $size): self
    {
        return $this;
    }

    public function setCurPage(int $page): self
    {
        return $this;
    }

    public function getIterator(): Traversable
    {
        return new ArrayIterator($this->rows);
    }
}

final class FakeCategoryIdCollection
{
    /**
     * @param list<int> $ids
     */
    public function __construct(private readonly array $ids)
    {
    }

    public function addAttributeToSelect(string $attribute): self
    {
        return $this;
    }

    public function addAttributeToFilter(string $attribute, array $condition): self
    {
        return $this;
    }

    public function setPageSize(int $size): self
    {
        return $this;
    }

    /**
     * @return list<int>
     */
    public function getAllIds(): array
    {
        return $this->ids;
    }
}
