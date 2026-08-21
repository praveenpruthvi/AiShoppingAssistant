<?php

declare(strict_types=1);

namespace Aavirbhava\AiShoppingAssistant\Test\Unit\Model\Tool;

use Aavirbhava\AiShoppingAssistant\Api\Config\CapabilitiesConfigInterface;
use Aavirbhava\AiShoppingAssistant\Api\Config\ConfigurationReaderInterface;
use Aavirbhava\AiShoppingAssistant\Api\Promotion\ActivePromotionReaderInterface;
use Aavirbhava\AiShoppingAssistant\Api\Revalidation\LiveRevalidationServiceInterface;
use Aavirbhava\AiShoppingAssistant\Model\Promotion\CartPromotion;
use Aavirbhava\AiShoppingAssistant\Model\Promotion\ProductPromotion;
use Aavirbhava\AiShoppingAssistant\Model\Revalidation\RevalidatedProduct;
use Aavirbhava\AiShoppingAssistant\Model\Tool\Exception\ToolAuthorizationException;
use Aavirbhava\AiShoppingAssistant\Model\Tool\GetActivePromotionsTool;
use Aavirbhava\AiShoppingAssistant\Model\Tool\SkuListParser;
use Aavirbhava\AiShoppingAssistant\Model\Tool\ToolContext;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(GetActivePromotionsTool::class)]
final class GetActivePromotionsToolTest extends TestCase
{
    private const STORE_ID = 5;

    public function testNameAndSchema(): void
    {
        $tool = $this->tool();

        self::assertSame('get_active_promotions', $tool->name());
        self::assertSame([], $tool->inputSchema()['required']);
    }

    public function testAuthorizeThrowsWhenPromotionAwarenessIsDisabled(): void
    {
        $tool = $this->tool(enabled: false);

        $this->expectException(ToolAuthorizationException::class);
        $tool->authorize(new ToolContext(self::STORE_ID, null));
    }

    public function testExecuteWithNoSkusStillReturnsActiveCartRules(): void
    {
        $cartRule = new CartPromotion(1, 'Storewide Sale', false, null, '15% off', null);
        $promotionReader = $this->createMock(ActivePromotionReaderInterface::class);
        $promotionReader->method('catalogRuleDiscounts')->willReturn([]);
        $promotionReader->method('activeCartRules')->willReturn([$cartRule]);

        $tool = $this->tool(promotionReader: $promotionReader);

        $result = $tool->execute(new ToolContext(self::STORE_ID, null), []);

        self::assertSame(
            [['name' => 'Storewide Sale', 'requires_coupon' => false, 'coupon_code' => null, 'discount_description' => '15% off', 'to_date' => null]],
            $result->data['cart_rules']
        );
        self::assertSame([], $result->data['product_discounts']);
        self::assertSame([$cartRule], $result->verifiedCartPromotions);
    }

    public function testExecuteRejectsMoreThanTenSkus(): void
    {
        $tool = $this->tool();

        $skus = array_map(static fn (int $i): string => "SKU-$i", range(1, 11));

        $result = $tool->execute(new ToolContext(self::STORE_ID, null), ['skus' => $skus]);

        self::assertArrayHasKey('error', $result->data);
    }

    public function testExecuteRejectsAMalformedSkuList(): void
    {
        $tool = $this->tool();

        $result = $tool->execute(new ToolContext(self::STORE_ID, null), ['skus' => ['', 'SKU-1']]);

        self::assertArrayHasKey('error', $result->data);
    }

    public function testExecuteReportsCatalogDiscountsAndNotFoundSkusSeparately(): void
    {
        $verified = new RevalidatedProduct(1, 'SKU-1', 'Blue Shoe', 50.00, null, 'https://store.test/blue-shoe', '2026-08-16T00:00:00+00:00');
        $discount = new ProductPromotion('SKU-1', 50.00, 40.00);

        $revalidationService = $this->createMock(LiveRevalidationServiceInterface::class);
        $revalidationService->method('revalidate')
            ->with(self::STORE_ID, 42, ['SKU-1', 'SKU-GONE'])
            ->willReturn([$verified]);

        $promotionReader = $this->createMock(ActivePromotionReaderInterface::class);
        $promotionReader->method('catalogRuleDiscounts')
            ->with(self::STORE_ID, 42, [$verified])
            ->willReturn(['SKU-1' => $discount]);
        $promotionReader->method('activeCartRules')->willReturn([]);

        $tool = $this->tool(revalidationService: $revalidationService, promotionReader: $promotionReader);

        $result = $tool->execute(new ToolContext(self::STORE_ID, 42), ['skus' => ['SKU-1', 'SKU-GONE']]);

        self::assertSame(
            [['sku' => 'SKU-1', 'regular_price' => 50.00, 'discounted_price' => 40.00, 'percent_off' => 20.0]],
            $result->data['product_discounts']
        );
        self::assertSame(['SKU-GONE'], $result->data['not_found']);
        self::assertSame([$verified], $result->verifiedProducts);
        self::assertSame([$discount], $result->verifiedProductPromotions);
    }

    private function tool(
        bool $enabled = true,
        ?LiveRevalidationServiceInterface $revalidationService = null,
        ?ActivePromotionReaderInterface $promotionReader = null
    ): GetActivePromotionsTool {
        $capabilities = $this->createMock(CapabilitiesConfigInterface::class);
        $capabilities->method('isPromotionAwarenessEnabled')->willReturn($enabled);

        $configurationReader = $this->createMock(ConfigurationReaderInterface::class);
        $configurationReader->method('readCapabilities')->with(self::STORE_ID)->willReturn($capabilities);

        $revalidationService ??= $this->createMock(LiveRevalidationServiceInterface::class);
        $promotionReader ??= $this->createMock(ActivePromotionReaderInterface::class);

        return new GetActivePromotionsTool($configurationReader, $revalidationService, $promotionReader, new SkuListParser());
    }
}
