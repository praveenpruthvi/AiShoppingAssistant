<?php

declare(strict_types=1);

namespace Aavirbhava\AiShoppingAssistant\Test\Unit\Model\Diagnostics;

use Aavirbhava\AiShoppingAssistant\Model\Diagnostics\IndexCoverageReport;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(IndexCoverageReport::class)]
final class IndexCoverageReportTest extends TestCase
{
    public function testIndexUnavailableWhenIndexCountIsNull(): void
    {
        $report = new IndexCoverageReport(1, 'default', 10, null, [], []);

        self::assertFalse($report->indexAvailable());
        self::assertFalse($report->isFullyCovered());
    }

    public function testFullyCoveredWhenBothDiffsAreEmptyAndIndexIsAvailable(): void
    {
        $report = new IndexCoverageReport(1, 'default', 10, 10, [], []);

        self::assertTrue($report->indexAvailable());
        self::assertTrue($report->isFullyCovered());
    }

    public function testNotFullyCoveredWhenSomeCatalogSkusAreMissingFromTheIndex(): void
    {
        $report = new IndexCoverageReport(1, 'default', 10, 9, ['SKU-1'], []);

        self::assertFalse($report->isFullyCovered());
    }

    public function testNotFullyCoveredWhenTheIndexHasOrphanedDocuments(): void
    {
        $report = new IndexCoverageReport(1, 'default', 9, 10, [], ['SKU-1']);

        self::assertFalse($report->isFullyCovered());
    }
}
