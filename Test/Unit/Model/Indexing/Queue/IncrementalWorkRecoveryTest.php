<?php

declare(strict_types=1);

namespace Aavirbhava\AiShoppingAssistant\Test\Unit\Model\Indexing\Queue;

use Aavirbhava\AiShoppingAssistant\Api\Indexing\IncrementalWorkClaimInterface;
use Aavirbhava\AiShoppingAssistant\Api\Indexing\IncrementalWorkLedgerInterface;
use Aavirbhava\AiShoppingAssistant\Model\Indexing\Exception\IncrementalLedgerPersistenceException;
use Aavirbhava\AiShoppingAssistant\Model\Indexing\Exception\IncrementalQueuePublishFailedException;
use Aavirbhava\AiShoppingAssistant\Model\Indexing\Queue\IncrementalProductIndexQueue;
use Aavirbhava\AiShoppingAssistant\Model\Indexing\Queue\IncrementalWorkRecovery;
use Aavirbhava\AiShoppingAssistant\Model\Indexing\Queue\MagentoIncrementalProductIndexScheduler;
use Magento\Framework\Lock\LockManagerInterface;
use Magento\Framework\MessageQueue\PublisherInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

#[CoversClass(IncrementalWorkRecovery::class)]
final class IncrementalWorkRecoveryTest extends TestCase
{
    /**
     * @var IncrementalWorkLedgerInterface&MockObject
     */
    private $ledger;

    /**
     * @var PublisherInterface&MockObject
     */
    private $publisher;

    /**
     * @var LockManagerInterface&MockObject
     */
    private $lockManager;

    protected function setUp(): void
    {
        $this->ledger = $this->createMock(IncrementalWorkLedgerInterface::class);
        $this->publisher = $this->createMock(PublisherInterface::class);
        $this->lockManager = $this->createMock(LockManagerInterface::class);
    }

    private function recovery(): IncrementalWorkRecovery
    {
        return new IncrementalWorkRecovery(
            $this->ledger,
            new MagentoIncrementalProductIndexScheduler($this->publisher),
            $this->lockManager
        );
    }

    public function testLockedRunnerDoesNothing(): void
    {
        $this->lockManager->expects(self::once())->method('lock')->willReturn(false);
        $this->ledger->expects(self::never())->method('dueProductIds');
        $this->publisher->expects(self::never())->method('publish');

        self::assertSame(0, $this->recovery()->recover());
    }

    public function testRecoversExpiredLeasesAndPublishesBoundedDueWakeups(): void
    {
        $claim = $this->createMock(IncrementalWorkClaimInterface::class);
        $this->lockManager->method('lock')->willReturn(true);
        $this->lockManager->expects(self::once())->method('unlock');
        $this->ledger->expects(self::once())->method('recoverExpiredLeases')->with(50)->willReturn(1);
        $this->ledger->expects(self::once())->method('dueProductIds')->with(50)->willReturn([7]);
        $this->ledger->expects(self::once())
            ->method('markQueuedForWakeup')
            ->with(7, 300)
            ->willReturn($claim);
        $this->publisher->expects(self::once())
            ->method('publish')
            ->with(IncrementalProductIndexQueue::TOPIC, '7');

        self::assertSame(1, $this->recovery()->recover());
    }

    public function testPublisherFailureReleasesExactQueuedWakeupAndPropagates(): void
    {
        $claim = $this->createMock(IncrementalWorkClaimInterface::class);
        $this->lockManager->method('lock')->willReturn(true);
        $this->lockManager->expects(self::once())->method('unlock');
        $this->ledger->method('dueProductIds')->willReturn([7]);
        $this->ledger->method('markQueuedForWakeup')->with(7, 300)->willReturn($claim);
        $this->publisher->method('publish')->willThrowException(new \RuntimeException('secret broker'));
        $this->ledger->expects(self::once())
            ->method('releaseQueuedWakeup')
            ->with($claim)
            ->willReturn(true);

        $this->expectException(IncrementalQueuePublishFailedException::class);
        $this->recovery()->recover();
    }

    public function testReleaseFailureAfterPublisherFailurePropagatesSanitizedLedgerFailure(): void
    {
        $claim = $this->createMock(IncrementalWorkClaimInterface::class);
        $this->lockManager->method('lock')->willReturn(true);
        $this->lockManager->expects(self::once())->method('unlock');
        $this->ledger->method('dueProductIds')->willReturn([7]);
        $this->ledger->method('markQueuedForWakeup')->willReturn($claim);
        $this->publisher->method('publish')->willThrowException(new \RuntimeException('secret broker'));
        $this->ledger->method('releaseQueuedWakeup')->with($claim)->willReturn(false);

        $this->expectException(IncrementalLedgerPersistenceException::class);
        $this->recovery()->recover();
    }

    public function testPublicationAfterEarlierSuccessDoesNotReportFalseSuccessWhenLaterPublishFails(): void
    {
        $first = $this->createMock(IncrementalWorkClaimInterface::class);
        $second = $this->createMock(IncrementalWorkClaimInterface::class);
        $this->lockManager->method('lock')->willReturn(true);
        $this->lockManager->expects(self::once())->method('unlock');
        $this->ledger->method('dueProductIds')->willReturn([7, 8]);
        $this->ledger->method('markQueuedForWakeup')
            ->willReturnMap([[7, 300, $first], [8, 300, $second]]);
        $this->publisher->expects(self::exactly(2))
            ->method('publish')
            ->willReturnCallback(
                function (string $topic, string $payload): void {
                    if ($payload === '8') {
                        throw new \RuntimeException('secret broker');
                    }
                }
            );
        $this->ledger->expects(self::once())
            ->method('releaseQueuedWakeup')
            ->with($second)
            ->willReturn(true);

        $this->expectException(IncrementalQueuePublishFailedException::class);
        $this->recovery()->recover();
    }

    public function testOverlappingRunnerCannotPublishUnclaimedDueWork(): void
    {
        $this->lockManager->method('lock')->willReturn(true);
        $this->lockManager->expects(self::once())->method('unlock');
        $this->ledger->method('dueProductIds')->willReturn([7]);
        $this->ledger->method('markQueuedForWakeup')->willReturn(null);
        $this->publisher->expects(self::never())->method('publish');

        self::assertSame(0, $this->recovery()->recover());
    }
}
