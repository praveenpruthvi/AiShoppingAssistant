<?php

declare(strict_types=1);

namespace Aavirbhava\AiShoppingAssistant\Test\Unit\Model\CostCap;

use Aavirbhava\AiShoppingAssistant\Model\CostCap\CostCapThreshold;
use Aavirbhava\AiShoppingAssistant\Model\CostCap\CostUsageSnapshot;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(CostUsageSnapshot::class)]
final class CostUsageSnapshotTest extends TestCase
{
    public function testExposesEachFieldIndependently(): void
    {
        $snapshot = new CostUsageSnapshot(true, 12.5, CostCapThreshold::WARNING);

        self::assertTrue($snapshot->exists());
        self::assertSame(12.5, $snapshot->costAmount());
        self::assertSame(CostCapThreshold::WARNING, $snapshot->notifiedThresholdRank());
    }

    public function testRejectsANegativeCostAmount(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new CostUsageSnapshot(true, -1.0, CostCapThreshold::NONE);
    }

    public function testRejectsAnOutOfRangeThresholdRank(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new CostUsageSnapshot(true, 0.0, 3);
    }
}
