<?php

declare(strict_types=1);

namespace Aavirbhava\AiShoppingAssistant\Test\Unit\Model\Tool;

use Aavirbhava\AiShoppingAssistant\Api\Config\CapabilitiesConfigInterface;
use Aavirbhava\AiShoppingAssistant\Api\Config\ConfigurationReaderInterface;
use Aavirbhava\AiShoppingAssistant\Api\Revalidation\LiveRevalidationServiceInterface;
use Aavirbhava\AiShoppingAssistant\Model\Revalidation\RevalidatedProduct;
use Aavirbhava\AiShoppingAssistant\Model\Tool\CheckPriceTool;
use Aavirbhava\AiShoppingAssistant\Model\Tool\Exception\ToolAuthorizationException;
use Aavirbhava\AiShoppingAssistant\Model\Tool\SkuListParser;
use Aavirbhava\AiShoppingAssistant\Model\Tool\ToolContext;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(CheckPriceTool::class)]
final class CheckPriceToolTest extends TestCase
{
    private const STORE_ID = 5;

    public function testNameAndSchema(): void
    {
        $tool = $this->tool();

        self::assertSame('check_price', $tool->name());
        self::assertSame(['skus'], $tool->inputSchema()['required']);
    }

    public function testAuthorizeThrowsWhenPriceCheckingIsDisabled(): void
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

    public function testExecuteReportsPricesAndNotFoundSeparately(): void
    {
        $verified = new RevalidatedProduct(1, 'SKU-1', 'Blue Shoe', 49.99, 39.99, 'https://store.test/blue-shoe', '2026-08-16T00:00:00+00:00');

        $revalidationService = $this->createMock(LiveRevalidationServiceInterface::class);
        $revalidationService->method('revalidate')
            ->with(self::STORE_ID, 42, ['SKU-1', 'SKU-GONE'])
            ->willReturn([$verified]);

        $tool = $this->tool(revalidationService: $revalidationService);

        $result = $tool->execute(new ToolContext(self::STORE_ID, 42), ['skus' => ['SKU-1', 'SKU-GONE']]);

        self::assertSame(
            [['sku' => 'SKU-1', 'price' => 49.99, 'special_price' => 39.99]],
            $result->data['prices']
        );
        self::assertSame(['SKU-GONE'], $result->data['not_found']);
        self::assertSame([$verified], $result->verifiedProducts);
    }

    private function tool(bool $enabled = true, ?LiveRevalidationServiceInterface $revalidationService = null): CheckPriceTool
    {
        $capabilities = $this->createMock(CapabilitiesConfigInterface::class);
        $capabilities->method('isPriceCheckingEnabled')->willReturn($enabled);

        $configurationReader = $this->createMock(ConfigurationReaderInterface::class);
        $configurationReader->method('readCapabilities')->with(self::STORE_ID)->willReturn($capabilities);

        $revalidationService ??= $this->createMock(LiveRevalidationServiceInterface::class);

        return new CheckPriceTool($configurationReader, $revalidationService, new SkuListParser());
    }
}
