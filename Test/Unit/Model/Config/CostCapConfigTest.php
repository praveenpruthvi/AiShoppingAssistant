<?php

declare(strict_types=1);

namespace Aavirbhava\AiShoppingAssistant\Test\Unit\Model\Config;

use Aavirbhava\AiShoppingAssistant\Model\Config\CostCapConfig;
use Aavirbhava\AiShoppingAssistant\Model\Config\Source\CapPeriod;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(CostCapConfig::class)]
final class CostCapConfigTest extends TestCase
{
    public function testExposesEachFieldIndependently(): void
    {
        $config = new CostCapConfig(50.0, CapPeriod::WEEKLY, 80, true, ['ops@store.test']);

        self::assertSame(50.0, $config->capAmount());
        self::assertSame(CapPeriod::WEEKLY, $config->period());
        self::assertSame(80, $config->warningThresholdPercent());
        self::assertTrue($config->allowOverride());
        self::assertSame(['ops@store.test'], $config->notificationEmails());
    }

    public function testZeroCapAmountIsAllowedAndMeansNoCap(): void
    {
        $config = new CostCapConfig(0.0, CapPeriod::DAILY, 80, false, []);

        self::assertSame(0.0, $config->capAmount());
    }

    public function testRejectsANegativeCapAmount(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new CostCapConfig(-1.0, CapPeriod::DAILY, 80, false, []);
    }

    public function testRejectsAWarningThresholdOutOfRange(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new CostCapConfig(50.0, CapPeriod::DAILY, 100, false, []);
    }

    public function testRejectsAnEmptyNotificationEmail(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new CostCapConfig(50.0, CapPeriod::DAILY, 80, false, ['']);
    }
}
