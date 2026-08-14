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
