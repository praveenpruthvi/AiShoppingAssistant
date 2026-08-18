<?php

declare(strict_types=1);

namespace Aavirbhava\AiShoppingAssistant\Test\Unit\Model\Chat;

use Aavirbhava\AiShoppingAssistant\Api\Config\ConfigurationReaderInterface;
use Aavirbhava\AiShoppingAssistant\Api\Config\GuardrailConfigInterface;
use Aavirbhava\AiShoppingAssistant\Model\Chat\ChatIdentityResolver;
use Aavirbhava\AiShoppingAssistant\Model\Session\ChatSession;
use Magento\Checkout\Model\Session as CheckoutSession;
use Magento\Customer\Model\Session as CustomerSession;
use Magento\Quote\Model\Quote;
use Magento\Quote\Model\QuoteIdMask;
use Magento\Quote\Model\QuoteIdMaskFactory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(ChatIdentityResolver::class)]
final class ChatIdentityResolverTest extends TestCase
{
    private const STORE_ID = 5;

    public function testGeneratesAndStoresAConversationIdWhenTheSessionHasNone(): void
    {
        $chatSession = $this->createMock(ChatSession::class);
        $chatSession->method('getConversationId')->willReturn(null);
        $chatSession->expects(self::once())->method('setConversationId')->with(self::isType('string'));

        $resolver = $this->resolver(chatSession: $chatSession, cartMutationsEnabled: false);

        $identity = $resolver->resolve(self::STORE_ID);

        self::assertNotSame('', $identity->conversationId);
    }

    public function testReusesAnExistingConversationIdWithoutGeneratingANewOne(): void
    {
        $chatSession = $this->createMock(ChatSession::class);
        $chatSession->method('getConversationId')->willReturn('existing-conv-id');
        $chatSession->expects(self::never())->method('setConversationId');

        $resolver = $this->resolver(chatSession: $chatSession, cartMutationsEnabled: false);

        $identity = $resolver->resolve(self::STORE_ID);

        self::assertSame('existing-conv-id', $identity->conversationId);
    }

    public function testTwoResolutionsWithNoStoredConversationIdProduceDifferentIds(): void
    {
        // Guards against a trivially-guessable/constant id — a real
        // security property, not just a uniqueness nicety.
        $chatSessionA = $this->createMock(ChatSession::class);
        $chatSessionA->method('getConversationId')->willReturn(null);

        $chatSessionB = $this->createMock(ChatSession::class);
        $chatSessionB->method('getConversationId')->willReturn(null);

        $identityA = $this->resolver(chatSession: $chatSessionA, cartMutationsEnabled: false)->resolve(self::STORE_ID);
        $identityB = $this->resolver(chatSession: $chatSessionB, cartMutationsEnabled: false)->resolve(self::STORE_ID);

        self::assertNotSame($identityA->conversationId, $identityB->conversationId);
    }

    public function testReturnsTheCustomerSessionsGroupId(): void
    {
        $customerSession = $this->createMock(CustomerSession::class);
        $customerSession->method('getCustomerGroupId')->willReturn(3);

        $resolver = $this->resolver(customerSession: $customerSession, cartMutationsEnabled: false);

        self::assertSame(3, $resolver->resolve(self::STORE_ID)->customerGroupId);
    }

    public function testCartIdIsNullWhenCartMutationsAreDisabledAndTheCheckoutSessionIsNeverTouched(): void
    {
        $checkoutSession = $this->createMock(CheckoutSession::class);
        $checkoutSession->expects(self::never())->method('getQuote');

        $resolver = $this->resolver(checkoutSession: $checkoutSession, cartMutationsEnabled: false);

        self::assertNull($resolver->resolve(self::STORE_ID)->cartId);
    }

    public function testCartIdIsNullWhenTheQuoteHasNoRealIdYet(): void
    {
        $quote = $this->createMock(Quote::class);
        $quote->method('getId')->willReturn(null);

        $checkoutSession = $this->createMock(CheckoutSession::class);
        $checkoutSession->method('getQuote')->willReturn($quote);

        $quoteIdMaskFactory = $this->createMock(QuoteIdMaskFactory::class);
        $quoteIdMaskFactory->expects(self::never())->method('create');

        $resolver = $this->resolver(
            checkoutSession: $checkoutSession,
            quoteIdMaskFactory: $quoteIdMaskFactory,
            cartMutationsEnabled: true
        );

        self::assertNull($resolver->resolve(self::STORE_ID)->cartId);
    }

    public function testResolvesAndCreatesAMaskedCartIdWhenNoneExistsYet(): void
    {
        $quote = $this->createMock(Quote::class);
        $quote->method('getId')->willReturn(77);

        $checkoutSession = $this->createMock(CheckoutSession::class);
        $checkoutSession->method('getQuote')->willReturn($quote);

        $quoteIdMask = $this->quoteIdMask(existingId: null, maskedId: 'masked-abc');
        $quoteIdMask->expects(self::once())->method('setQuoteId')->with(77);
        $quoteIdMask->expects(self::once())->method('save');

        $quoteIdMaskFactory = $this->createMock(QuoteIdMaskFactory::class);
        $quoteIdMaskFactory->method('create')->willReturn($quoteIdMask);

        $resolver = $this->resolver(
            checkoutSession: $checkoutSession,
            quoteIdMaskFactory: $quoteIdMaskFactory,
            cartMutationsEnabled: true
        );

        self::assertSame('masked-abc', $resolver->resolve(self::STORE_ID)->cartId);
    }

    public function testReusesAnExistingMaskedCartIdWithoutSavingAgain(): void
    {
        $quote = $this->createMock(Quote::class);
        $quote->method('getId')->willReturn(77);

        $checkoutSession = $this->createMock(CheckoutSession::class);
        $checkoutSession->method('getQuote')->willReturn($quote);

        $quoteIdMask = $this->quoteIdMask(existingId: 9, maskedId: 'masked-existing');
        $quoteIdMask->expects(self::never())->method('setQuoteId');
        $quoteIdMask->expects(self::never())->method('save');

        $quoteIdMaskFactory = $this->createMock(QuoteIdMaskFactory::class);
        $quoteIdMaskFactory->method('create')->willReturn($quoteIdMask);

        $resolver = $this->resolver(
            checkoutSession: $checkoutSession,
            quoteIdMaskFactory: $quoteIdMaskFactory,
            cartMutationsEnabled: true
        );

        self::assertSame('masked-existing', $resolver->resolve(self::STORE_ID)->cartId);
    }

    private function quoteIdMask(?int $existingId, string $maskedId): QuoteIdMask
    {
        $quoteIdMask = $this->getMockBuilder(QuoteIdMask::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['load', 'save', 'getId'])
            ->addMethods(['setQuoteId', 'getMaskedId'])
            ->getMock();
        $quoteIdMask->method('load')->willReturn($quoteIdMask);
        $quoteIdMask->method('getId')->willReturn($existingId);
        $quoteIdMask->method('getMaskedId')->willReturn($maskedId);

        return $quoteIdMask;
    }

    private function resolver(
        ?ChatSession $chatSession = null,
        ?CustomerSession $customerSession = null,
        ?CheckoutSession $checkoutSession = null,
        ?QuoteIdMaskFactory $quoteIdMaskFactory = null,
        bool $cartMutationsEnabled = false
    ): ChatIdentityResolver {
        if ($chatSession === null) {
            $chatSession = $this->createMock(ChatSession::class);
            $chatSession->method('getConversationId')->willReturn('stub-conv-id');
        }

        if ($customerSession === null) {
            $customerSession = $this->createMock(CustomerSession::class);
            $customerSession->method('getCustomerGroupId')->willReturn(0);
        }

        $checkoutSession ??= $this->createMock(CheckoutSession::class);
        $quoteIdMaskFactory ??= $this->createMock(QuoteIdMaskFactory::class);

        $guardrails = $this->createMock(GuardrailConfigInterface::class);
        $guardrails->method('areCartMutationsEnabled')->willReturn($cartMutationsEnabled);

        $configurationReader = $this->createMock(ConfigurationReaderInterface::class);
        $configurationReader->method('readGuardrails')->with(self::STORE_ID)->willReturn($guardrails);

        return new ChatIdentityResolver(
            $chatSession,
            $customerSession,
            $checkoutSession,
            $quoteIdMaskFactory,
            $configurationReader
        );
    }
}
