<?php

declare(strict_types=1);

namespace Aavirbhava\AiShoppingAssistant\Test\Unit\Model\Indexing\Capture;

use Aavirbhava\AiShoppingAssistant\Api\Indexing\IncrementalProductReconciliationInterface;
use Aavirbhava\AiShoppingAssistant\Model\Indexing\Capture\CategoryChangeCommitObserver;
use Aavirbhava\AiShoppingAssistant\Model\Indexing\Capture\ProductChangeScheduler;
use Magento\Catalog\Model\Category;
use Magento\Framework\Event;
use Magento\Framework\Event\Observer;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

#[CoversClass(CategoryChangeCommitObserver::class)]
final class CategoryChangeCommitObserverTest extends TestCase
{
    /**
     * @var ProductChangeScheduler&MockObject
     */
    private $scheduler;

    /**
     * @var IncrementalProductReconciliationInterface&MockObject
     */
    private $reconciliation;

    protected function setUp(): void
    {
        $this->scheduler = $this->createMock(ProductChangeScheduler::class);
        $this->reconciliation = $this->createMock(IncrementalProductReconciliationInterface::class);
    }

    public function testSchedulesAffectedProductsAndRequestsBoundedReconciliation(): void
    {
        $category = $this->getMockBuilder(Category::class)
            ->disableOriginalConstructor()
            ->addMethods(['getAffectedProductIds'])
            ->getMock();
        $category->method('getAffectedProductIds')->willReturn(['3', 1]);
        $this->scheduler->expects(self::once())->method('scheduleProductsIfUpdateOnSave')->with(['3', 1]);
        $this->reconciliation->expects(self::once())->method('requestFullPass');

        $observer = new CategoryChangeCommitObserver($this->scheduler, $this->reconciliation);
        $observer->execute(new Observer(['event' => new Event(['category' => $category])]));
    }
}
