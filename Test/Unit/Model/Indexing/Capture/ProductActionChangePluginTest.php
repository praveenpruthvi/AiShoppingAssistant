<?php

declare(strict_types=1);

namespace Aavirbhava\AiShoppingAssistant\Test\Unit\Model\Indexing\Capture;

use Aavirbhava\AiShoppingAssistant\Model\Indexing\Capture\ProductActionChangePlugin;
use Aavirbhava\AiShoppingAssistant\Model\Indexing\Capture\ProductChangeScheduler;
use Magento\Catalog\Model\Product\Action;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

#[CoversClass(ProductActionChangePlugin::class)]
final class ProductActionChangePluginTest extends TestCase
{
    /**
     * @var ProductChangeScheduler&MockObject
     */
    private $scheduler;

    /**
     * @var Action&MockObject
     */
    private $action;

    protected function setUp(): void
    {
        $this->scheduler = $this->createMock(ProductChangeScheduler::class);
        $this->action = $this->createMock(Action::class);
    }

    public function testMassAttributeUpdateSchedulesIdsAfterAction(): void
    {
        $this->scheduler->expects(self::once())->method('scheduleProductsIfUpdateOnSave')->with([3, 1]);

        $plugin = new ProductActionChangePlugin($this->scheduler);
        self::assertSame($this->action, $plugin->afterUpdateAttributes($this->action, $this->action, [3, 1], [], 1));
    }

    public function testWebsiteUpdateSchedulesIdsAfterAction(): void
    {
        $this->scheduler->expects(self::once())->method('scheduleProductsIfUpdateOnSave')->with([7]);

        $plugin = new ProductActionChangePlugin($this->scheduler);
        self::assertNull($plugin->afterUpdateWebsites($this->action, null, [7], [1], 'add'));
    }
}
