<?php

declare(strict_types=1);

namespace Aavirbhava\AiShoppingAssistant\Test\Unit\Model\Chat;

use Aavirbhava\AiShoppingAssistant\Api\Chat\ConversationHistoryStoreInterface;
use Aavirbhava\AiShoppingAssistant\Model\Chat\ConversationHistoryViewBuilder;
use Aavirbhava\AiShoppingAssistant\Model\Chat\StoredConversationMessage;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Proves this class only reshapes what
 * ConversationHistoryStoreInterface::recentMessagesWithResponsePayloads()
 * already returns — filtering out intermediate tool-call-request/
 * tool-result messages is StoredConversationMessage's own constructor's
 * job (only `user`/`assistant` roles with non-empty content can exist),
 * proven separately by DbConversationHistoryStoreDatabaseTest.
 */
#[CoversClass(ConversationHistoryViewBuilder::class)]
final class ConversationHistoryViewBuilderTest extends TestCase
{
    private const CONVERSATION_ID = 'conv-1';
    private const STORE_ID = 5;
    private const MAX_MESSAGES = 40;

    public function testReshapesMessagesWithNoStoredPayloadIntoEmptyProductsFollowUpsAndActions(): void
    {
        $store = $this->createMock(ConversationHistoryStoreInterface::class);
        $store->method('recentMessagesWithResponsePayloads')
            ->with(self::CONVERSATION_ID, self::STORE_ID, self::MAX_MESSAGES)
            ->willReturn([
                new StoredConversationMessage('user', 'Show me waterproof phones.'),
                new StoredConversationMessage('assistant', 'Here are some options.'),
            ]);

        $builder = new ConversationHistoryViewBuilder($store);

        self::assertSame(
            [
                ['role' => 'user', 'message' => 'Show me waterproof phones.', 'products' => [], 'follow_up_questions' => [], 'actions' => []],
                ['role' => 'assistant', 'message' => 'Here are some options.', 'products' => [], 'follow_up_questions' => [], 'actions' => []],
            ],
            $builder->build(self::CONVERSATION_ID, self::STORE_ID, self::MAX_MESSAGES)
        );
    }

    public function testIncludesTheStoredResponsePayloadWhenPresent(): void
    {
        $payload = [
            'products' => [['sku' => 'SKU-1', 'name' => 'Blue Shoe', 'price' => 49.99]],
            'follow_up_questions' => ['Would you like to see more colors?'],
            'actions' => [['type' => 'compare', 'skus' => ['SKU-1', 'SKU-2']]],
        ];

        $store = $this->createMock(ConversationHistoryStoreInterface::class);
        $store->method('recentMessagesWithResponsePayloads')->willReturn([
            new StoredConversationMessage('assistant', 'Here is a great option.', $payload),
        ]);

        $builder = new ConversationHistoryViewBuilder($store);

        self::assertSame(
            [
                [
                    'role' => 'assistant',
                    'message' => 'Here is a great option.',
                    'products' => $payload['products'],
                    'follow_up_questions' => $payload['follow_up_questions'],
                    'actions' => $payload['actions'],
                ],
            ],
            $builder->build(self::CONVERSATION_ID, self::STORE_ID, self::MAX_MESSAGES)
        );
    }

    public function testReturnsEmptyListForAnEmptyConversation(): void
    {
        $store = $this->createMock(ConversationHistoryStoreInterface::class);
        $store->method('recentMessagesWithResponsePayloads')->willReturn([]);

        $builder = new ConversationHistoryViewBuilder($store);

        self::assertSame([], $builder->build(self::CONVERSATION_ID, self::STORE_ID, self::MAX_MESSAGES));
    }
}
