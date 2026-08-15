<?php

declare(strict_types=1);

namespace Aavirbhava\AiShoppingAssistant\Test\Unit\Model\Indexing\Queue;

use Aavirbhava\AiShoppingAssistant\Model\Indexing\Exception\IncrementalQueuePublishFailedException;
use Aavirbhava\AiShoppingAssistant\Model\Indexing\Exception\InvalidProductIndexEntityIdsException;
use Aavirbhava\AiShoppingAssistant\Model\Indexing\Queue\IncrementalProductIndexQueue;
use Aavirbhava\AiShoppingAssistant\Model\Indexing\Queue\MagentoIncrementalProductIndexScheduler;
use Magento\Framework\MessageQueue\PublisherInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

#[CoversClass(MagentoIncrementalProductIndexScheduler::class)]
final class MagentoIncrementalProductIndexSchedulerTest extends TestCase
{
    /**
     * @var PublisherInterface&MockObject
     */
    private $publisher;

    protected function setUp(): void
    {
        $this->publisher = $this->createMock(PublisherInterface::class);
    }

    private function scheduler(): MagentoIncrementalProductIndexScheduler
    {
        return new MagentoIncrementalProductIndexScheduler($this->publisher);
    }

    public function testSchedulePublishesSingleProductId(): void
    {
        $this->publisher->expects(self::once())
            ->method('publish')
            ->with(IncrementalProductIndexQueue::TOPIC, '42');

        $this->scheduler()->schedule(42);
    }

    public function testScheduleManyPublishesDeduplicatedSortedIds(): void
    {
        $published = [];
        $this->publisher->expects(self::exactly(3))
            ->method('publish')
            ->willReturnCallback(
                function (string $topic, string $productId) use (&$published): void {
                    $published[] = [$topic, $productId];
                }
            );

        $this->scheduler()->scheduleMany([3, 1, 2, 1, 3]);

        self::assertSame(
            [
                [IncrementalProductIndexQueue::TOPIC, '1'],
                [IncrementalProductIndexQueue::TOPIC, '2'],
                [IncrementalProductIndexQueue::TOPIC, '3'],
            ],
            $published
        );
    }

    /**
     * @dataProvider invalidIdsProvider
     *
     * @param array<mixed> $ids
     */
    public function testInvalidListPublishesNothing(array $ids): void
    {
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

    public function testPartialPublisherFailureIsSanitizedAndRetrySafe(): void
    {
        $published = [];
        $this->publisher->method('publish')->willReturnCallback(
            function (string $topic, string $productId) use (&$published): void {
                $published[] = [$topic, $productId];
                if ($productId === '2') {
                    throw new \RuntimeException('secret broker detail');
                }
            }
        );

        try {
            $this->scheduler()->scheduleMany([1, 2, 3]);
            self::fail('Expected queue publish failure');
        } catch (IncrementalQueuePublishFailedException $exception) {
            self::assertSame('incremental_queue_publish_failed', $exception->errorCode());
            self::assertNull($exception->getPrevious());
            self::assertStringNotContainsString('secret broker detail', $exception->getMessage());
            self::assertSame(
                [
                    [IncrementalProductIndexQueue::TOPIC, '1'],
                    [IncrementalProductIndexQueue::TOPIC, '2'],
                ],
                $published
            );
        }
    }

    public function testConstructionDoesNotPublish(): void
    {
        $this->publisher->expects(self::never())->method('publish');

        $this->scheduler();
    }
}
