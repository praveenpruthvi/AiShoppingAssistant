<?php

declare(strict_types=1);

namespace Aavirbhava\AiShoppingAssistant\Test\Unit\Model\Indexing\Queue;

use Aavirbhava\AiShoppingAssistant\Api\Indexing\IncrementalWorkClaimInterface;
use Aavirbhava\AiShoppingAssistant\Api\Indexing\IncrementalWorkLedgerInterface;
use Aavirbhava\AiShoppingAssistant\Api\Indexing\ProductIncrementalIndexerInterface;
use Aavirbhava\AiShoppingAssistant\Model\Indexing\Exception\IncrementalLedgerPersistenceException;
use Aavirbhava\AiShoppingAssistant\Model\Indexing\Exception\IncrementalWorkerLockException;
use Aavirbhava\AiShoppingAssistant\Model\Indexing\Exception\InvalidProductIndexEntityIdsException;
use Aavirbhava\AiShoppingAssistant\Model\Indexing\Exception\OpenSearchBackendUnavailableException;
use Aavirbhava\AiShoppingAssistant\Model\Indexing\Queue\IncrementalProductIndexConsumer;
use Aavirbhava\AiShoppingAssistant\Model\Indexing\Queue\IncrementalFailureDisposition;
use Aavirbhava\AiShoppingAssistant\Model\Indexing\Queue\IncrementalFailureDispositionPolicyInterface;
use Magento\Framework\Lock\LockManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

#[CoversClass(IncrementalProductIndexConsumer::class)]
final class IncrementalProductIndexConsumerTest extends TestCase
{
    /**
     * @var ProductIncrementalIndexerInterface&MockObject
     */
    private $indexer;

    /**
     * @var IncrementalWorkLedgerInterface&MockObject
     */
    private $ledger;

    /**
     * @var IncrementalFailureDispositionPolicyInterface&MockObject
     */
    private $policy;

    /**
     * @var LockManagerInterface&MockObject
     */
    private $lockManager;

    /**
     * @var IncrementalWorkClaimInterface&MockObject
     */
    private $claim;

    protected function setUp(): void
    {
        $this->indexer = $this->createMock(ProductIncrementalIndexerInterface::class);
        $this->ledger = $this->createMock(IncrementalWorkLedgerInterface::class);
        $this->policy = $this->createMock(IncrementalFailureDispositionPolicyInterface::class);
        $this->lockManager = $this->createMock(LockManagerInterface::class);
        $this->claim = $this->createMock(IncrementalWorkClaimInterface::class);
        $this->claim->method('attempts')->willReturn(2);
    }

    private function consumer(): IncrementalProductIndexConsumer
    {
        return new IncrementalProductIndexConsumer(
            $this->indexer,
            $this->ledger,
            $this->policy,
            $this->lockManager
        );
    }

    public function testProcessClaimsIndexesAndCompletesExactlyOnce(): void
    {
        $this->allowProductLock();
        $this->allowProductUnlock();
        $this->ledger->expects(self::once())
            ->method('claimDueWork')
            ->with(42)
            ->willReturn($this->claim);
        $this->indexer->expects(self::once())
            ->method('process')
            ->with(42);
        $this->ledger->expects(self::once())
            ->method('complete')
            ->with($this->claim)
            ->willReturn(true);

        $this->consumer()->process('42');
    }

    /**
     * @dataProvider malformedPayloadProvider
     */
    public function testMalformedPayloadFailsClosed(mixed $payload): void
    {
        $this->ledger->expects(self::never())->method('claimDueWork');
        $this->indexer->expects(self::never())->method('process');
        $this->lockManager->expects(self::never())->method('lock');

        $this->expectException(InvalidProductIndexEntityIdsException::class);
        $this->consumer()->process($payload);
    }

    /**
     * @return array<string, array{mixed}>
     */
    public static function malformedPayloadProvider(): array
    {
        return [
            'empty string' => [''],
            'zero' => ['0'],
            'negative' => ['-1'],
            'non numeric' => ['abc'],
            'fractional' => ['1.2'],
            'array' => [[42]],
        ];
    }

    public function testDuplicateOrStaleMessageIsNoopWhenNoClaimExists(): void
    {
        $this->allowProductLock();
        $this->allowProductUnlock();
        $this->ledger->expects(self::once())
            ->method('claimDueWork')
            ->with(42)
            ->willReturn(null);
        $this->indexer->expects(self::never())->method('process');
        $this->ledger->expects(self::never())->method('complete');

        $this->consumer()->process('42');
    }

    public function testUnavailableProductLockReturnsBeforeClaiming(): void
    {
        $this->lockManager->expects(self::once())
            ->method('lock')
            ->with('aavirbhava_ai_incremental_product_42', 0)
            ->willReturn(false);
        $this->lockManager->expects(self::never())->method('unlock');
        $this->ledger->expects(self::never())->method('claimDueWork');
        $this->indexer->expects(self::never())->method('process');

        $this->consumer()->process('42');
    }

    public function testLockIsHeldThroughClaimIndexAndCompletionThenReleased(): void
    {
        $productLocked = false;
        $gateLocked = false;
        $this->lockManager->expects(self::exactly(2))
            ->method('lock')
            ->willReturnCallback(function (string $name) use (&$productLocked, &$gateLocked): bool {
                if ($name === 'aavirbhava_ai_incremental_product_42') {
                    $productLocked = true;

                    return true;
                }

                self::assertTrue($productLocked);
                $gateLocked = true;

                return true;
            });
        $this->ledger->expects(self::once())
            ->method('claimDueWork')
            ->willReturnCallback(function () use (&$productLocked, &$gateLocked) {
                self::assertTrue($productLocked);
                self::assertTrue($gateLocked);

                return $this->claim;
            });
        $this->indexer->expects(self::once())
            ->method('process')
            ->willReturnCallback(function () use (&$productLocked, &$gateLocked): void {
                self::assertTrue($productLocked);
                self::assertFalse($gateLocked);
            });
        $this->ledger->expects(self::once())
            ->method('complete')
            ->willReturnCallback(function () use (&$productLocked, &$gateLocked): bool {
                self::assertTrue($productLocked);
                self::assertFalse($gateLocked);

                return true;
            });
        $this->lockManager->expects(self::exactly(2))
            ->method('unlock')
            ->willReturnCallback(function (string $name) use (&$productLocked, &$gateLocked): bool {
                if ($name === 'aavirbhava_ai_full_rebuild_gate') {
                    self::assertTrue($gateLocked);
                    $gateLocked = false;

                    return true;
                }

                self::assertSame('aavirbhava_ai_incremental_product_42', $name);
                self::assertTrue($productLocked);
                self::assertFalse($gateLocked);
                $productLocked = false;

                return true;
            });

        $this->consumer()->process('42');
        self::assertFalse($productLocked);
        self::assertFalse($gateLocked);
    }

    public function testLockExceptionIsSanitized(): void
    {
        $this->lockManager->method('lock')->willThrowException(new \RuntimeException('secret lock backend'));
        $this->ledger->expects(self::never())->method('claimDueWork');

        $this->expectException(IncrementalWorkerLockException::class);
        $this->consumer()->process('42');
    }

    public function testSecondExecutionCannotIndexWhenProductLockIsHeld(): void
    {
        $calls = 0;
        $this->lockManager->method('lock')->willReturnCallback(
            function (string $name) use (&$calls): bool {
                ++$calls;

                return $calls !== 3 || $name !== 'aavirbhava_ai_incremental_product_42';
            }
        );
        $this->lockManager->method('unlock')->willReturn(true);
        $this->ledger->expects(self::once())->method('claimDueWork')->willReturn($this->claim);
        $this->indexer->expects(self::once())->method('process')->with(42);
        $this->ledger->method('complete')->willReturn(true);

        $this->consumer()->process('42');
        $this->consumer()->process('42');
    }

    public function testExpiredLeaseWakeupDoesNotIndexUntilOriginalWorkerReleasesProductLock(): void
    {
        $calls = 0;
        $this->lockManager->method('lock')->willReturnCallback(
            function (string $name) use (&$calls): bool {
                ++$calls;

                return $calls !== 1 || $name !== 'aavirbhava_ai_incremental_product_42';
            }
        );
        $this->lockManager->method('unlock')->willReturn(true);
        $this->ledger->expects(self::once())->method('claimDueWork')->with(42)->willReturn($this->claim);
        $this->indexer->expects(self::once())->method('process')->with(42);
        $this->ledger->expects(self::once())->method('complete')->with($this->claim)->willReturn(true);

        $this->consumer()->process('42');
        $this->consumer()->process('42');
    }

    public function testRetryableFailureIsRecordedWithoutRawExceptionPropagation(): void
    {
        $this->allowProductLock();
        $failure = new OpenSearchBackendUnavailableException(new \RuntimeException('secret host'));
        $this->ledger->method('claimDueWork')->willReturn($this->claim);
        $this->indexer->expects(self::once())
            ->method('process')
            ->with(42)
            ->willThrowException($failure);
        $this->policy->expects(self::once())
            ->method('classify')
            ->with($failure, 2)
            ->willReturn(new IncrementalFailureDisposition(true, 'opensearch_backend_unavailable', 60));
        $this->ledger->expects(self::once())
            ->method('recordRetry')
            ->with($this->claim, 'opensearch_backend_unavailable', 60)
            ->willReturn(true);
        $this->lockManager->expects(self::exactly(2))->method('unlock')->willReturn(true);

        $this->consumer()->process('42');
    }

    public function testUnknownFailureIsRecordedTerminal(): void
    {
        $this->allowProductLock();
        $failure = new \RuntimeException('secret failure detail');
        $this->ledger->method('claimDueWork')->willReturn($this->claim);
        $this->indexer->method('process')->willThrowException($failure);
        $this->policy->method('classify')
            ->with($failure, 2)
            ->willReturn(new IncrementalFailureDisposition(false, 'unknown', 0));
        $this->ledger->expects(self::once())
            ->method('recordTerminal')
            ->with($this->claim, 'unknown')
            ->willReturn(true);
        $this->lockManager->expects(self::exactly(2))->method('unlock')->willReturn(true);

        $this->consumer()->process('42');
    }

    public function testFailureStatePersistenceFailurePropagatesSanitizedException(): void
    {
        $this->allowProductLock();
        $this->ledger->method('claimDueWork')->willReturn($this->claim);
        $this->indexer->method('process')->willThrowException(new \RuntimeException('secret failure detail'));
        $this->policy->method('classify')
            ->willReturn(new IncrementalFailureDisposition(false, 'unknown', 0));
        $this->ledger->method('recordTerminal')->willReturn(false);
        $this->lockManager->expects(self::exactly(2))->method('unlock')->willReturn(true);

        $this->expectException(IncrementalLedgerPersistenceException::class);
        $this->consumer()->process('42');
    }

    public function testCompletionPersistenceFailurePropagatesSanitizedException(): void
    {
        $this->allowProductLock();
        $this->ledger->method('claimDueWork')->willReturn($this->claim);
        $this->indexer->expects(self::once())->method('process')->with(42);
        $this->ledger->method('complete')->with($this->claim)->willReturn(false);
        $this->policy->expects(self::never())->method('classify');
        $this->ledger->expects(self::never())->method('recordTerminal');
        $this->ledger->expects(self::never())->method('recordRetry');
        $this->lockManager->expects(self::exactly(2))->method('unlock')->willReturn(true);

        $this->expectException(IncrementalLedgerPersistenceException::class);
        $this->consumer()->process('42');
    }

    public function testLedgerCompletionExceptionIsNotClassifiedAsIndexingFailure(): void
    {
        $this->allowProductLock();
        $this->ledger->method('claimDueWork')->willReturn($this->claim);
        $this->indexer->method('process')->with(42);
        $this->ledger->method('complete')->willThrowException(new IncrementalLedgerPersistenceException());
        $this->policy->expects(self::never())->method('classify');
        $this->ledger->expects(self::never())->method('recordTerminal');
        $this->ledger->expects(self::never())->method('recordRetry');
        $this->lockManager->expects(self::exactly(2))->method('unlock')->willReturn(true);

        $this->expectException(IncrementalLedgerPersistenceException::class);
        $this->consumer()->process('42');
    }

    public function testLockReleaseFailureDoesNotReplacePrimaryLedgerFailure(): void
    {
        $this->allowProductLock();
        $this->ledger->method('claimDueWork')->willReturn($this->claim);
        $this->indexer->method('process')->with(42);
        $this->ledger->method('complete')->willThrowException(new IncrementalLedgerPersistenceException());
        $this->lockManager->method('unlock')->willReturnCallback(static function (string $name): bool {
            if ($name === 'aavirbhava_ai_full_rebuild_gate') {
                return true;
            }

            throw new \RuntimeException('secret unlock detail');
        });

        $this->expectException(IncrementalLedgerPersistenceException::class);
        $this->consumer()->process('42');
    }

    public function testLockReleaseFailureWithoutPrimaryFailureIsSanitized(): void
    {
        $this->allowProductLock();
        $this->ledger->method('claimDueWork')->willReturn($this->claim);
        $this->indexer->method('process')->with(42);
        $this->ledger->method('complete')->willReturn(true);
        $this->lockManager->method('unlock')->willThrowException(new \RuntimeException('secret unlock detail'));

        $this->expectException(IncrementalWorkerLockException::class);
        $this->consumer()->process('42');
    }

    public function testUnlockFalseWithoutPrimaryFailureIsSanitized(): void
    {
        $this->allowProductLock();
        $this->ledger->method('claimDueWork')->willReturn($this->claim);
        $this->indexer->method('process')->with(42);
        $this->ledger->method('complete')->willReturn(true);
        $this->lockManager->method('unlock')->willReturn(false);

        $this->expectException(IncrementalWorkerLockException::class);
        $this->consumer()->process('42');
    }

    public function testUnlockFalseDoesNotReplacePrimaryLedgerFailure(): void
    {
        $this->allowProductLock();
        $this->ledger->method('claimDueWork')->willReturn($this->claim);
        $this->indexer->method('process')->with(42);
        $this->ledger->method('complete')->willThrowException(new IncrementalLedgerPersistenceException());
        $this->lockManager->method('unlock')->willReturnCallback(
            static fn(string $name): bool => $name === 'aavirbhava_ai_full_rebuild_gate'
        );

        $this->expectException(IncrementalLedgerPersistenceException::class);
        $this->consumer()->process('42');
    }

    public function testConstructionDoesNotIndex(): void
    {
        $this->indexer->expects(self::never())->method('process');

        $this->consumer();
    }

    private function allowProductLock(): void
    {
        $this->lockManager->method('lock')->willReturn(true);
    }

    private function allowProductUnlock(): void
    {
        $this->lockManager->method('unlock')->willReturn(true);
    }
}
