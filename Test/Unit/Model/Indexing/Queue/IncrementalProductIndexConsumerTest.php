<?php

declare(strict_types=1);

namespace Aavirbhava\AiShoppingAssistant\Test\Unit\Model\Indexing\Queue;

use Aavirbhava\AiShoppingAssistant\Api\Indexing\IncrementalWorkClaimInterface;
use Aavirbhava\AiShoppingAssistant\Api\Indexing\IncrementalWorkLedgerInterface;
use Aavirbhava\AiShoppingAssistant\Api\Indexing\ProductIncrementalIndexerInterface;
use Aavirbhava\AiShoppingAssistant\Model\Indexing\Exception\IncrementalLedgerPersistenceException;
use Aavirbhava\AiShoppingAssistant\Model\Indexing\Exception\InvalidProductIndexEntityIdsException;
use Aavirbhava\AiShoppingAssistant\Model\Indexing\Exception\OpenSearchBackendUnavailableException;
use Aavirbhava\AiShoppingAssistant\Model\Indexing\Queue\IncrementalProductIndexConsumer;
use Aavirbhava\AiShoppingAssistant\Model\Indexing\Queue\IncrementalFailureDisposition;
use Aavirbhava\AiShoppingAssistant\Model\Indexing\Queue\IncrementalFailureDispositionPolicyInterface;
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
     * @var IncrementalWorkClaimInterface&MockObject
     */
    private $claim;

    protected function setUp(): void
    {
        $this->indexer = $this->createMock(ProductIncrementalIndexerInterface::class);
        $this->ledger = $this->createMock(IncrementalWorkLedgerInterface::class);
        $this->policy = $this->createMock(IncrementalFailureDispositionPolicyInterface::class);
        $this->claim = $this->createMock(IncrementalWorkClaimInterface::class);
    }

    private function consumer(): IncrementalProductIndexConsumer
    {
        return new IncrementalProductIndexConsumer($this->indexer, $this->ledger, $this->policy);
    }

    public function testProcessClaimsIndexesAndCompletesExactlyOnce(): void
    {
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
        $this->ledger->expects(self::once())
            ->method('claimDueWork')
            ->with(42)
            ->willReturn(null);
        $this->indexer->expects(self::never())->method('process');
        $this->ledger->expects(self::never())->method('complete');

        $this->consumer()->process('42');
    }

    public function testRetryableFailureIsRecordedWithoutRawExceptionPropagation(): void
    {
        $failure = new OpenSearchBackendUnavailableException(new \RuntimeException('secret host'));
        $this->ledger->method('claimDueWork')->willReturn($this->claim);
        $this->indexer->expects(self::once())
            ->method('process')
            ->with(42)
            ->willThrowException($failure);
        $this->policy->expects(self::once())
            ->method('classify')
            ->with($failure, 0)
            ->willReturn(new IncrementalFailureDisposition(true, 'opensearch_backend_unavailable', 60));
        $this->ledger->expects(self::once())
            ->method('recordRetry')
            ->with($this->claim, 'opensearch_backend_unavailable', 60)
            ->willReturn(true);

        $this->consumer()->process('42');
    }

    public function testUnknownFailureIsRecordedTerminal(): void
    {
        $failure = new \RuntimeException('secret failure detail');
        $this->ledger->method('claimDueWork')->willReturn($this->claim);
        $this->indexer->method('process')->willThrowException($failure);
        $this->policy->method('classify')
            ->with($failure, 0)
            ->willReturn(new IncrementalFailureDisposition(false, 'unknown', 0));
        $this->ledger->expects(self::once())
            ->method('recordTerminal')
            ->with($this->claim, 'unknown')
            ->willReturn(true);

        $this->consumer()->process('42');
    }

    public function testFailureStatePersistenceFailurePropagatesSanitizedException(): void
    {
        $this->ledger->method('claimDueWork')->willReturn($this->claim);
        $this->indexer->method('process')->willThrowException(new \RuntimeException('secret failure detail'));
        $this->policy->method('classify')
            ->willReturn(new IncrementalFailureDisposition(false, 'unknown', 0));
        $this->ledger->method('recordTerminal')->willReturn(false);

        $this->expectException(IncrementalLedgerPersistenceException::class);
        $this->consumer()->process('42');
    }

    public function testConstructionDoesNotIndex(): void
    {
        $this->indexer->expects(self::never())->method('process');

        $this->consumer();
    }
}
