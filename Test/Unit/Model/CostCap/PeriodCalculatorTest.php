<?php

declare(strict_types=1);

namespace Aavirbhava\AiShoppingAssistant\Test\Unit\Model\CostCap;

use Aavirbhava\AiShoppingAssistant\Model\Config\Source\CapPeriod;
use Aavirbhava\AiShoppingAssistant\Model\CostCap\PeriodCalculator;
use DateTimeImmutable;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(PeriodCalculator::class)]
final class PeriodCalculatorTest extends TestCase
{
    public function testDailyPeriodKeyIsTheCalendarDate(): void
    {
        $calculator = new PeriodCalculator();

        self::assertSame('2026-08-21', $calculator->periodKey(CapPeriod::DAILY, new DateTimeImmutable('2026-08-21 23:59:59')));
    }

    public function testDailyPeriodRollsOverAtMidnight(): void
    {
        $calculator = new PeriodCalculator();

        self::assertNotSame(
            $calculator->periodKey(CapPeriod::DAILY, new DateTimeImmutable('2026-08-21 23:59:59')),
            $calculator->periodKey(CapPeriod::DAILY, new DateTimeImmutable('2026-08-22 00:00:00'))
        );
    }

    /**
     * 2026-08-21 is a Friday — the Monday-start ISO week it falls in
     * begins 2026-08-17.
     */
    public function testWeeklyPeriodKeyIsTheMondayOfThatIsoWeek(): void
    {
        $calculator = new PeriodCalculator();

        self::assertSame('2026-08-17', $calculator->periodKey(CapPeriod::WEEKLY, new DateTimeImmutable('2026-08-21 12:00:00')));
    }

    public function testWeeklyPeriodKeyOnAMondayIsThatSameDay(): void
    {
        $calculator = new PeriodCalculator();

        self::assertSame('2026-08-17', $calculator->periodKey(CapPeriod::WEEKLY, new DateTimeImmutable('2026-08-17 00:00:01')));
    }

    public function testWeeklyPeriodRollsOverAtTheNextMonday(): void
    {
        $calculator = new PeriodCalculator();

        self::assertNotSame(
            $calculator->periodKey(CapPeriod::WEEKLY, new DateTimeImmutable('2026-08-23 23:59:59')),
            $calculator->periodKey(CapPeriod::WEEKLY, new DateTimeImmutable('2026-08-24 00:00:00'))
        );
    }

    public function testMonthlyPeriodKeyIsTheFirstOfTheMonth(): void
    {
        $calculator = new PeriodCalculator();

        self::assertSame('2026-08-01', $calculator->periodKey(CapPeriod::MONTHLY, new DateTimeImmutable('2026-08-21 12:00:00')));
    }

    public function testMonthlyPeriodRollsOverAtTheNextMonth(): void
    {
        $calculator = new PeriodCalculator();

        self::assertNotSame(
            $calculator->periodKey(CapPeriod::MONTHLY, new DateTimeImmutable('2026-08-31 23:59:59')),
            $calculator->periodKey(CapPeriod::MONTHLY, new DateTimeImmutable('2026-09-01 00:00:00'))
        );
    }

    public function testRejectsAnUnknownPeriodType(): void
    {
        $calculator = new PeriodCalculator();

        $this->expectException(InvalidArgumentException::class);

        $calculator->periodKey('fortnightly', new DateTimeImmutable('2026-08-21'));
    }
}
