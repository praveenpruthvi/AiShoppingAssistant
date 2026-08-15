<?php

declare(strict_types=1);

namespace Aavirbhava\AiShoppingAssistant\Test\Unit\Model\Indexer;

use Aavirbhava\AiShoppingAssistant\Api\Indexing\FullProductReindexerInterface;
use Aavirbhava\AiShoppingAssistant\Api\Indexing\IncrementalProductIndexSchedulerInterface;
use Aavirbhava\AiShoppingAssistant\Api\Indexing\RebuildResultInterface;
use Aavirbhava\AiShoppingAssistant\Model\Indexing\Exception\InvalidProductIndexEntityIdsException;
use Aavirbhava\AiShoppingAssistant\Model\Indexer\ProductIndexer;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

#[CoversClass(ProductIndexer::class)]
final class ProductIndexerTest extends TestCase
{
    /**
     * @var FullProductReindexerInterface&MockObject
     */
    private $reindexer;

    /**
     * @var IncrementalProductIndexSchedulerInterface&MockObject
     */
    private $scheduler;

    protected function setUp(): void
    {
        $this->reindexer = $this->createMock(FullProductReindexerInterface::class);
        $this->scheduler = $this->createMock(IncrementalProductIndexSchedulerInterface::class);
    }

    private function buildIndexer(): ProductIndexer
    {
        return new ProductIndexer($this->reindexer, $this->scheduler);
    }

    public function testExecuteFullRunsFullRebuild(): void
    {
        $result = $this->createMock(RebuildResultInterface::class);
        $this->reindexer->expects(self::once())->method('rebuild')->willReturn($result);

        $this->buildIndexer()->executeFull();
    }

    public function testExecuteRowSchedulesSingleId(): void
    {
        $this->scheduler->expects(self::once())->method('schedule')->with(42);

        $this->buildIndexer()->executeRow(42);
    }

    public function testExecuteRowCastsStringIdToInt(): void
    {
        $this->scheduler->expects(self::once())->method('schedule')->with(42);

        $this->buildIndexer()->executeRow('42');
    }

    public function testExecuteRowRejectsMalformedStringId(): void
    {
        $this->scheduler->expects(self::never())->method('schedule');

        $this->expectException(InvalidProductIndexEntityIdsException::class);
        $this->buildIndexer()->executeRow('42abc');
    }

    public function testExecuteListSchedulesMany(): void
    {
        $this->scheduler->expects(self::once())->method('scheduleMany')->with([1, 2, 3]);

        $this->buildIndexer()->executeList([1, 2, 3]);
    }

    public function testMviewExecuteWithArraySchedulesMany(): void
    {
        $this->scheduler->expects(self::once())->method('scheduleMany')->with([1, 2]);

        $this->buildIndexer()->execute([1, 2]);
    }

    public function testMviewExecuteWithStringArrayValidatesToIntegers(): void
    {
        $this->scheduler->expects(self::once())->method('scheduleMany')->with([1, 2]);

        $this->buildIndexer()->execute(['1', '2']);
    }

    public function testMviewExecuteWithIntSchedulesSingleId(): void
    {
        $this->scheduler->expects(self::once())->method('schedule')->with(7);

        $this->buildIndexer()->execute(7);
    }
}
