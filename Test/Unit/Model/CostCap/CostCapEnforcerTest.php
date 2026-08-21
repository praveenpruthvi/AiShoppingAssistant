<?php

declare(strict_types=1);

namespace Aavirbhava\AiShoppingAssistant\Test\Unit\Model\CostCap;

use Aavirbhava\AiShoppingAssistant\Api\Config\ConfigurationReaderInterface;
use Aavirbhava\AiShoppingAssistant\Api\Config\CostCapConfigInterface;
use Aavirbhava\AiShoppingAssistant\Api\CostCap\CostUsageTrackerInterface;
use Aavirbhava\AiShoppingAssistant\Model\Config\Source\CapPeriod;
use Aavirbhava\AiShoppingAssistant\Model\CostCap\CostCapEnforcer;
use Aavirbhava\AiShoppingAssistant\Model\CostCap\CostUsageSnapshot;
use Aavirbhava\AiShoppingAssistant\Model\Indexing\Clock\ClockInterface;
use Magento\Store\Api\Data\StoreInterface;
use Magento\Store\Model\StoreManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Proves the render-time cap check (1) blocks only when cap is configured,
 * reached, AND override is off, (2) never blocks when override is
 * allowed even with the cap reached — override is a real continue-serving
 * switch, not just a suppressed-email flag, (3) fails OPEN (returns
 * false, never throws) on any error, the opposite fail-safe direction
 * from ChatWidget's own isAssistantEnabled() check.
 */
#[CoversClass(CostCapEnforcer::class)]
final class CostCapEnforcerTest extends TestCase
{
    private const STORE_ID = 3;

    public function testDoesNotBlockWhenNoCapIsConfigured(): void
    {
        $enforcer = $this->enforcer($this->costCapConfig(0.0, false), 999.0);

        self::assertFalse($enforcer->isBlocking());
    }

    public function testDoesNotBlockWhenCapIsConfiguredButNotYetReached(): void
    {
        $enforcer = $this->enforcer($this->costCapConfig(50.0, false), 10.0);

        self::assertFalse($enforcer->isBlocking());
    }

    public function testBlocksWhenCapIsReachedAndOverrideIsNotAllowed(): void
    {
        $enforcer = $this->enforcer($this->costCapConfig(50.0, false), 50.0);

        self::assertTrue($enforcer->isBlocking());
    }

    public function testDoesNotBlockWhenCapIsReachedButOverrideIsAllowed(): void
    {
        $enforcer = $this->enforcer($this->costCapConfig(50.0, true), 75.0);

        self::assertFalse($enforcer->isBlocking());
    }

    public function testFailsOpenWhenConfigurationReadingThrows(): void
    {
        $configurationReader = $this->createMock(ConfigurationReaderInterface::class);
        $configurationReader->method('readCostCap')->willThrowException(new \RuntimeException('boom'));

        $enforcer = new CostCapEnforcer(
            $configurationReader,
            $this->createMock(CostUsageTrackerInterface::class),
            new \Aavirbhava\AiShoppingAssistant\Model\CostCap\PeriodCalculator(),
            $this->clock(),
            $this->storeManager(),
            $this->createMock(LoggerInterface::class)
        );

        self::assertFalse($enforcer->isBlocking());
    }

    public function testFailsOpenWhenUsageTrackerReadingThrows(): void
    {
        $configurationReader = $this->createMock(ConfigurationReaderInterface::class);
        $configurationReader->method('readCostCap')->willReturn($this->costCapConfig(50.0, false));

        $tracker = $this->createMock(CostUsageTrackerInterface::class);
        $tracker->method('currentUsage')->willThrowException(new \RuntimeException('boom'));

        $enforcer = new CostCapEnforcer(
            $configurationReader,
            $tracker,
            new \Aavirbhava\AiShoppingAssistant\Model\CostCap\PeriodCalculator(),
            $this->clock(),
            $this->storeManager(),
            $this->createMock(LoggerInterface::class)
        );

        self::assertFalse($enforcer->isBlocking());
    }

    public function testFailsOpenWhenStoreResolutionThrows(): void
    {
        $storeManager = $this->createMock(StoreManagerInterface::class);
        $storeManager->method('getStore')->willThrowException(new \RuntimeException('no store'));

        $enforcer = new CostCapEnforcer(
            $this->createMock(ConfigurationReaderInterface::class),
            $this->createMock(CostUsageTrackerInterface::class),
            new \Aavirbhava\AiShoppingAssistant\Model\CostCap\PeriodCalculator(),
            $this->clock(),
            $storeManager,
            $this->createMock(LoggerInterface::class)
        );

        self::assertFalse($enforcer->isBlocking());
    }

    private function costCapConfig(float $capAmount, bool $allowOverride): CostCapConfigInterface
    {
        $config = $this->createMock(CostCapConfigInterface::class);
        $config->method('capAmount')->willReturn($capAmount);
        $config->method('period')->willReturn(CapPeriod::DAILY);
        $config->method('allowOverride')->willReturn($allowOverride);

        return $config;
    }

    private function enforcer(CostCapConfigInterface $costCapConfig, float $currentCost): CostCapEnforcer
    {
        $configurationReader = $this->createMock(ConfigurationReaderInterface::class);
        $configurationReader->method('readCostCap')->with(self::STORE_ID)->willReturn($costCapConfig);

        $tracker = $this->createMock(CostUsageTrackerInterface::class);
        $tracker->method('currentUsage')->willReturn(new CostUsageSnapshot(true, $currentCost, 0));

        return new CostCapEnforcer(
            $configurationReader,
            $tracker,
            new \Aavirbhava\AiShoppingAssistant\Model\CostCap\PeriodCalculator(),
            $this->clock(),
            $this->storeManager(),
            $this->createMock(LoggerInterface::class)
        );
    }

    private function storeManager(): StoreManagerInterface
    {
        $store = $this->createMock(StoreInterface::class);
        $store->method('getId')->willReturn(self::STORE_ID);

        $storeManager = $this->createMock(StoreManagerInterface::class);
        $storeManager->method('getStore')->willReturn($store);

        return $storeManager;
    }

    private function clock(): ClockInterface
    {
        $clock = $this->createMock(ClockInterface::class);
        $clock->method('now')->willReturn(new \DateTimeImmutable('2026-08-21 12:00:00'));

        return $clock;
    }
}
