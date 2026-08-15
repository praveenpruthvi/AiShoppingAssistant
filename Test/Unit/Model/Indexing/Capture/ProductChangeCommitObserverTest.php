<?php

declare(strict_types=1);

namespace Aavirbhava\AiShoppingAssistant\Test\Unit\Model\Indexing\Capture;

use Aavirbhava\AiShoppingAssistant\Model\Indexing\Capture\ProductChangeCommitObserver;
use Aavirbhava\AiShoppingAssistant\Model\Indexing\Capture\ProductChangeScheduler;
use Magento\Catalog\Model\Product;
use Magento\Framework\Event;
use Magento\Framework\Event\Observer;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

#[CoversClass(ProductChangeCommitObserver::class)]
final class ProductChangeCommitObserverTest extends TestCase
{
    /**
     * @var ProductChangeScheduler&MockObject
     */
    private $scheduler;

    protected function setUp(): void
    {
        $this->scheduler = $this->createMock(ProductChangeScheduler::class);
    }

    public function testSchedulesProductIdFromCommitEvent(): void
    {
        $product = $this->createMock(Product::class);
        $product->method('getId')->willReturn('42');
        $this->scheduler->expects(self::once())->method('scheduleProductIfUpdateOnSave')->with('42');

        $observer = new ProductChangeCommitObserver($this->scheduler);
        $observer->execute(new Observer(['event' => new Event(['product' => $product])]));
    }
}
