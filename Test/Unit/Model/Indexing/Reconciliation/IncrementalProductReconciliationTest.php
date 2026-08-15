<?php

declare(strict_types=1);

namespace Aavirbhava\AiShoppingAssistant\Test\Unit\Model\Indexing\Reconciliation;

use Aavirbhava\AiShoppingAssistant\Api\Indexing\IncrementalProductIndexSchedulerInterface;
use Aavirbhava\AiShoppingAssistant\Model\Indexing\Exception\IncrementalQueuePublishFailedException;
use Aavirbhava\AiShoppingAssistant\Model\Indexing\Exception\IncrementalReconciliationException;
use Aavirbhava\AiShoppingAssistant\Model\Indexing\Reconciliation\IncrementalProductReconciliation;
use Aavirbhava\AiShoppingAssistant\Model\Indexing\Reconciliation\IncrementalReconciliationCheckpoint;
use Aavirbhava\AiShoppingAssistant\Model\Indexing\Reconciliation\IncrementalReconciliationCheckpointInterface;
use Aavirbhava\AiShoppingAssistant\Model\Indexing\Reconciliation\ProductIdCursorBatchProviderInterface;
use Magento\Framework\Lock\LockManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

#[CoversClass(IncrementalProductReconciliation::class)]
final class IncrementalProductReconciliationTest extends TestCase
{
    /**
     * @var IncrementalReconciliationCheckpointInterface&MockObject
     */
    private $checkpoint;

    /**
     * @var ProductIdCursorBatchProviderInterface&MockObject
     */
    private $productIds;

    /**
     * @var IncrementalProductIndexSchedulerInterface&MockObject
     */
    private $scheduler;

    /**
     * @var LockManagerInterface&MockObject
     */
    private $locks;

    protected function setUp(): void
    {
        $this->checkpoint = $this->createMock(IncrementalReconciliationCheckpointInterface::class);
        $this->productIds = $this->createMock(ProductIdCursorBatchProviderInterface::class);
        $this->scheduler = $this->createMock(IncrementalProductIndexSchedulerInterface::class);
        $this->locks = $this->createMock(LockManagerInterface::class);
    }

    private function reconciliation(): IncrementalProductReconciliation
    {
        return new IncrementalProductReconciliation(
            $this->checkpoint,
            $this->productIds,
            $this->scheduler,
            $this->locks
        );
    }

    public function testLockUnavailableReturnsWithoutScheduling(): void
    {
        $this->locks->expects(self::once())->method('lock')->willReturn(false);
        $this->checkpoint->expects(self::never())->method('current');
        $this->scheduler->expects(self::never())->method('scheduleMany');

        self::assertSame(0, $this->reconciliation()->reconcile());
    }

    public function testSchedulesBoundedBatchAndAdvancesCursor(): void
    {
        $this->locks->method('lock')->willReturn(true);
        $this->locks->expects(self::once())->method('unlock')->willReturn(true);
        $this->checkpoint->method('current')->willReturn(new IncrementalReconciliationCheckpoint(10));
        $this->productIds->expects(self::once())->method('idsAfter')->with(10, 2)->willReturn([11, 12]);
        $this->scheduler->expects(self::once())->method('scheduleMany')->with([11, 12]);
        $this->checkpoint->expects(self::once())->method('advance')->with(12);
        $this->checkpoint->expects(self::never())->method('completePass');

        self::assertSame(2, $this->reconciliation()->reconcile(2));
    }

    public function testShortBatchCompletesPassAndResetsCursor(): void
    {
        $this->locks->method('lock')->willReturn(true);
        $this->locks->method('unlock')->willReturn(true);
        $this->checkpoint->method('current')->willReturn(new IncrementalReconciliationCheckpoint(10));
        $this->productIds->method('idsAfter')->with(10, 3)->willReturn([11, 12]);
        $this->scheduler->expects(self::once())->method('scheduleMany')->with([11, 12]);
        $this->checkpoint->expects(self::once())->method('completePass');
        $this->checkpoint->expects(self::never())->method('advance');

        self::assertSame(2, $this->reconciliation()->reconcile(3));
    }

    public function testEmptyBatchCompletesPassWithoutScheduling(): void
    {
        $this->locks->method('lock')->willReturn(true);
        $this->locks->method('unlock')->willReturn(true);
        $this->checkpoint->method('current')->willReturn(new IncrementalReconciliationCheckpoint(99));
        $this->productIds->method('idsAfter')->willReturn([]);
        $this->scheduler->expects(self::never())->method('scheduleMany');
        $this->checkpoint->expects(self::once())->method('completePass');

        self::assertSame(0, $this->reconciliation()->reconcile());
    }

    public function testSchedulerFailureDoesNotAdvanceCursor(): void
    {
        $this->locks->method('lock')->willReturn(true);
        $this->locks->method('unlock')->willReturn(true);
        $this->checkpoint->method('current')->willReturn(new IncrementalReconciliationCheckpoint(10));
        $this->productIds->method('idsAfter')->willReturn([11]);
        $this->scheduler->method('scheduleMany')->willThrowException(new IncrementalQueuePublishFailedException());
        $this->checkpoint->expects(self::never())->method('advance');
        $this->checkpoint->expects(self::never())->method('completePass');

        $this->expectException(IncrementalQueuePublishFailedException::class);
        $this->reconciliation()->reconcile();
    }

    public function testLockManagementFailureIsSanitized(): void
    {
        $this->locks->method('lock')->willThrowException(new \RuntimeException('secret lock detail'));

        $this->expectException(IncrementalReconciliationException::class);
        $this->reconciliation()->reconcile();
    }

    public function testUnlockFalseWithoutPrimaryFailureIsSanitized(): void
    {
        $this->locks->method('lock')->willReturn(true);
        $this->locks->method('unlock')->willReturn(false);
        $this->checkpoint->method('current')->willReturn(new IncrementalReconciliationCheckpoint(10));
        $this->productIds->method('idsAfter')->willReturn([]);

        $this->expectException(IncrementalReconciliationException::class);
        $this->reconciliation()->reconcile();
    }
}
