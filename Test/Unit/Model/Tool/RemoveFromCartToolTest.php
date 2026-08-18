<?php

declare(strict_types=1);

namespace Aavirbhava\AiShoppingAssistant\Test\Unit\Model\Tool;

use Aavirbhava\AiShoppingAssistant\Api\Cart\CartResolverInterface;
use Aavirbhava\AiShoppingAssistant\Api\Config\ConfigurationReaderInterface;
use Aavirbhava\AiShoppingAssistant\Api\Config\GuardrailConfigInterface;
use Aavirbhava\AiShoppingAssistant\Model\Cart\Exception\CartNotAvailableException;
use Aavirbhava\AiShoppingAssistant\Model\Tool\CartMutationConfirmationService;
use Aavirbhava\AiShoppingAssistant\Model\Tool\Exception\ToolAuthorizationException;
use Aavirbhava\AiShoppingAssistant\Model\Tool\RemoveFromCartTool;
use Aavirbhava\AiShoppingAssistant\Model\Tool\ToolContext;
use Magento\Framework\App\CacheInterface;
use Magento\Framework\Phrase;
use Magento\Quote\Api\CartItemRepositoryInterface;
use Magento\Quote\Api\Data\CartInterface;
use Magento\Quote\Api\Data\CartItemInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

#[CoversClass(RemoveFromCartTool::class)]
final class RemoveFromCartToolTest extends TestCase
{
    private const STORE_ID = 5;

    /**
     * @var array<string, string>
     */
    private array $cacheStore = [];

    public function testNameAndSchema(): void
    {
        $tool = $this->tool();

        self::assertSame('remove_from_cart', $tool->name());
        self::assertSame(['sku'], $tool->inputSchema()['required']);
        self::assertArrayHasKey('confirmation_token', $tool->inputSchema()['properties']);
    }

    public function testAuthorizeThrowsWhenCartMutationsAreDisabled(): void
    {
        $tool = $this->tool(cartMutationsEnabled: false);

        $this->expectException(ToolAuthorizationException::class);
        $tool->authorize(new ToolContext(self::STORE_ID, null));
    }

    public function testExecuteRejectsAMissingSku(): void
    {
        $tool = $this->tool();

        $result = $tool->execute(new ToolContext(self::STORE_ID, null), []);

        self::assertSame(['status' => 'rejected', 'reason' => 'invalid_arguments'], $result->data);
    }

    public function testExecuteReportsCartNotAvailable(): void
    {
        $cartResolver = $this->createMock(CartResolverInterface::class);
        $cartResolver->method('resolve')->willThrowException(new CartNotAvailableException(new Phrase('none')));

        $tool = $this->tool(cartResolver: $cartResolver);

        $result = $tool->execute(new ToolContext(self::STORE_ID, null), ['sku' => 'SKU-1']);

        self::assertSame(['status' => 'cart_not_available'], $result->data);
    }

    public function testExecuteReportsNotInCartWithoutAskingForConfirmation(): void
    {
        $cartResolver = $this->createMock(CartResolverInterface::class);
        $cartResolver->method('resolve')->willReturn($this->cart([]));

        $tool = $this->tool(cartResolver: $cartResolver, requireConfirmation: true);

        $result = $tool->execute(new ToolContext(self::STORE_ID, null), ['sku' => 'SKU-NOT-PRESENT']);

        self::assertSame(['status' => 'not_in_cart', 'sku' => 'SKU-NOT-PRESENT'], $result->data);
    }

    public function testFirstCallWithConfirmationRequiredNeverRemovesTheItem(): void
    {
        $item = $this->cartItem('SKU-1', 10);
        $cart = $this->cart([$item]);

        $cartResolver = $this->createMock(CartResolverInterface::class);
        $cartResolver->method('resolve')->willReturn($cart);

        $cartItemRepository = $this->createMock(CartItemRepositoryInterface::class);
        $cartItemRepository->expects(self::never())->method('deleteById');

        $tool = $this->tool(cartResolver: $cartResolver, cartItemRepository: $cartItemRepository, requireConfirmation: true);

        $result = $tool->execute(new ToolContext(self::STORE_ID, null), ['sku' => 'SKU-1']);

        self::assertSame('confirmation_required', $result->data['status']);
        self::assertArrayHasKey('confirmation_token', $result->data);
    }

    public function testAValidConfirmationTokenFromALaterTurnExecutesTheRemoval(): void
    {
        $item = $this->cartItem('SKU-1', 10);
        $cart = $this->cart([$item]);

        $cartResolver = $this->createMock(CartResolverInterface::class);
        $cartResolver->method('resolve')->willReturn($cart);

        $cartItemRepository = $this->createMock(CartItemRepositoryInterface::class);
        $cartItemRepository->expects(self::once())->method('deleteById')->with(77, 10);

        $tool = $this->tool(cartResolver: $cartResolver, cartItemRepository: $cartItemRepository, requireConfirmation: true);

        $firstContext = new ToolContext(self::STORE_ID, null, null, 'turn-1');
        $proposeResult = $tool->execute($firstContext, ['sku' => 'SKU-1']);
        $token = $proposeResult->data['confirmation_token'];

        $secondContext = new ToolContext(self::STORE_ID, null, null, 'turn-2');
        $confirmResult = $tool->execute($secondContext, ['sku' => 'SKU-1', 'confirmation_token' => $token]);

        self::assertSame(['status' => 'removed', 'sku' => 'SKU-1'], $confirmResult->data);
    }

    public function testRedeemingTheTokenInTheSameTurnDoesNotExecuteAndReturnsAFreshConfirmationRequest(): void
    {
        $item = $this->cartItem('SKU-1', 10);
        $cart = $this->cart([$item]);

        $cartResolver = $this->createMock(CartResolverInterface::class);
        $cartResolver->method('resolve')->willReturn($cart);

        $cartItemRepository = $this->createMock(CartItemRepositoryInterface::class);
        $cartItemRepository->expects(self::never())->method('deleteById');

        $tool = $this->tool(cartResolver: $cartResolver, cartItemRepository: $cartItemRepository, requireConfirmation: true);

        $context = new ToolContext(self::STORE_ID, null, null, 'same-turn');
        $proposeResult = $tool->execute($context, ['sku' => 'SKU-1']);
        $token = $proposeResult->data['confirmation_token'];

        $secondAttempt = $tool->execute($context, ['sku' => 'SKU-1', 'confirmation_token' => $token]);

        self::assertSame('confirmation_required', $secondAttempt->data['status']);
    }

    public function testExecutesImmediatelyWhenConfirmationIsNotRequired(): void
    {
        $item = $this->cartItem('SKU-1', 10);
        $cart = $this->cart([$item]);

        $cartResolver = $this->createMock(CartResolverInterface::class);
        $cartResolver->method('resolve')->willReturn($cart);

        $cartItemRepository = $this->createMock(CartItemRepositoryInterface::class);
        $cartItemRepository->expects(self::once())->method('deleteById')->with(77, 10);

        $tool = $this->tool(cartResolver: $cartResolver, cartItemRepository: $cartItemRepository, requireConfirmation: false);

        $result = $tool->execute(new ToolContext(self::STORE_ID, null), ['sku' => 'SKU-1']);

        self::assertSame(['status' => 'removed', 'sku' => 'SKU-1'], $result->data);
    }

    public function testCartUpdateFailureIsReportedCleanlyNotThrown(): void
    {
        $item = $this->cartItem('SKU-1', 10);
        $cart = $this->cart([$item]);

        $cartResolver = $this->createMock(CartResolverInterface::class);
        $cartResolver->method('resolve')->willReturn($cart);

        $cartItemRepository = $this->createMock(CartItemRepositoryInterface::class);
        $cartItemRepository->method('deleteById')->willThrowException(new \RuntimeException('db exploded'));

        $tool = $this->tool(cartResolver: $cartResolver, cartItemRepository: $cartItemRepository, requireConfirmation: false);

        $result = $tool->execute(new ToolContext(self::STORE_ID, null), ['sku' => 'SKU-1']);

        self::assertSame(['status' => 'failed', 'reason' => 'cart_update_failed', 'sku' => 'SKU-1'], $result->data);
    }

    private function cartItem(string $sku, int $itemId): CartItemInterface
    {
        $item = $this->createMock(CartItemInterface::class);
        $item->method('getSku')->willReturn($sku);
        $item->method('getItemId')->willReturn($itemId);

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

    private function tool(
        bool $cartMutationsEnabled = true,
        bool $requireConfirmation = false,
        ?CartResolverInterface $cartResolver = null,
        ?CartItemRepositoryInterface $cartItemRepository = null
    ): RemoveFromCartTool {
        $guardrails = $this->createMock(GuardrailConfigInterface::class);
        $guardrails->method('areCartMutationsEnabled')->willReturn($cartMutationsEnabled);
        $guardrails->method('requiresCartConfirmation')->willReturn($requireConfirmation);

        $configurationReader = $this->createMock(ConfigurationReaderInterface::class);
        $configurationReader->method('readGuardrails')->with(self::STORE_ID)->willReturn($guardrails);

        return new RemoveFromCartTool(
            $configurationReader,
            $cartResolver ?? $this->createMock(CartResolverInterface::class),
            $this->confirmationService(),
            $cartItemRepository ?? $this->createMock(CartItemRepositoryInterface::class),
            $this->createMock(LoggerInterface::class)
        );
    }

    private function confirmationService(): CartMutationConfirmationService
    {
        $cache = $this->createMock(CacheInterface::class);
        $cache->method('load')->willReturnCallback(
            fn (string $id) => $this->cacheStore[$id] ?? false
        );
        $cache->method('save')->willReturnCallback(
            function (string $data, string $id) {
                $this->cacheStore[$id] = $data;

                return true;
            }
        );
        $cache->method('remove')->willReturnCallback(
            function (string $id) {
                unset($this->cacheStore[$id]);

                return true;
            }
        );

        return new CartMutationConfirmationService($cache);
    }
}
