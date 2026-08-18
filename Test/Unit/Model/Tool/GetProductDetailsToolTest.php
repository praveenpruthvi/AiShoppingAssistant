<?php

declare(strict_types=1);

namespace Aavirbhava\AiShoppingAssistant\Test\Unit\Model\Tool;

use Aavirbhava\AiShoppingAssistant\Api\Config\CapabilitiesConfigInterface;
use Aavirbhava\AiShoppingAssistant\Api\Config\ConfigurationReaderInterface;
use Aavirbhava\AiShoppingAssistant\Api\Revalidation\LiveRevalidationServiceInterface;
use Aavirbhava\AiShoppingAssistant\Model\Revalidation\RevalidatedProduct;
use Aavirbhava\AiShoppingAssistant\Model\Tool\Exception\ToolAuthorizationException;
use Aavirbhava\AiShoppingAssistant\Model\Tool\GetProductDetailsTool;
use Aavirbhava\AiShoppingAssistant\Model\Tool\ProductFormatter;
use Aavirbhava\AiShoppingAssistant\Model\Tool\ToolContext;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(GetProductDetailsTool::class)]
final class GetProductDetailsToolTest extends TestCase
{
    private const STORE_ID = 5;

    public function testNameAndSchema(): void
    {
        $tool = $this->tool();

        self::assertSame('get_product_details', $tool->name());
        self::assertSame(['sku'], $tool->inputSchema()['required']);
    }

    public function testAuthorizeThrowsWhenProductDetailsAreDisabled(): void
    {
        $tool = $this->tool(enabled: false);

        $this->expectException(ToolAuthorizationException::class);
        $tool->authorize(new ToolContext(self::STORE_ID, null));
    }

    public function testExecuteRejectsAMissingSku(): void
    {
        $tool = $this->tool();

        $result = $tool->execute(new ToolContext(self::STORE_ID, null), []);

        self::assertArrayHasKey('error', $result->data);
    }

    public function testExecuteReportsNotFoundWhenRevalidationReturnsNothing(): void
    {
        $revalidationService = $this->createMock(LiveRevalidationServiceInterface::class);
        $revalidationService->method('revalidate')->willReturn([]);

        $tool = $this->tool(revalidationService: $revalidationService);

        $result = $tool->execute(new ToolContext(self::STORE_ID, null), ['sku' => 'SKU-GONE']);

        self::assertSame(['found' => false], $result->data);
        self::assertSame([], $result->verifiedProducts);
    }

    public function testExecuteReturnsFormattedDetailsForAVerifiedSku(): void
    {
        $verified = new RevalidatedProduct(1, 'SKU-1', 'Blue Shoe', 49.99, null, 'https://store.test/blue-shoe', '2026-08-16T00:00:00+00:00');

        $revalidationService = $this->createMock(LiveRevalidationServiceInterface::class);
        $revalidationService->method('revalidate')->with(self::STORE_ID, null, ['SKU-1'])->willReturn([$verified]);

        $tool = $this->tool(revalidationService: $revalidationService);

        $result = $tool->execute(new ToolContext(self::STORE_ID, null), ['sku' => 'SKU-1']);

        self::assertTrue($result->data['found']);
        self::assertSame('SKU-1', $result->data['product']['sku']);
        self::assertSame([$verified], $result->verifiedProducts);
    }

    private function tool(bool $enabled = true, ?LiveRevalidationServiceInterface $revalidationService = null): GetProductDetailsTool
    {
        $capabilities = $this->createMock(CapabilitiesConfigInterface::class);
        $capabilities->method('isProductDetailsEnabled')->willReturn($enabled);

        $configurationReader = $this->createMock(ConfigurationReaderInterface::class);
        $configurationReader->method('readCapabilities')->with(self::STORE_ID)->willReturn($capabilities);

        $revalidationService ??= $this->createMock(LiveRevalidationServiceInterface::class);

        return new GetProductDetailsTool($configurationReader, $revalidationService, new ProductFormatter());
    }
}
