<?php

declare(strict_types=1);

namespace Aavirbhava\AiShoppingAssistant\Test\Unit\Model\Catalog;

use Aavirbhava\AiShoppingAssistant\Model\Catalog\Exception\CatalogException;
use Aavirbhava\AiShoppingAssistant\Model\Catalog\ProductSnapshot;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(ProductSnapshot::class)]
final class ProductSnapshotTest extends TestCase
{
    private CatalogSnapshotFactory $factory;

    protected function setUp(): void
    {
        $this->factory = new CatalogSnapshotFactory();
    }

    public function testAcceptsValidSnapshot(): void
    {
        $snapshot = $this->factory->create();

        self::assertSame(42, $snapshot->entityId());
        self::assertSame('SKU-42', $snapshot->sku());
        self::assertSame([2, 1], $snapshot->websiteIds());
        self::assertSame('2026-01-01T00:00:00+00:00', $snapshot->updatedAt());
        self::assertSame(4.5, $snapshot->ratingAverage());
        self::assertSame(12, $snapshot->reviewCount());
        self::assertSame(3.5, $snapshot->catalogRatingAverage());
    }

    public function testRejectsAnOutOfRangeRatingAverage(): void
    {
        $this->expectException(CatalogException::class);

        $this->factory->create(['ratingAverage' => 5.1]);
    }

    public function testRejectsAnOutOfRangeCatalogRatingAverage(): void
    {
        $this->expectException(CatalogException::class);

        $this->factory->create(['catalogRatingAverage' => -0.1]);
    }

    public function testRejectsANegativeReviewCount(): void
    {
        $this->expectException(CatalogException::class);

        $this->factory->create(['reviewCount' => -1]);
    }

    public function testRejectsNonPositiveEntityId(): void
    {
        $this->expectException(CatalogException::class);

        $this->factory->create(['entityId' => 0]);
    }

    public function testRejectsEmptySku(): void
    {
        $this->expectException(CatalogException::class);

        $this->factory->create(['sku' => '']);
    }

    public function testRejectsNonPositiveStoreId(): void
    {
        $this->expectException(CatalogException::class);

        $this->factory->create(['storeId' => 0]);
    }

    public function testRejectsEmptyWebsiteList(): void
    {
        $this->expectException(CatalogException::class);

        $this->factory->create(['websiteIds' => []]);
    }

    public function testRejectsNonPositiveWebsiteId(): void
    {
        $this->expectException(CatalogException::class);

        $this->factory->create(['websiteIds' => [1, 0]]);
    }

    public function testRejectsEmptyProductType(): void
    {
        $this->expectException(CatalogException::class);

        $this->factory->create(['productType' => '']);
    }
}
