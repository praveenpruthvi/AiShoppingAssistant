<?php

declare(strict_types=1);

namespace Aavirbhava\AiShoppingAssistant\Test\Unit\Model\Tool;

use Aavirbhava\AiShoppingAssistant\Api\Config\CapabilitiesConfigInterface;
use Aavirbhava\AiShoppingAssistant\Api\Config\ConfigurationReaderInterface;
use Aavirbhava\AiShoppingAssistant\Api\Revalidation\LiveRevalidationServiceInterface;
use Aavirbhava\AiShoppingAssistant\Model\Revalidation\RevalidatedProduct;
use Aavirbhava\AiShoppingAssistant\Model\Tool\CompareProductsTool;
use Aavirbhava\AiShoppingAssistant\Model\Tool\Exception\ToolAuthorizationException;
use Aavirbhava\AiShoppingAssistant\Model\Tool\ProductFormatter;
use Aavirbhava\AiShoppingAssistant\Model\Tool\SkuListParser;
use Aavirbhava\AiShoppingAssistant\Model\Tool\ToolContext;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(CompareProductsTool::class)]
final class CompareProductsToolTest extends TestCase
{
    private const STORE_ID = 5;

    public function testNameAndSchema(): void
    {
        $tool = $this->tool();

        self::assertSame('compare_products', $tool->name());
        self::assertSame(['skus'], $tool->inputSchema()['required']);
    }

    public function testAuthorizeThrowsWhenComparisonIsDisabled(): void
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

    public function testExecuteRejectsMoreThanFiveSkus(): void
    {
        $tool = $this->tool();

        $result = $tool->execute(
            new ToolContext(self::STORE_ID, null),
            ['skus' => ['SKU-1', 'SKU-2', 'SKU-3', 'SKU-4', 'SKU-5', 'SKU-6']]
        );

        self::assertArrayHasKey('error', $result->data);
    }

    public function testExecuteReportsProductsAndNotFoundSeparately(): void
    {
        $verified = new RevalidatedProduct(1, 'SKU-1', 'Blue Shoe', 49.99, null, 'https://store.test/blue-shoe', '2026-08-16T00:00:00+00:00');

        $revalidationService = $this->createMock(LiveRevalidationServiceInterface::class);
        $revalidationService->method('revalidate')
            ->with(self::STORE_ID, null, ['SKU-1', 'SKU-GONE'])
            ->willReturn([$verified]);

        $tool = $this->tool(revalidationService: $revalidationService);

        $result = $tool->execute(new ToolContext(self::STORE_ID, null), ['skus' => ['SKU-1', 'SKU-GONE']]);

        self::assertCount(1, $result->data['products']);
        self::assertSame('SKU-1', $result->data['products'][0]['sku']);
        self::assertSame(['SKU-GONE'], $result->data['not_found']);
        self::assertSame([$verified], $result->verifiedProducts);
    }

    private function tool(bool $enabled = true, ?LiveRevalidationServiceInterface $revalidationService = null): CompareProductsTool
    {
        $capabilities = $this->createMock(CapabilitiesConfigInterface::class);
        $capabilities->method('isComparisonEnabled')->willReturn($enabled);

        $configurationReader = $this->createMock(ConfigurationReaderInterface::class);
        $configurationReader->method('readCapabilities')->with(self::STORE_ID)->willReturn($capabilities);

        $revalidationService ??= $this->createMock(LiveRevalidationServiceInterface::class);

        return new CompareProductsTool($configurationReader, $revalidationService, new ProductFormatter(), new SkuListParser());
    }
}
