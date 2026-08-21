<?php

declare(strict_types=1);

namespace Aavirbhava\AiShoppingAssistant\Test\Unit\Model\CostCap;

use Aavirbhava\AiShoppingAssistant\Api\Config\ConfigurationReaderInterface;
use Aavirbhava\AiShoppingAssistant\Api\Config\CostCapConfigInterface;
use Aavirbhava\AiShoppingAssistant\Api\Config\ProviderCostConfigInterface;
use Aavirbhava\AiShoppingAssistant\Api\CostCap\CostCapNotifierInterface;
use Aavirbhava\AiShoppingAssistant\Api\CostCap\CostUsageTrackerInterface;
use Aavirbhava\AiShoppingAssistant\Model\Config\Source\CapPeriod;
use Aavirbhava\AiShoppingAssistant\Model\CostCap\CostCalculator;
use Aavirbhava\AiShoppingAssistant\Model\CostCap\CostCapThreshold;
use Aavirbhava\AiShoppingAssistant\Model\CostCap\CostUsageRecorder;
use Aavirbhava\AiShoppingAssistant\Model\CostCap\CostUsageSnapshot;
use Aavirbhava\AiShoppingAssistant\Model\CostCap\PeriodCalculator;
use Aavirbhava\AiShoppingAssistant\Model\Dto\TokenUsage;
use Aavirbhava\AiShoppingAssistant\Model\Indexing\Clock\ClockInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Proves CostUsageRecorder: (1) always records real usage, (2) skips
 * threshold checks entirely when no cap is configured, (3) sends the
 * warning notification exactly once per period even across multiple
 * record() calls that stay above the threshold — the tracker's
 * claimThresholdNotification() is the only source of that dedup, so
 * these tests drive it directly via a mock, (4) still notifies on cap
 * reached even when override is allowed — override is not silent, (5)
 * swallows any tracking/notification failure rather than propagating.
 */
#[CoversClass(CostUsageRecorder::class)]
final class CostUsageRecorderTest extends TestCase
{
    private const STORE_ID = 3;

    public function testRecordsRealUsageComputedFromRealTokenCountsAndPricing(): void
    {
        $tracker = $this->createMock(CostUsageTrackerInterface::class);
        $tracker->expects(self::once())
            ->method('recordUsage')
            ->with('2026-08-21', CapPeriod::DAILY, 2000, 1000, 0.025);
        $tracker->method('currentUsage')->willReturn(new CostUsageSnapshot(true, 0.025, CostCapThreshold::NONE));

        $recorder = $this->recorder($tracker, $this->costCapConfig(0.0, 80, false));

        $recorder->record(self::STORE_ID, 'openai', new TokenUsage(2000, 1000));
    }

    public function testSkipsThresholdChecksWhenNoCapIsConfigured(): void
    {
        $tracker = $this->createMock(CostUsageTrackerInterface::class);
        $tracker->expects(self::once())->method('recordUsage');
        $tracker->expects(self::never())->method('currentUsage');
        $tracker->expects(self::never())->method('claimThresholdNotification');

        $notifier = $this->createMock(CostCapNotifierInterface::class);
        $notifier->expects(self::never())->method('notify');

        $recorder = $this->recorder($tracker, $this->costCapConfig(0.0, 80, false), $notifier);

        $recorder->record(self::STORE_ID, 'openai', new TokenUsage(100, 50));
    }

    public function testSendsTheWarningNotificationOnlyOnceAcrossMultipleCallsInTheSamePeriod(): void
    {
        $tracker = $this->createMock(CostUsageTrackerInterface::class);
        $tracker->method('currentUsage')->willReturn(new CostUsageSnapshot(true, 45.0, CostCapThreshold::NONE));
        // First call wins the claim, every subsequent call in the same
        // period loses it — this is the real dedup mechanism.
        $tracker->method('claimThresholdNotification')
            ->willReturnOnConsecutiveCalls(true, false, false);

        $notifier = $this->createMock(CostCapNotifierInterface::class);
        $notifier->expects(self::once())->method('notify');

        $recorder = $this->recorder($tracker, $this->costCapConfig(50.0, 80, false), $notifier);

        $recorder->record(self::STORE_ID, 'openai', new TokenUsage(100, 50));
        $recorder->record(self::STORE_ID, 'openai', new TokenUsage(100, 50));
        $recorder->record(self::STORE_ID, 'openai', new TokenUsage(100, 50));
    }

    public function testStillNotifiesOnCapReachedEvenWhenOverrideIsAllowed(): void
    {
        $tracker = $this->createMock(CostUsageTrackerInterface::class);
        $tracker->method('currentUsage')->willReturn(new CostUsageSnapshot(true, 50.0, CostCapThreshold::WARNING));
        $tracker->method('claimThresholdNotification')
            ->willReturnCallback(static fn (string $period, int $rank): bool => $rank === CostCapThreshold::CAP);

        $notifier = $this->createMock(CostCapNotifierInterface::class);
        $notifier->expects(self::once())->method('notify')->with(
            self::STORE_ID,
            self::anything(),
            self::anything(),
            self::anything(),
            CostCapThreshold::CAP
        );

        $recorder = $this->recorder($tracker, $this->costCapConfig(50.0, 80, true), $notifier);

        $recorder->record(self::STORE_ID, 'openai', new TokenUsage(100, 50));
    }

    public function testBothWarningAndCapNotificationsFireWhenASingleCallCrossesBoth(): void
    {
        $tracker = $this->createMock(CostUsageTrackerInterface::class);
        $tracker->method('currentUsage')->willReturn(new CostUsageSnapshot(true, 60.0, CostCapThreshold::NONE));
        $tracker->method('claimThresholdNotification')->willReturn(true);

        $notifier = $this->createMock(CostCapNotifierInterface::class);
        $notifier->expects(self::exactly(2))->method('notify');

        $recorder = $this->recorder($tracker, $this->costCapConfig(50.0, 80, false), $notifier);

        $recorder->record(self::STORE_ID, 'openai', new TokenUsage(4000, 4000));
    }

    public function testTrackingFailureIsSwallowedNotPropagated(): void
    {
        $tracker = $this->createMock(CostUsageTrackerInterface::class);
        $tracker->method('recordUsage')->willThrowException(new \RuntimeException('boom'));

        $recorder = $this->recorder($tracker, $this->costCapConfig(50.0, 80, false));

        $recorder->record(self::STORE_ID, 'openai', new TokenUsage(100, 50));

        self::assertTrue(true, 'record() must not throw on a tracking failure.');
    }

    public function testNotificationFailureIsSwallowedNotPropagated(): void
    {
        $tracker = $this->createMock(CostUsageTrackerInterface::class);
        $tracker->method('currentUsage')->willReturn(new CostUsageSnapshot(true, 50.0, CostCapThreshold::NONE));
        $tracker->method('claimThresholdNotification')->willReturn(true);

        $notifier = $this->createMock(CostCapNotifierInterface::class);
        $notifier->method('notify')->willThrowException(new \RuntimeException('smtp down'));

        $recorder = $this->recorder($tracker, $this->costCapConfig(50.0, 80, false), $notifier);

        $recorder->record(self::STORE_ID, 'openai', new TokenUsage(100, 50));

        self::assertTrue(true, 'record() must not throw on a notification failure.');
    }

    private function costCapConfig(float $capAmount, int $warningPercent, bool $allowOverride): CostCapConfigInterface
    {
        $config = $this->createMock(CostCapConfigInterface::class);
        $config->method('capAmount')->willReturn($capAmount);
        $config->method('period')->willReturn(CapPeriod::DAILY);
        $config->method('warningThresholdPercent')->willReturn($warningPercent);
        $config->method('allowOverride')->willReturn($allowOverride);
        $config->method('notificationEmails')->willReturn(['ops@store.test']);

        return $config;
    }

    private function recorder(
        CostUsageTrackerInterface $tracker,
        CostCapConfigInterface $costCapConfig,
        ?CostCapNotifierInterface $notifier = null
    ): CostUsageRecorder {
        $configurationReader = $this->createMock(ConfigurationReaderInterface::class);
        $configurationReader->method('readCostCap')->willReturn($costCapConfig);
        $configurationReader->method('readProviderCost')->willReturn($this->providerCost());

        return new CostUsageRecorder(
            $configurationReader,
            $tracker,
            new PeriodCalculator(),
            new CostCalculator(),
            $this->clock(),
            $notifier ?? $this->createMock(CostCapNotifierInterface::class),
            $this->createMock(LoggerInterface::class)
        );
    }

    private function providerCost(): ProviderCostConfigInterface
    {
        $providerCost = $this->createMock(ProviderCostConfigInterface::class);
        $providerCost->method('pricePerThousandInputTokens')->willReturn(0.005);
        $providerCost->method('pricePerThousandOutputTokens')->willReturn(0.015);

        return $providerCost;
    }

    private function clock(): ClockInterface
    {
        $clock = $this->createMock(ClockInterface::class);
        $clock->method('now')->willReturn(new \DateTimeImmutable('2026-08-21 12:00:00'));

        return $clock;
    }
}
