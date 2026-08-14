<?php

declare(strict_types=1);

namespace Aavirbhava\AiShoppingAssistant\Test\Unit\Model\Indexing;

use Aavirbhava\AiShoppingAssistant\Api\Catalog\ProductEligibilityResultInterface;
use Aavirbhava\AiShoppingAssistant\Api\Indexing\RebuildMetricsInterface;
use Aavirbhava\AiShoppingAssistant\Model\Indexing\Exception\ProductIndexingException;
use Aavirbhava\AiShoppingAssistant\Model\Indexing\RebuildMetrics;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(RebuildMetrics::class)]
final class RebuildMetricsTest extends TestCase
{
    private function build(array $overrides = []): RebuildMetrics
    {
        $data = array_replace([
            'storesConsidered' => 1,
            'storesSkipped' => 0,
            'storesPrepared' => 1,
            'productIdsExamined' => 5,
            'snapshotsLoaded' => 4,
            'missingProducts' => 1,
            'eligibleDocuments' => 3,
            'ineligibleByReason' => [ProductEligibilityResultInterface::REASON_DISABLED => 1],
            'batchesWritten' => 1,
            'activated' => true,
            'durationSeconds' => 1.5,
        ], $overrides);

        return new RebuildMetrics(
            $data['storesConsidered'],
            $data['storesSkipped'],
            $data['storesPrepared'],
            $data['productIdsExamined'],
            $data['snapshotsLoaded'],
            $data['missingProducts'],
            $data['eligibleDocuments'],
            $data['ineligibleByReason'],
            $data['batchesWritten'],
            $data['activated'],
            $data['durationSeconds']
        );
    }

    public function testExposesMetrics(): void
    {
        $metrics = $this->build();

        self::assertInstanceOf(RebuildMetricsInterface::class, $metrics);
        self::assertSame(1, $metrics->storesConsidered());
        self::assertSame(0, $metrics->storesSkipped());
        self::assertSame(1, $metrics->storesPrepared());
        self::assertSame(5, $metrics->productIdsExamined());
        self::assertSame(4, $metrics->snapshotsLoaded());
        self::assertSame(1, $metrics->missingProducts());
        self::assertSame(3, $metrics->eligibleDocuments());
        self::assertSame([ProductEligibilityResultInterface::REASON_DISABLED => 1], $metrics->ineligibleByReason());
        self::assertSame(1, $metrics->batchesWritten());
        self::assertTrue($metrics->activated());
        self::assertSame(1.5, $metrics->durationSeconds());
    }

    public function testRejectsNegativeCounter(): void
    {
        $this->expectException(ProductIndexingException::class);
        $this->build(['eligibleDocuments' => -1]);
    }

    public function testRejectsUnknownIneligibleReason(): void
    {
        $this->expectException(ProductIndexingException::class);
        $this->build(['ineligibleByReason' => ['mystery' => 2]]);
    }

    public function testRejectsZeroCountForKnownReason(): void
    {
        $this->expectException(ProductIndexingException::class);
        $this->build(['ineligibleByReason' => [ProductEligibilityResultInterface::REASON_DISABLED => 0]]);
    }

    public function testRejectsNonIntCount(): void
    {
        $this->expectException(ProductIndexingException::class);
        $this->build(['ineligibleByReason' => [ProductEligibilityResultInterface::REASON_DISABLED => '2']]);
    }

    public function testRejectsNegativeDuration(): void
    {
        $this->expectException(ProductIndexingException::class);
        $this->build(['durationSeconds' => -0.5]);
    }
}
