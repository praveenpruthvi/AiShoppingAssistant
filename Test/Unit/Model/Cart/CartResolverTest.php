<?php

declare(strict_types=1);

namespace Aavirbhava\AiShoppingAssistant\Test\Unit\Model\Cart;

use Aavirbhava\AiShoppingAssistant\Model\Cart\CartResolver;
use Aavirbhava\AiShoppingAssistant\Model\Cart\Exception\CartNotAvailableException;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Framework\Phrase;
use Magento\Quote\Api\CartRepositoryInterface;
use Magento\Quote\Api\Data\CartInterface;
use Magento\Quote\Model\MaskedQuoteIdToQuoteIdInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(CartResolver::class)]
final class CartResolverTest extends TestCase
{
    private const STORE_ID = 5;

    public function testRejectsANullCartIdWithoutCallingMagento(): void
    {
        $maskedQuoteIdToQuoteId = $this->createMock(MaskedQuoteIdToQuoteIdInterface::class);
        $maskedQuoteIdToQuoteId->expects(self::never())->method('execute');
        $cartRepository = $this->createMock(CartRepositoryInterface::class);
        $cartRepository->expects(self::never())->method('get');

        $resolver = new CartResolver($maskedQuoteIdToQuoteId, $cartRepository);

        $this->expectException(CartNotAvailableException::class);
        $resolver->resolve(self::STORE_ID, null);
    }

    public function testRejectsABlankCartIdWithoutCallingMagento(): void
    {
        $maskedQuoteIdToQuoteId = $this->createMock(MaskedQuoteIdToQuoteIdInterface::class);
        $maskedQuoteIdToQuoteId->expects(self::never())->method('execute');
        $cartRepository = $this->createMock(CartRepositoryInterface::class);

        $resolver = new CartResolver($maskedQuoteIdToQuoteId, $cartRepository);

        $this->expectException(CartNotAvailableException::class);
        $resolver->resolve(self::STORE_ID, '   ');
    }

    public function testResolvesARealCartForAMatchingStore(): void
    {
        $cart = $this->createMock(CartInterface::class);
        $cart->method('getStoreId')->willReturn(self::STORE_ID);

        $maskedQuoteIdToQuoteId = $this->createMock(MaskedQuoteIdToQuoteIdInterface::class);
        $maskedQuoteIdToQuoteId->method('execute')->with('masked-abc')->willReturn(42);

        $cartRepository = $this->createMock(CartRepositoryInterface::class);
        $cartRepository->method('get')->with(42)->willReturn($cart);

        $resolver = new CartResolver($maskedQuoteIdToQuoteId, $cartRepository);

        self::assertSame($cart, $resolver->resolve(self::STORE_ID, 'masked-abc'));
    }

    public function testRejectsACartBelongingToADifferentStore(): void
    {
        $cart = $this->createMock(CartInterface::class);
        $cart->method('getStoreId')->willReturn(self::STORE_ID + 1);

        $maskedQuoteIdToQuoteId = $this->createMock(MaskedQuoteIdToQuoteIdInterface::class);
        $maskedQuoteIdToQuoteId->method('execute')->willReturn(42);

        $cartRepository = $this->createMock(CartRepositoryInterface::class);
        $cartRepository->method('get')->willReturn($cart);

        $resolver = new CartResolver($maskedQuoteIdToQuoteId, $cartRepository);

        $this->expectException(CartNotAvailableException::class);
        $resolver->resolve(self::STORE_ID, 'masked-abc');
    }

    public function testMalformedCartIdIsRejectedNotPropagated(): void
    {
        $maskedQuoteIdToQuoteId = $this->createMock(MaskedQuoteIdToQuoteIdInterface::class);
        $maskedQuoteIdToQuoteId->method('execute')->willThrowException(new NoSuchEntityException(new Phrase('not found')));

        $cartRepository = $this->createMock(CartRepositoryInterface::class);
        $cartRepository->expects(self::never())->method('get');

        $resolver = new CartResolver($maskedQuoteIdToQuoteId, $cartRepository);

        $this->expectException(CartNotAvailableException::class);
        $resolver->resolve(self::STORE_ID, 'not-a-real-masked-id');
    }

    public function testMissingQuoteIsRejectedNotPropagated(): void
    {
        $maskedQuoteIdToQuoteId = $this->createMock(MaskedQuoteIdToQuoteIdInterface::class);
        $maskedQuoteIdToQuoteId->method('execute')->willReturn(42);

        $cartRepository = $this->createMock(CartRepositoryInterface::class);
        $cartRepository->method('get')->willThrowException(new NoSuchEntityException(new Phrase('not found')));

        $resolver = new CartResolver($maskedQuoteIdToQuoteId, $cartRepository);

        $this->expectException(CartNotAvailableException::class);
        $resolver->resolve(self::STORE_ID, 'masked-abc');
    }
}
