<?php

declare(strict_types=1);

namespace Aavirbhava\AiShoppingAssistant\Test\Integration\Model\Chat;

use Aavirbhava\AiShoppingAssistant\Model\Chat\DbConversationHistoryStore;
use Aavirbhava\AiShoppingAssistant\Model\Dto\ChatMessage;
use Aavirbhava\AiShoppingAssistant\Model\Dto\ToolCall;
use Aavirbhava\AiShoppingAssistant\Test\Integration\Model\Indexing\Queue\Fixture\MutableClock;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\App\State;
use Magento\Framework\DB\Adapter\AdapterInterface;
use Psr\Log\LoggerInterface;
use PHPUnit\Framework\TestCase;

/**
 * Exercises DbConversationHistoryStore against the real database — the
 * same rationale as DbIncrementalWorkLedgerDatabaseTest: raw
 * ResourceConnection/Select-based logic isn't meaningfully testable
 * against a mocked adapter.
 *
 * Store-id and conversation-id scoping are tested explicitly here because
 * they are this class's actual security property: a conversation started
 * on one store, or under a different conversation id, must never surface
 * in another's recentMessages() — this is the persistence-layer half of
 * Task 8's "one customer's conversation can never leak into another's"
 * requirement (the session-identity half is covered by
 * ChatIdentityResolverTest and the live cross-session check).
 */
final class DbConversationHistoryStoreDatabaseTest extends TestCase
{
    private const CONVERSATION_A = 'aavirbhava-test-conv-a';
    private const CONVERSATION_B = 'aavirbhava-test-conv-b';

    // store_id is a smallint unsigned column (max 65535) — unlike
    // PRODUCT_ID in DbIncrementalWorkLedgerDatabaseTest (an int unsigned
    // column), a large placeholder here would overflow and silently fail
    // to insert (appendTurn() catches and logs persistence failures
    // rather than throwing, by design).
    private const STORE_ID = 60001;
    private const OTHER_STORE_ID = 60002;

    private ResourceConnection $resource;
    private AdapterInterface $connection;
    private string $table;
    private MutableClock $clock;
    private DbConversationHistoryStore $store;

    protected function setUp(): void
    {
        $root = dirname(__DIR__, 8);
        require_once $root . '/app/bootstrap.php';

        $bootstrap = \Magento\Framework\App\Bootstrap::create($root, $_SERVER);
        $objectManager = $bootstrap->getObjectManager();

        try {
            $objectManager->get(State::class)->setAreaCode('adminhtml');
        } catch (\Throwable) {
        }

        $this->resource = $objectManager->get(ResourceConnection::class);
        $this->connection = $this->resource->getConnection();
        $this->table = $this->resource->getTableName('aavirbhava_ai_conversation_message');
        $this->clock = new MutableClock(new \DateTimeImmutable('2026-08-16 12:00:00'));
        $this->store = new DbConversationHistoryStore($this->resource, $this->clock, $objectManager->get(LoggerInterface::class));
        $this->cleanup();
    }

    protected function tearDown(): void
    {
        $this->cleanup();
    }

    public function testAppendedMessagesAreReturnedInOrderWithToolCallsRoundTripped(): void
    {
        $toolCall = new ToolCall('call_1', 'check_price', ['skus' => ['SKU-1']]);

        $this->store->appendTurn(self::CONVERSATION_A, self::STORE_ID, [
            new ChatMessage('user', 'What does SKU-1 cost?'),
            new ChatMessage('assistant', '', null, [$toolCall]),
            new ChatMessage('tool', '{"prices":[{"sku":"SKU-1","price":9.99}]}', 'call_1'),
            new ChatMessage('assistant', 'It is $9.99.'),
        ], 40);

        $messages = $this->store->recentMessages(self::CONVERSATION_A, self::STORE_ID, 40);

        self::assertCount(4, $messages);
        self::assertSame('user', $messages[0]->role);
        self::assertSame('What does SKU-1 cost?', $messages[0]->content);
        self::assertSame('assistant', $messages[1]->role);
        self::assertCount(1, $messages[1]->toolCalls);
        self::assertSame('call_1', $messages[1]->toolCalls[0]->id);
        self::assertSame('check_price', $messages[1]->toolCalls[0]->name);
        self::assertSame(['skus' => ['SKU-1']], $messages[1]->toolCalls[0]->arguments);
        self::assertSame('tool', $messages[2]->role);
        self::assertSame('call_1', $messages[2]->toolCallId);
        self::assertSame('assistant', $messages[3]->role);
        self::assertSame('It is $9.99.', $messages[3]->content);
    }

    /**
     * A ToolCall's providerMetadata (e.g. Gemini's thoughtSignature, which
     * must be echoed back verbatim on any later turn that replays this
     * same function call, confirmed against a real Gemini response — see
     * GeminiProviderTest) must survive a real save/reload round trip, not
     * just an in-memory one — a real, multi-turn storefront conversation
     * persists and reloads across separate HTTP requests.
     */
    public function testAToolCallsProviderMetadataSurvivesARealSaveAndReloadRoundTrip(): void
    {
        $toolCall = new ToolCall('call_1', 'check_price', ['skus' => ['SKU-1']], 'thought-signature-xyz');

        $this->store->appendTurn(self::CONVERSATION_A, self::STORE_ID, [
            new ChatMessage('user', 'What does SKU-1 cost?'),
            new ChatMessage('assistant', '', null, [$toolCall]),
            new ChatMessage('tool', '{"prices":[{"sku":"SKU-1","price":9.99}]}', 'call_1'),
        ], 40);

        $messages = $this->store->recentMessages(self::CONVERSATION_A, self::STORE_ID, 40);

        self::assertSame('thought-signature-xyz', $messages[1]->toolCalls[0]->providerMetadata);
    }

    public function testAToolCallWithNoProviderMetadataRoundTripsAsNullNotAnEmptyString(): void
    {
        $toolCall = new ToolCall('call_1', 'check_price', ['skus' => ['SKU-1']]);

        $this->store->appendTurn(self::CONVERSATION_A, self::STORE_ID, [
            new ChatMessage('user', 'What does SKU-1 cost?'),
            new ChatMessage('assistant', '', null, [$toolCall]),
            new ChatMessage('tool', '{"prices":[]}', 'call_1'),
        ], 40);

        $messages = $this->store->recentMessages(self::CONVERSATION_A, self::STORE_ID, 40);

        self::assertNull($messages[1]->toolCalls[0]->providerMetadata);
    }

    public function testMessagesFromADifferentConversationIdNeverLeakIn(): void
    {
        $this->store->appendTurn(self::CONVERSATION_A, self::STORE_ID, [
            new ChatMessage('user', 'Conversation A message'),
        ], 40);
        $this->store->appendTurn(self::CONVERSATION_B, self::STORE_ID, [
            new ChatMessage('user', 'Conversation B message'),
        ], 40);

        $messagesA = $this->store->recentMessages(self::CONVERSATION_A, self::STORE_ID, 40);
        $messagesB = $this->store->recentMessages(self::CONVERSATION_B, self::STORE_ID, 40);

        self::assertCount(1, $messagesA);
        self::assertSame('Conversation A message', $messagesA[0]->content);
        self::assertCount(1, $messagesB);
        self::assertSame('Conversation B message', $messagesB[0]->content);
    }

    public function testMessagesFromADifferentStoreNeverLeakInEvenForTheSameConversationId(): void
    {
        // The exact same conversation id, deliberately, on two different
        // stores — proves isolation is store-scoped, not just id-scoped
        // (relevant since a real conversation id only comes from one
        // browser session, but a store view can still change mid-session).
        $this->store->appendTurn(self::CONVERSATION_A, self::STORE_ID, [
            new ChatMessage('user', 'Message on the real store'),
        ], 40);
        $this->store->appendTurn(self::CONVERSATION_A, self::OTHER_STORE_ID, [
            new ChatMessage('user', 'Message on the other store'),
        ], 40);

        $messages = $this->store->recentMessages(self::CONVERSATION_A, self::STORE_ID, 40);

        self::assertCount(1, $messages);
        self::assertSame('Message on the real store', $messages[0]->content);
    }

    public function testAppendingBeyondTheRetentionCapPrunesTheOldestMessages(): void
    {
        for ($i = 1; $i <= 5; $i++) {
            $this->store->appendTurn(self::CONVERSATION_A, self::STORE_ID, [
                new ChatMessage('user', "message-$i"),
            ], 3);
        }

        $messages = $this->store->recentMessages(self::CONVERSATION_A, self::STORE_ID, 10);

        self::assertCount(3, $messages);
        self::assertSame('message-3', $messages[0]->content);
        self::assertSame('message-4', $messages[1]->content);
        self::assertSame('message-5', $messages[2]->content);
    }

    public function testMessagesOlderThanTheRetentionWindowAreExcluded(): void
    {
        $this->store->appendTurn(self::CONVERSATION_A, self::STORE_ID, [
            new ChatMessage('user', 'old message'),
        ], 40);

        $this->clock->advance(25 * 3600); // past the 24-hour TTL

        $this->store->appendTurn(self::CONVERSATION_A, self::STORE_ID, [
            new ChatMessage('user', 'fresh message'),
        ], 40);

        $messages = $this->store->recentMessages(self::CONVERSATION_A, self::STORE_ID, 40);

        self::assertCount(1, $messages);
        self::assertSame('fresh message', $messages[0]->content);
    }

    public function testRecentMessagesForAnUnknownConversationIsEmpty(): void
    {
        self::assertSame([], $this->store->recentMessages('never-existed', self::STORE_ID, 40));
    }

    public function testResponsePayloadIsPersistedOnTheFinalMessageAndReturnedByRestore(): void
    {
        $payload = [
            'products' => [['sku' => 'SKU-1', 'name' => 'Blue Shoe', 'price' => 49.99]],
            'follow_up_questions' => ['Would you like to see more colors?'],
            'actions' => [['type' => 'compare', 'skus' => ['SKU-1', 'SKU-2']]],
        ];

        $this->store->appendTurn(
            self::CONVERSATION_A,
            self::STORE_ID,
            [
                new ChatMessage('user', 'Show me waterproof phones.'),
                new ChatMessage('assistant', 'Here is a great option.'),
            ],
            40,
            $payload
        );

        $messages = $this->store->recentMessagesWithResponsePayloads(self::CONVERSATION_A, self::STORE_ID, 40);

        self::assertCount(2, $messages);
        self::assertSame('user', $messages[0]->role);
        self::assertNull($messages[0]->responsePayload);
        self::assertSame('assistant', $messages[1]->role);
        self::assertSame('Here is a great option.', $messages[1]->content);
        self::assertSame($payload, $messages[1]->responsePayload);
    }

    public function testRestoreExcludesToolAndIntermediateAssistantToolCallMessages(): void
    {
        $toolCall = new ToolCall('call_1', 'check_price', ['skus' => ['SKU-1']]);

        $this->store->appendTurn(
            self::CONVERSATION_A,
            self::STORE_ID,
            [
                new ChatMessage('user', 'What does SKU-1 cost?'),
                new ChatMessage('assistant', '', null, [$toolCall]),
                new ChatMessage('tool', '{"prices":[{"sku":"SKU-1","price":9.99}]}', 'call_1'),
                new ChatMessage('assistant', 'It is $9.99.'),
            ],
            40,
            ['products' => [], 'follow_up_questions' => [], 'actions' => []]
        );

        $messages = $this->store->recentMessagesWithResponsePayloads(self::CONVERSATION_A, self::STORE_ID, 40);

        self::assertCount(2, $messages);
        self::assertSame('user', $messages[0]->role);
        self::assertSame('What does SKU-1 cost?', $messages[0]->content);
        self::assertSame('assistant', $messages[1]->role);
        self::assertSame('It is $9.99.', $messages[1]->content);
    }

    public function testRestoreForAnUnknownConversationIsEmpty(): void
    {
        self::assertSame([], $this->store->recentMessagesWithResponsePayloads('never-existed', self::STORE_ID, 40));
    }

    private function cleanup(): void
    {
        $this->connection->delete($this->table, [
            'conversation_id IN (?)' => [self::CONVERSATION_A, self::CONVERSATION_B],
        ]);
    }
}
