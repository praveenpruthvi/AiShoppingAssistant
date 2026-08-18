<?php

declare(strict_types=1);

namespace Aavirbhava\AiShoppingAssistant\Test\Unit\Model\Tool;

use Aavirbhava\AiShoppingAssistant\Api\Cart\CartResolverInterface;
use Aavirbhava\AiShoppingAssistant\Api\Config\ConfigurationReaderInterface;
use Aavirbhava\AiShoppingAssistant\Api\Config\GuardrailConfigInterface;
use Aavirbhava\AiShoppingAssistant\Api\Revalidation\LiveRevalidationServiceInterface;
use Aavirbhava\AiShoppingAssistant\Model\Cart\Exception\CartNotAvailableException;
use Aavirbhava\AiShoppingAssistant\Model\Revalidation\RevalidatedProduct;
use Aavirbhava\AiShoppingAssistant\Model\Tool\Exception\ToolAuthorizationException;
use Aavirbhava\AiShoppingAssistant\Model\Tool\GetCartTool;
use Aavirbhava\AiShoppingAssistant\Model\Tool\ToolContext;
use Magento\Framework\Phrase;
use Magento\Quote\Api\CartTotalRepositoryInterface;
use Magento\Quote\Api\Data\CartInterface;
use Magento\Quote\Api\Data\CartItemInterface;
use Magento\Quote\Api\Data\TotalsInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(GetCartTool::class)]
final class GetCartToolTest extends TestCase
{
    private const STORE_ID = 5;

    public function testNameAndSchema(): void
    {
        $tool = $this->tool();

        self::assertSame('get_cart', $tool->name());
        self::assertSame([], $tool->inputSchema()['required']);
    }

    /**
     * A plain PHP `[]` for `properties` json_encode()s as a JSON array,
     * which is invalid for JSON Schema (must be an object, even when
     * empty) — OpenAI's real API tolerates this, but a real, live-tested
     * Ollama instance rejects the entire chat request outright the moment
     * get_cart (the only zero-argument tool) is offered. Regression test
     * for that real, live-confirmed bug.
     */
    public function testPropertiesEncodesAsAJsonObjectNotArray(): void
    {
        $tool = $this->tool();

        self::assertSame('{}', json_encode($tool->inputSchema()['properties']));
    }

    public function testAuthorizeThrowsWhenCartMutationsAreDisabled(): void
    {
        $tool = $this->tool(cartMutationsEnabled: false);

        $this->expectException(ToolAuthorizationException::class);
        $tool->authorize(new ToolContext(self::STORE_ID, null));
    }

    public function testExecuteReportsCartNotAvailableWhenNoCartCanBeResolved(): void
    {
        $cartResolver = $this->createMock(CartResolverInterface::class);
        $cartResolver->method('resolve')->willThrowException(new CartNotAvailableException(new Phrase('none')));

        $tool = $this->tool(cartResolver: $cartResolver);

        $result = $tool->execute(new ToolContext(self::STORE_ID, null), []);

        self::assertSame(['status' => 'cart_not_available'], $result->data);
        self::assertSame([], $result->verifiedProducts);
    }

    public function testExecuteReturnsLiveDataForAnAvailableLineItem(): void
    {
        $item = $this->cartItem('SKU-1', 2.0, 'Stale Name', 10.0);
        $cart = $this->cart([$item]);

        $cartResolver = $this->createMock(CartResolverInterface::class);
        $cartResolver->method('resolve')->willReturn($cart);

        $verified = new RevalidatedProduct(1, 'SKU-1', 'Blue Shoe', 49.99, 39.99, 'https://store.test/blue-shoe', '2026-08-16T00:00:00+00:00');
        $revalidationService = $this->createMock(LiveRevalidationServiceInterface::class);
        $revalidationService->method('revalidate')->with(self::STORE_ID, null, ['SKU-1'])->willReturn([$verified]);

        $totals = $this->totals(2.0, 49.99, 49.99);
        $cartTotalRepository = $this->createMock(CartTotalRepositoryInterface::class);
        $cartTotalRepository->method('get')->with(77)->willReturn($totals);

        $tool = $this->tool(
            cartResolver: $cartResolver,
            revalidationService: $revalidationService,
            cartTotalRepository: $cartTotalRepository
        );

        $result = $tool->execute(new ToolContext(self::STORE_ID, null), []);

        self::assertSame('ok', $result->data['status']);
        self::assertCount(1, $result->data['items']);
        self::assertTrue($result->data['items'][0]['currently_available']);
        self::assertSame('Blue Shoe', $result->data['items'][0]['name']);
        self::assertSame(49.99, $result->data['items'][0]['price']);
        self::assertSame(2.0, $result->data['items'][0]['qty']);
        self::assertSame([$verified], $result->verifiedProducts);
    }

    public function testExecuteStillReportsALineItemThatNoLongerPassesRevalidation(): void
    {
        $item = $this->cartItem('SKU-GONE', 1.0, 'Discontinued Widget', 12.5);
        $cart = $this->cart([$item]);

        $cartResolver = $this->createMock(CartResolverInterface::class);
        $cartResolver->method('resolve')->willReturn($cart);

        $revalidationService = $this->createMock(LiveRevalidationServiceInterface::class);
        $revalidationService->method('revalidate')->willReturn([]);

        $cartTotalRepository = $this->createMock(CartTotalRepositoryInterface::class);
        $cartTotalRepository->method('get')->willReturn($this->totals(1.0, 12.5, 12.5));

        $tool = $this->tool(
            cartResolver: $cartResolver,
            revalidationService: $revalidationService,
            cartTotalRepository: $cartTotalRepository
        );

        $result = $tool->execute(new ToolContext(self::STORE_ID, null), []);

        self::assertCount(1, $result->data['items']);
        self::assertFalse($result->data['items'][0]['currently_available']);
        self::assertSame('Discontinued Widget', $result->data['items'][0]['name']);
        self::assertSame(12.5, $result->data['items'][0]['price']);
        self::assertSame([], $result->verifiedProducts);
    }

    private function cartItem(string $sku, float $qty, string $name, float $price): CartItemInterface
    {
        $item = $this->createMock(CartItemInterface::class);
        $item->method('getSku')->willReturn($sku);
        $item->method('getQty')->willReturn($qty);
        $item->method('getName')->willReturn($name);
        $item->method('getPrice')->willReturn($price);

        return $item;
    }

    /**
     * @param list<CartItemInterface> $items
     */
    private function cart(array $items): CartInterface
    {
        $cart = $this->createMock(CartInterface::class);
        $cart->method('getId')->willReturn(77);
        $cart->method('getItems')->willReturn($items);

        return $cart;
    }

    private function totals(float $itemsQty, float $subtotal, float $grandTotal): TotalsInterface
    {
        $totals = $this->createMock(TotalsInterface::class);
        $totals->method('getItemsQty')->willReturn($itemsQty);
        $totals->method('getSubtotal')->willReturn($subtotal);
        $totals->method('getGrandTotal')->willReturn($grandTotal);

        return $totals;
    }

    private function tool(
        bool $cartMutationsEnabled = true,
        ?CartResolverInterface $cartResolver = null,
        ?LiveRevalidationServiceInterface $revalidationService = null,
        ?CartTotalRepositoryInterface $cartTotalRepository = null
    ): GetCartTool {
        $guardrails = $this->createMock(GuardrailConfigInterface::class);
        $guardrails->method('areCartMutationsEnabled')->willReturn($cartMutationsEnabled);

        $configurationReader = $this->createMock(ConfigurationReaderInterface::class);
        $configurationReader->method('readGuardrails')->with(self::STORE_ID)->willReturn($guardrails);

        return new GetCartTool(
            $configurationReader,
            $cartResolver ?? $this->createMock(CartResolverInterface::class),
            $revalidationService ?? $this->createMock(LiveRevalidationServiceInterface::class),
            $cartTotalRepository ?? $this->createMock(CartTotalRepositoryInterface::class)
        );
    }
}
