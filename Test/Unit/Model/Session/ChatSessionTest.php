<?php

declare(strict_types=1);

namespace Aavirbhava\AiShoppingAssistant\Test\Unit\Model\Session;

use Aavirbhava\AiShoppingAssistant\Model\Session\ChatSession;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(ChatSession::class)]
final class ChatSessionTest extends TestCase
{
    public function testHasNoConversationIdInitially(): void
    {
        $session = $this->session();

        self::assertNull($session->getConversationId());
    }

    public function testStoresAndReturnsAConversationId(): void
    {
        $session = $this->session();

        $session->setConversationId('conv-abc');

        self::assertSame('conv-abc', $session->getConversationId());
    }

    /**
     * ChatSession extends Magento\Framework\Session\SessionManager, which
     * cannot be constructed via createMock() (it does real session/cookie
     * work in its constructor). getData($key, $clear) is a real declared
     * method on SessionManager (onlyMethods); setData($key, $value) is
     * NOT — SessionManager routes it through __call() to its internal
     * Storage object — so it needs addMethods(), the same technique Task
     * 4's LiveRevalidationServiceTest already established for
     * Magento\Catalog\Model\Product::setCustomerGroupId(), another
     * magic-dispatched DataObject-style accessor.
     */
    private function session(): ChatSession
    {
        $session = $this->getMockBuilder(ChatSession::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['getData'])
            ->addMethods(['setData'])
            ->getMock();

        $store = [];
        $session->method('getData')->willReturnCallback(
            function (string $key) use (&$store) {
                return $store[$key] ?? null;
            }
        );
        $session->method('setData')->willReturnCallback(
            function (string $key, $value) use (&$store, $session) {
                $store[$key] = $value;

                return $session;
            }
        );

        return $session;
    }
}
