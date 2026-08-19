<?php

declare(strict_types=1);

namespace Aavirbhava\AiShoppingAssistant\Test\Unit\Model\Chat;

use Aavirbhava\AiShoppingAssistant\Api\Chat\ConversationHistoryStoreInterface;
use Aavirbhava\AiShoppingAssistant\Model\Chat\PriorTurnProductCarryOver;
use Aavirbhava\AiShoppingAssistant\Model\Chat\StoredConversationMessage;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(PriorTurnProductCarryOver::class)]
final class PriorTurnProductCarryOverTest extends TestCase
{
    public function testReturnsNothingWhenTheConversationHasNoMessages(): void
    {
        $historyStore = $this->createMock(ConversationHistoryStoreInterface::class);
        $historyStore->method('recentMessagesWithResponsePayloads')->willReturn([]);

        $carryOver = new PriorTurnProductCarryOver($historyStore);

        self::assertSame([], $carryOver->skus('conv-1', 1, 40));
    }

    public function testReturnsTheSkusFromTheMostRecentAssistantMessageWithProducts(): void
    {
        $historyStore = $this->createMock(ConversationHistoryStoreInterface::class);
        $historyStore->method('recentMessagesWithResponsePayloads')->willReturn([
            new StoredConversationMessage('user', 'show me jogging pants'),
            new StoredConversationMessage('assistant', 'Here are some options.', [
                'products' => [
                    ['sku' => 'SKU-1', 'name' => 'A'],
                    ['sku' => 'SKU-2', 'name' => 'B'],
                ],
                'follow_up_questions' => [],
                'actions' => [],
            ]),
        ]);

        $carryOver = new PriorTurnProductCarryOver($historyStore);

        self::assertSame(['SKU-1', 'SKU-2'], $carryOver->skus('conv-1', 1, 40));
    }

    public function testSkipsBackPastAnAssistantMessageWithNoProductsToAnEarlierOneThatHadSome(): void
    {
        $historyStore = $this->createMock(ConversationHistoryStoreInterface::class);
        $historyStore->method('recentMessagesWithResponsePayloads')->willReturn([
            new StoredConversationMessage('user', 'show me jogging pants'),
            new StoredConversationMessage('assistant', 'Here are some options.', [
                'products' => [['sku' => 'SKU-1', 'name' => 'A']],
                'follow_up_questions' => [],
                'actions' => [],
            ]),
            new StoredConversationMessage('user', 'thanks'),
            new StoredConversationMessage('assistant', 'You\'re welcome!', [
                'products' => [],
                'follow_up_questions' => [],
                'actions' => [],
            ]),
        ]);

        $carryOver = new PriorTurnProductCarryOver($historyStore);

        self::assertSame(['SKU-1'], $carryOver->skus('conv-1', 1, 40));
    }

    public function testIgnoresAnAssistantMessageWithNoStoredResponsePayloadAtAll(): void
    {
        $historyStore = $this->createMock(ConversationHistoryStoreInterface::class);
        $historyStore->method('recentMessagesWithResponsePayloads')->willReturn([
            new StoredConversationMessage('assistant', 'Some message with no payload.'),
        ]);

        $carryOver = new PriorTurnProductCarryOver($historyStore);

        self::assertSame([], $carryOver->skus('conv-1', 1, 40));
    }

    public function testDeduplicatesRepeatedSkusInTheSameMessage(): void
    {
        $historyStore = $this->createMock(ConversationHistoryStoreInterface::class);
        $historyStore->method('recentMessagesWithResponsePayloads')->willReturn([
            new StoredConversationMessage('assistant', 'Here are some options.', [
                'products' => [
                    ['sku' => 'SKU-1', 'name' => 'A'],
                    ['sku' => 'SKU-1', 'name' => 'A'],
                ],
                'follow_up_questions' => [],
                'actions' => [],
            ]),
        ]);

        $carryOver = new PriorTurnProductCarryOver($historyStore);

        self::assertSame(['SKU-1'], $carryOver->skus('conv-1', 1, 40));
    }
}
