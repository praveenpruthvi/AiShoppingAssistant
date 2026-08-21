<?php

declare(strict_types=1);

namespace Aavirbhava\AiShoppingAssistant\Test\Integration\Model\CostCap;

use Aavirbhava\AiShoppingAssistant\Model\CostCap\CostCapThreshold;
use Aavirbhava\AiShoppingAssistant\Model\CostCap\DbCostUsageTracker;
use Aavirbhava\AiShoppingAssistant\Model\Indexing\Clock\ClockInterface;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\App\State;
use PHPUnit\Framework\TestCase;

/**
 * Exercises DbCostUsageTracker against the real database — the atomic
 * insertOnDuplicate/Zend_Db_Expr increment and the compare-and-swap
 * threshold claim aren't meaningfully re-verifiable against a mocked
 * adapter, the same rationale as DbRebuildFenceDatabaseTest/
 * ActiveCartPromotionDatabaseTest.
 *
 * Period keys used here are deliberately short (<=20 chars, matching the
 * real column's width — real keys are always a 10-char 'Y-m-d' string)
 * rather than descriptive test names, since an earlier draft's longer
 * descriptive keys silently truncated on insert (this environment's MySQL
 * is not running in strict SQL mode) and then never matched on a
 * subsequent read by the full, untruncated key — a real bug in this test
 * itself, not in DbCostUsageTracker.
 */
final class DbCostUsageTrackerDatabaseTest extends TestCase
{
    private \Magento\Framework\ObjectManagerInterface $objectManager;
    private ResourceConnection $resource;
    private DbCostUsageTracker $tracker;

    /**
     * @var list<string>
     */
    private array $usedPeriodKeys = [];

    protected function setUp(): void
    {
        $root = dirname(__DIR__, 8);
        require_once $root . '/app/bootstrap.php';

        $bootstrap = \Magento\Framework\App\Bootstrap::create($root, $_SERVER);
        $this->objectManager = $bootstrap->getObjectManager();

        try {
            $this->objectManager->get(State::class)->setAreaCode('adminhtml');
        } catch (\Throwable) {
        }

        $this->resource = $this->objectManager->get(ResourceConnection::class);
        $this->tracker = new DbCostUsageTracker($this->resource, $this->objectManager->get(ClockInterface::class));
        $this->cleanup();
    }

    protected function tearDown(): void
    {
        $this->cleanup();
    }

    public function testCostAccumulatesAcrossMultipleRealCalls(): void
    {
        $periodKey = $this->periodKey('accum');

        $this->tracker->recordUsage($periodKey, 'daily', 100, 50, 0.010);
        $this->tracker->recordUsage($periodKey, 'daily', 200, 75, 0.015);
        $this->tracker->recordUsage($periodKey, 'daily', 50, 25, 0.005);

        $usage = $this->tracker->currentUsage($periodKey);

        self::assertTrue($usage->exists());
        self::assertEqualsWithDelta(0.030, $usage->costAmount(), 0.0000001);
    }

    public function testAPeriodWithNoRecordedUsageDoesNotExistAndReadsAsZero(): void
    {
        $usage = $this->tracker->currentUsage($this->periodKey('none'));

        self::assertFalse($usage->exists());
        self::assertSame(0.0, $usage->costAmount());
        self::assertSame(CostCapThreshold::NONE, $usage->notifiedThresholdRank());
    }

    public function testDifferentPeriodKeysAccumulateIndependently(): void
    {
        $periodA = $this->periodKey('roll-a');
        $periodB = $this->periodKey('roll-b');

        $this->tracker->recordUsage($periodA, 'daily', 100, 50, 0.020);
        $this->tracker->recordUsage($periodB, 'daily', 999, 999, 0.999);

        $usageA = $this->tracker->currentUsage($periodA);

        self::assertEqualsWithDelta(0.020, $usageA->costAmount(), 0.0000001);
    }

    public function testClaimingAThresholdSucceedsOnceThenFailsForTheSamePeriod(): void
    {
        $periodKey = $this->periodKey('claim1');
        $this->tracker->recordUsage($periodKey, 'daily', 100, 50, 40.0);

        self::assertTrue($this->tracker->claimThresholdNotification($periodKey, CostCapThreshold::WARNING));
        self::assertFalse($this->tracker->claimThresholdNotification($periodKey, CostCapThreshold::WARNING));

        $usage = $this->tracker->currentUsage($periodKey);
        self::assertSame(CostCapThreshold::WARNING, $usage->notifiedThresholdRank());
    }

    public function testClaimingTheCapThresholdSucceedsAfterWarningWasAlreadyClaimed(): void
    {
        $periodKey = $this->periodKey('claim2');
        $this->tracker->recordUsage($periodKey, 'daily', 100, 50, 50.0);

        self::assertTrue($this->tracker->claimThresholdNotification($periodKey, CostCapThreshold::WARNING));
        self::assertTrue($this->tracker->claimThresholdNotification($periodKey, CostCapThreshold::CAP));

        $usage = $this->tracker->currentUsage($periodKey);
        self::assertSame(CostCapThreshold::CAP, $usage->notifiedThresholdRank());
    }

    public function testClaimingAnAlreadySurpassedLowerThresholdFails(): void
    {
        $periodKey = $this->periodKey('claim3');
        $this->tracker->recordUsage($periodKey, 'daily', 100, 50, 50.0);

        self::assertTrue($this->tracker->claimThresholdNotification($periodKey, CostCapThreshold::CAP));
        self::assertFalse($this->tracker->claimThresholdNotification($periodKey, CostCapThreshold::WARNING));
    }

    private function periodKey(string $suffix): string
    {
        $key = 'cctest-' . $suffix;
        $this->usedPeriodKeys[] = $key;

        return $key;
    }

    private function cleanup(): void
    {
        if ($this->usedPeriodKeys === []) {
            return;
        }

        $connection = $this->resource->getConnection();
        $connection->delete(
            $this->resource->getTableName('aavirbhava_ai_cost_cap_usage'),
            ['period_key IN (?)' => $this->usedPeriodKeys]
        );

        $this->usedPeriodKeys = [];
    }
}
