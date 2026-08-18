<?php

declare(strict_types=1);

namespace Aavirbhava\AiShoppingAssistant\Test\Unit\Model\Chat;

use Aavirbhava\AiShoppingAssistant\Model\Chat\ChatRequestIdentity;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(ChatRequestIdentity::class)]
final class ChatRequestIdentityTest extends TestCase
{
    public function testAcceptsAGuestIdentityWithNoCart(): void
    {
        $identity = new ChatRequestIdentity('conv-1', 0, null);

        self::assertSame('conv-1', $identity->conversationId);
        self::assertSame(0, $identity->customerGroupId);
        self::assertNull($identity->cartId);
    }

    public function testAcceptsALoggedInIdentityWithACart(): void
    {
        $identity = new ChatRequestIdentity('conv-1', 3, 'masked-cart-abc');

        self::assertSame(3, $identity->customerGroupId);
        self::assertSame('masked-cart-abc', $identity->cartId);
    }

    public function testRejectsAnEmptyConversationId(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new ChatRequestIdentity('', 0, null);
    }

    public function testRejectsANegativeCustomerGroupId(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new ChatRequestIdentity('conv-1', -1, null);
    }
}
