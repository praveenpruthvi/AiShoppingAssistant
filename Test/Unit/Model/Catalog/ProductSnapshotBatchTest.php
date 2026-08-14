<?php

declare(strict_types=1);

namespace Aavirbhava\AiShoppingAssistant\Test\Unit\Model\Catalog;

use Aavirbhava\AiShoppingAssistant\Api\Catalog\ProductSnapshotBatchInterface;
use Aavirbhava\AiShoppingAssistant\Api\Catalog\ProductSnapshotInterface;
use Aavirbhava\AiShoppingAssistant\Model\Catalog\Exception\CatalogException;
use Aavirbhava\AiShoppingAssistant\Model\Catalog\ProductSnapshotBatch;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(ProductSnapshotBatch::class)]
final class ProductSnapshotBatchTest extends TestCase
{
    private function snapshot(int $entityId): ProductSnapshotInterface
    {
        $snapshot = $this->createMock(ProductSnapshotInterface::class);
        $snapshot->method('entityId')->willReturn($entityId);

        return $snapshot;
    }

    public function testExposesSnapshotsAndMissingIds(): void
    {
        $snapshots = [$this->snapshot(1), $this->snapshot(2)];

        $batch = new ProductSnapshotBatch($snapshots, [3, 4]);

        self::assertInstanceOf(ProductSnapshotBatchInterface::class, $batch);
        self::assertSame($snapshots, $batch->snapshots());
        self::assertSame([3, 4], $batch->missingProductIds());
    }

    public function testSupportsEmptyBatch(): void
    {
        $batch = new ProductSnapshotBatch([], []);
        self::assertSame([], $batch->snapshots());
        self::assertSame([], $batch->missingProductIds());
    }

    public function testRejectsInvalidSnapshotEntry(): void
    {
        $this->expectException(CatalogException::class);
        new ProductSnapshotBatch([new \stdClass()], []);
    }

    public function testRejectsNonPositiveMissingId(): void
    {
        $this->expectException(CatalogException::class);
        new ProductSnapshotBatch([], [0]);
    }
}