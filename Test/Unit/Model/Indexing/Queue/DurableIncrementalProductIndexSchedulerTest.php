<?php

declare(strict_types=1);

namespace Aavirbhava\AiShoppingAssistant\Test\Unit\Model\Indexing\Queue;

use Aavirbhava\AiShoppingAssistant\Api\Indexing\IncrementalWorkLedgerInterface;
use Aavirbhava\AiShoppingAssistant\Model\Indexing\Exception\IncrementalQueuePublishFailedException;
use Aavirbhava\AiShoppingAssistant\Model\Indexing\Exception\InvalidProductIndexEntityIdsException;
use Aavirbhava\AiShoppingAssistant\Model\Indexing\Queue\DurableIncrementalProductIndexScheduler;
use Aavirbhava\AiShoppingAssistant\Model\Indexing\Queue\IncrementalProductIndexQueue;
use Aavirbhava\AiShoppingAssistant\Model\Indexing\Queue\MagentoIncrementalProductIndexScheduler;
use Magento\Framework\MessageQueue\PublisherInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

#[CoversClass(DurableIncrementalProductIndexScheduler::class)]
final class DurableIncrementalProductIndexSchedulerTest extends TestCase
{
    /**
     * @var IncrementalWorkLedgerInterface&MockObject
     */
    private $ledger;

    /**
     * @var PublisherInterface&MockObject
     */
    private $publisher;

    protected function setUp(): void
    {
        $this->ledger = $this->createMock(IncrementalWorkLedgerInterface::class);
        $this->publisher = $this->createMock(PublisherInterface::class);
    }

    private function scheduler(): DurableIncrementalProductIndexScheduler
    {
        return new DurableIncrementalProductIndexScheduler(
            $this->ledger,
            new MagentoIncrementalProductIndexScheduler($this->publisher)
        );
    }

    public function testValidatesDeduplicatesAndSortsBeforeLedgerMutation(): void
    {
        $published = [];
        $this->ledger->expects(self::once())
            ->method('recordProductChanges')
            ->with([1, 2, 3]);
        $this->publisher->expects(self::exactly(3))
            ->method('publish')
            ->willReturnCallback(function (string $topic, string $productId) use (&$published): void {
                self::assertSame(IncrementalProductIndexQueue::TOPIC, $topic);
                $published[] = $productId;
            });

        $this->scheduler()->scheduleMany([3, 1, 2, 1]);

        self::assertSame(['1', '2', '3'], $published);
    }

    /**
     * @dataProvider invalidIdsProvider
     *
     * @param array<mixed> $ids
     */
    public function testInvalidInputCausesNoLedgerMutationOrPublication(array $ids): void
    {
        $this->ledger->expects(self::never())->method('recordProductChanges');
        $this->publisher->expects(self::never())->method('publish');

        $this->expectException(InvalidProductIndexEntityIdsException::class);
        $this->scheduler()->scheduleMany($ids);
    }

    /**
     * @return array<string, array{array<mixed>}>
     */
    public static function invalidIdsProvider(): array
    {
        return [
            'empty' => [[]],
            'zero' => [[1, 0]],
            'negative' => [[-1]],
            'string' => [[1, '2']],
        ];
    }

    public function testPublicationFailureLeavesDurableWorkRecorded(): void
    {
        $this->ledger->expects(self::once())
            ->method('recordProductChanges')
            ->with([1, 2]);
        $this->publisher->expects(self::exactly(2))
            ->method('publish')
            ->willReturnCallback(function (string $topic, string $productId): void {
                if ($productId === '2') {
                    throw new \RuntimeException('secret broker text');
                }
            });

        $this->expectException(IncrementalQueuePublishFailedException::class);
        $this->scheduler()->scheduleMany([2, 1]);
    }
}
