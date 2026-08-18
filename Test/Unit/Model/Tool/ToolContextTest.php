<?php

declare(strict_types=1);

namespace Aavirbhava\AiShoppingAssistant\Test\Unit\Model\Tool;

use Aavirbhava\AiShoppingAssistant\Model\Tool\ToolContext;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(ToolContext::class)]
final class ToolContextTest extends TestCase
{
    public function testAcceptsAPositiveStoreIdAndNullCustomerGroup(): void
    {
        $context = new ToolContext(1, null);

        self::assertSame(1, $context->storeId);
        self::assertNull($context->customerGroupId);
        self::assertNull($context->cartId);
    }

    public function testAcceptsAnExplicitCartId(): void
    {
        $context = new ToolContext(1, null, 'abc123maskedquoteid');

        self::assertSame('abc123maskedquoteid', $context->cartId);
    }

    public function testGeneratesAFreshRandomTurnIdWhenNoneIsSupplied(): void
    {
        $first = new ToolContext(1, null);
        $second = new ToolContext(1, null);

        self::assertNotSame('', $first->turnId);
        self::assertNotSame($first->turnId, $second->turnId);
    }

    public function testAcceptsAnExplicitTurnId(): void
    {
        $context = new ToolContext(1, null, null, 'explicit-turn-id');

        self::assertSame('explicit-turn-id', $context->turnId);
    }

    public function testAcceptsAnExplicitCustomerGroup(): void
    {
        $context = new ToolContext(1, 42);

        self::assertSame(42, $context->customerGroupId);
    }

    public function testRejectsAZeroStoreId(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new ToolContext(0, null);
    }

    public function testRejectsANegativeStoreId(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new ToolContext(-1, null);
    }
}
