<?php

declare(strict_types=1);

namespace Aavirbhava\AiShoppingAssistant\Test\Unit\Controller\Chat;

use Aavirbhava\AiShoppingAssistant\Api\Chat\ConversationHistoryStoreInterface;
use Aavirbhava\AiShoppingAssistant\Api\Config\ConfigurationReaderInterface;
use Aavirbhava\AiShoppingAssistant\Api\Config\GeneralConfigInterface;
use Aavirbhava\AiShoppingAssistant\Controller\Chat\History;
use Aavirbhava\AiShoppingAssistant\Model\Chat\ConversationHistoryViewBuilder;
use Aavirbhava\AiShoppingAssistant\Model\Chat\StoredConversationMessage;
use Aavirbhava\AiShoppingAssistant\Model\Session\ChatSession;
use Magento\Framework\Controller\Result\Json;
use Magento\Framework\Controller\Result\JsonFactory;
use Magento\Store\Api\Data\StoreInterface;
use Magento\Store\Model\StoreManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Proves this read-only endpoint degrades to an empty transcript — never
 * an error — in every case that isn't "a real, existing conversation for
 * an enabled store": no conversation id yet (a visitor who never chatted),
 * the assistant disabled, and any unexpected failure anywhere in the
 * chain. Also proves it never calls ChatIdentityResolverInterface::resolve()
 * (which would allocate a fresh conversation id / touch the cart as a
 * side effect) — this controller only ever reads the session's existing
 * conversation id via ChatSession directly.
 *
 * Uses a real ConversationHistoryViewBuilder (final, so not mockable)
 * wrapping a mocked ConversationHistoryStoreInterface — the same "swap
 * only the actual boundary" style this module's other controller tests
 * already use, rather than mocking a concrete collaborator.
 */
#[CoversClass(History::class)]
final class HistoryTest extends TestCase
{
    private const STORE_ID = 5;

    public function testReturnsTheBuiltHistoryForAnExistingConversation(): void
    {
        $historyStore = $this->createMock(ConversationHistoryStoreInterface::class);
        $historyStore->expects(self::once())
            ->method('recentMessagesWithResponsePayloads')
            ->with('conv-1', self::STORE_ID, 40)
            ->willReturn([
                new StoredConversationMessage('user', 'Show me waterproof phones.'),
                new StoredConversationMessage(
                    'assistant',
                    'Here are some options.',
                    ['products' => [['sku' => 'SKU-1']], 'follow_up_questions' => [], 'actions' => []]
                ),
            ]);

        $jsonResult = $this->createMock(Json::class);
        $jsonResult->expects(self::once())->method('setData')->with([
            'messages' => [
                ['role' => 'user', 'message' => 'Show me waterproof phones.', 'products' => [], 'follow_up_questions' => [], 'actions' => []],
                ['role' => 'assistant', 'message' => 'Here are some options.', 'products' => [['sku' => 'SKU-1']], 'follow_up_questions' => [], 'actions' => []],
            ],
        ]);

        $controller = $this->controller(conversationId: 'conv-1', historyStore: $historyStore, jsonResult: $jsonResult);

        $controller->execute();
    }

    public function testReturnsEmptyMessagesWhenNoConversationIdExistsYet(): void
    {
        $historyStore = $this->createMock(ConversationHistoryStoreInterface::class);
        $historyStore->expects(self::never())->method('recentMessagesWithResponsePayloads');

        $jsonResult = $this->createMock(Json::class);
        $jsonResult->expects(self::once())->method('setData')->with(['messages' => []]);

        $controller = $this->controller(conversationId: null, historyStore: $historyStore, jsonResult: $jsonResult);

        $controller->execute();
    }

    public function testReturnsEmptyMessagesWhenTheAssistantIsDisabled(): void
    {
        $historyStore = $this->createMock(ConversationHistoryStoreInterface::class);
        $historyStore->expects(self::never())->method('recentMessagesWithResponsePayloads');

        $jsonResult = $this->createMock(Json::class);
        $jsonResult->expects(self::once())->method('setData')->with(['messages' => []]);

        $controller = $this->controller(
            conversationId: 'conv-1',
            assistantEnabled: false,
            historyStore: $historyStore,
            jsonResult: $jsonResult
        );

        $controller->execute();
    }

    public function testReturnsEmptyMessagesRatherThanThrowingOnAnUnexpectedFailure(): void
    {
        $historyStore = $this->createMock(ConversationHistoryStoreInterface::class);
        $historyStore->method('recentMessagesWithResponsePayloads')->willThrowException(new \RuntimeException('boom'));

        $jsonResult = $this->createMock(Json::class);
        $jsonResult->expects(self::once())->method('setData')->with(['messages' => []]);

        $controller = $this->controller(conversationId: 'conv-1', historyStore: $historyStore, jsonResult: $jsonResult);

        $controller->execute();
    }

    private function controller(
        ?string $conversationId,
        bool $assistantEnabled = true,
        ?ConversationHistoryStoreInterface $historyStore = null,
        ?Json $jsonResult = null
    ): History {
        $store = $this->createMock(StoreInterface::class);
        $store->method('getId')->willReturn(self::STORE_ID);
        $storeManager = $this->createMock(StoreManagerInterface::class);
        $storeManager->method('getStore')->willReturn($store);

        $general = $this->createMock(GeneralConfigInterface::class);
        $general->method('isEnabled')->willReturn($assistantEnabled);
        $general->method('maxConversationMessages')->willReturn(40);

        $configurationReader = $this->createMock(ConfigurationReaderInterface::class);
        $configurationReader->method('readGeneral')->with(self::STORE_ID)->willReturn($general);

        $chatSession = $this->getMockBuilder(ChatSession::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['getData'])
            ->getMock();
        $chatSession->method('getData')->with('conversation_id')->willReturn($conversationId);

        $historyStore ??= $this->createMock(ConversationHistoryStoreInterface::class);
        $viewBuilder = new ConversationHistoryViewBuilder($historyStore);

        $jsonResult ??= $this->createMock(Json::class);
        $jsonResultFactory = $this->createMock(JsonFactory::class);
        $jsonResultFactory->method('create')->willReturn($jsonResult);

        return new History(
            $jsonResultFactory,
            $storeManager,
            $configurationReader,
            $chatSession,
            $viewBuilder,
            $this->createMock(LoggerInterface::class)
        );
    }
}
