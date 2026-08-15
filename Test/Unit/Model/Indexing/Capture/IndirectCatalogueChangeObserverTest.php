<?php

declare(strict_types=1);

namespace Aavirbhava\AiShoppingAssistant\Test\Unit\Model\Indexing\Capture;

use Aavirbhava\AiShoppingAssistant\Api\Indexing\IncrementalProductReconciliationInterface;
use Aavirbhava\AiShoppingAssistant\Model\Indexing\Capture\IndirectCatalogueChangeObserver;
use Magento\Framework\Event\Observer;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

#[CoversClass(IndirectCatalogueChangeObserver::class)]
final class IndirectCatalogueChangeObserverTest extends TestCase
{
    /**
     * @var IncrementalProductReconciliationInterface&MockObject
     */
    private $reconciliation;

    protected function setUp(): void
    {
        $this->reconciliation = $this->createMock(IncrementalProductReconciliationInterface::class);
    }

    public function testRequestsBoundedReconciliationOnly(): void
    {
        $this->reconciliation->expects(self::once())->method('requestFullPass');

        $observer = new IndirectCatalogueChangeObserver($this->reconciliation);
        $observer->execute(new Observer());
    }
}
