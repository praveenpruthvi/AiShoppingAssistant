<?php

declare(strict_types=1);

namespace Aavirbhava\AiShoppingAssistant\Test\Unit\Model\Indexing\Capture;

use Aavirbhava\AiShoppingAssistant\Api\Indexing\IncrementalProductIndexSchedulerInterface;
use Aavirbhava\AiShoppingAssistant\Model\Indexing\Capture\ProductChangeScheduler;
use Aavirbhava\AiShoppingAssistant\Model\Indexing\Exception\IncrementalChangeCaptureException;
use Aavirbhava\AiShoppingAssistant\Model\Indexing\Exception\InvalidProductIndexEntityIdsException;
use Magento\Framework\Indexer\IndexerInterface;
use Magento\Framework\Indexer\IndexerRegistry;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

#[CoversClass(ProductChangeScheduler::class)]
final class ProductChangeSchedulerTest extends TestCase
{
    /**
     * @var IndexerRegistry&MockObject
     */
    private $registry;

    /**
     * @var IncrementalProductIndexSchedulerInterface&MockObject
     */
    private $scheduler;

    /**
     * @var IndexerInterface&MockObject
     */
    private $indexer;

    protected function setUp(): void
    {
        $this->registry = $this->createMock(IndexerRegistry::class);
        $this->scheduler = $this->createMock(IncrementalProductIndexSchedulerInterface::class);
        $this->indexer = $this->createMock(IndexerInterface::class);
        $this->registry->method('get')->with(ProductChangeScheduler::INDEXER_ID)->willReturn($this->indexer);
    }

    private function service(): ProductChangeScheduler
    {
        return new ProductChangeScheduler($this->registry, $this->scheduler);
    }

    public function testSchedulesDeduplicatedIdsOnlyInUpdateOnSaveMode(): void
    {
        $this->indexer->method('isScheduled')->willReturn(false);
        $this->scheduler->expects(self::once())->method('scheduleMany')->with([1, 2, 3]);

        $this->service()->scheduleProductsIfUpdateOnSave(['3', 2, '1', 2]);
    }

    public function testScheduledModeSuppressesObserverPublication(): void
    {
        $this->indexer->method('isScheduled')->willReturn(true);
        $this->scheduler->expects(self::never())->method('scheduleMany');

        $this->service()->scheduleProductsIfUpdateOnSave([1, 2]);
    }

    public function testInvalidIdsFailBeforeScheduler(): void
    {
        $this->scheduler->expects(self::never())->method('scheduleMany');

        $this->expectException(InvalidProductIndexEntityIdsException::class);
        $this->service()->scheduleProductsIfUpdateOnSave([1, 'bad']);
    }

    public function testIndexerModeFailureIsSanitized(): void
    {
        $this->registry->method('get')->willThrowException(new \RuntimeException('secret registry detail'));
        $this->scheduler->expects(self::never())->method('scheduleMany');

        $this->expectException(IncrementalChangeCaptureException::class);
        $this->service()->scheduleProductIfUpdateOnSave(1);
    }
}
