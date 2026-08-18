<?php

declare(strict_types=1);

namespace Aavirbhava\AiShoppingAssistant\Test\Unit\Model\Tool;

use Aavirbhava\AiShoppingAssistant\Api\Config\CapabilitiesConfigInterface;
use Aavirbhava\AiShoppingAssistant\Api\Config\ConfigurationReaderInterface;
use Aavirbhava\AiShoppingAssistant\Api\Revalidation\LiveRevalidationServiceInterface;
use Aavirbhava\AiShoppingAssistant\Model\Revalidation\AvailabilityStatus;
use Aavirbhava\AiShoppingAssistant\Model\Revalidation\RevalidatedProduct;
use Aavirbhava\AiShoppingAssistant\Model\Tool\CheckInventoryTool;
use Aavirbhava\AiShoppingAssistant\Model\Tool\Exception\ToolAuthorizationException;
use Aavirbhava\AiShoppingAssistant\Model\Tool\SkuListParser;
use Aavirbhava\AiShoppingAssistant\Model\Tool\ToolContext;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(CheckInventoryTool::class)]
final class CheckInventoryToolTest extends TestCase
{
    private const STORE_ID = 5;

    public function testNameAndSchema(): void
    {
        $tool = $this->tool();

        self::assertSame('check_inventory', $tool->name());
        self::assertSame(['skus'], $tool->inputSchema()['required']);
    }

    public function testAuthorizeThrowsWhenStockCheckingIsDisabled(): void
    {
        $tool = $this->tool(enabled: false);

        $this->expectException(ToolAuthorizationException::class);
        $tool->authorize(new ToolContext(self::STORE_ID, null));
    }

    public function testExecuteRejectsAMissingSkuList(): void
    {
        $tool = $this->tool();

        $result = $tool->execute(new ToolContext(self::STORE_ID, null), []);

        self::assertArrayHasKey('error', $result->data);
    }

    public function testExecuteRejectsMoreThanTenSkus(): void
    {
        $tool = $this->tool();

        $skus = array_map(static fn (int $i): string => "SKU-$i", range(1, 11));

        $result = $tool->execute(new ToolContext(self::STORE_ID, null), ['skus' => $skus]);

        self::assertArrayHasKey('error', $result->data);
    }

    public function testExecuteReportsFoundNotFoundAndInStockDistinctly(): void
    {
        $inStock = new AvailabilityStatus('SKU-1', true, true, 'Blue Shoe');
        $outOfStock = new AvailabilityStatus('SKU-2', true, false, 'Red Hat');
        $notFound = new AvailabilityStatus('SKU-GONE', false, false, null);

        $verified = new RevalidatedProduct(1, 'SKU-1', 'Blue Shoe', 49.99, null, 'https://store.test/blue-shoe', '2026-08-16T00:00:00+00:00');

        $revalidationService = $this->createMock(LiveRevalidationServiceInterface::class);
        $revalidationService->method('checkAvailability')
            ->with(self::STORE_ID, null, ['SKU-1', 'SKU-2', 'SKU-GONE'])
            ->willReturn([$inStock, $outOfStock, $notFound]);
        $revalidationService->method('revalidate')
            ->with(self::STORE_ID, null, ['SKU-1', 'SKU-2', 'SKU-GONE'])
            ->willReturn([$verified]);

        $tool = $this->tool(revalidationService: $revalidationService);

        $result = $tool->execute(new ToolContext(self::STORE_ID, null), ['skus' => ['SKU-1', 'SKU-2', 'SKU-GONE']]);

        self::assertSame(
            [
                ['sku' => 'SKU-1', 'found' => true, 'in_stock' => true, 'name' => 'Blue Shoe'],
                ['sku' => 'SKU-2', 'found' => true, 'in_stock' => false, 'name' => 'Red Hat'],
                ['sku' => 'SKU-GONE', 'found' => false, 'in_stock' => false, 'name' => null],
            ],
            $result->data['items']
        );
        self::assertSame([$verified], $result->verifiedProducts);
    }

    private function tool(bool $enabled = true, ?LiveRevalidationServiceInterface $revalidationService = null): CheckInventoryTool
    {
        $capabilities = $this->createMock(CapabilitiesConfigInterface::class);
        $capabilities->method('isStockCheckingEnabled')->willReturn($enabled);

        $configurationReader = $this->createMock(ConfigurationReaderInterface::class);
        $configurationReader->method('readCapabilities')->with(self::STORE_ID)->willReturn($capabilities);

        $revalidationService ??= $this->createMock(LiveRevalidationServiceInterface::class);

        return new CheckInventoryTool($configurationReader, $revalidationService, new SkuListParser());
    }
}
