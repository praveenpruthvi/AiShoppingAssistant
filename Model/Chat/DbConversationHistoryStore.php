<?php

declare(strict_types=1);

namespace Aavirbhava\AiShoppingAssistant\Model\Chat;

use Aavirbhava\AiShoppingAssistant\Api\Chat\ConversationHistoryStoreInterface;
use Aavirbhava\AiShoppingAssistant\Model\Dto\ChatMessage;
use Aavirbhava\AiShoppingAssistant\Model\Dto\ToolCall;
use Aavirbhava\AiShoppingAssistant\Model\Indexing\Clock\ClockInterface;
use InvalidArgumentException;
use JsonException;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Adapter\AdapterInterface;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * DB-backed conversation history, using the same raw ResourceConnection
 * style as DbIncrementalWorkLedger/DbRebuildFence — no ORM-style
 * Model/ResourceModel/Collection layer, since the access pattern here
 * (append a turn, read the last N, prune) needs none of that machinery.
 *
 * Chosen over the cache-based pattern CacheCircuitBreaker/
 * CartMutationConfirmationService use (see the Task 8 status report for
 * the full reasoning): conversation history needs to survive well beyond
 * a short TTL, needs to be queried ("last N messages in order," not just
 * "does this exact key exist"), and needs predictable capacity — none of
 * which a single cache blob per key models well, while a DB table with an
 * index on (conversation_id, store_id, message_id) does directly.
 *
 * A store's retention window is enforced two ways: appendTurn() prunes any
 * row beyond the configured $maxMessages for that conversation immediately
 * after every write, bounding storage per conversation regardless of how
 * long it stays active; recentMessages() additionally excludes anything
 * older than a fixed absolute TTL (self::TTL_HOURS), so a conversation
 * nobody has touched in a long time is treated as expired context rather
 * than resurrected verbatim. Neither mechanism sweeps orphaned
 * conversations nobody ever revisits — a documented, deliberately
 * out-of-scope future improvement (a periodic cleanup job), not silently
 * ignored.
 *
 * Every failure here is caught and logged, never propagated: conversation
 * memory is a quality-of-life feature, not a safety-critical one, so a
 * transient storage problem degrades to "this turn has no memory of
 * earlier ones," not a broken chat turn.
 */
final class DbConversationHistoryStore implements ConversationHistoryStoreInterface
{
    private const TABLE = 'aavirbhava_ai_conversation_message';
    private const TTL_HOURS = 24;
    private const ALLOWED_ROLES = ['user', 'assistant', 'tool'];

    public function __construct(
        private readonly ResourceConnection $resource,
        private readonly ClockInterface $clock,
        private readonly LoggerInterface $logger
    ) {
    }

    public function recentMessages(string $conversationId, int $storeId, int $maxMessages): array
    {
        $rows = $this->fetchRows($conversationId, $storeId, $maxMessages, 'AI shopping assistant: failed to load conversation history.');

        $messages = [];
        foreach ($rows as $row) {
            $message = $this->rowToMessage($row);
            if ($message !== null) {
                $messages[] = $message;
            }
        }

        return $messages;
    }

    public function recentMessagesWithResponsePayloads(string $conversationId, int $storeId, int $maxMessages): array
    {
        $rows = $this->fetchRows(
            $conversationId,
            $storeId,
            $maxMessages,
            'AI shopping assistant: failed to load conversation history for restore.'
        );

        $messages = [];
        foreach ($rows as $row) {
            $message = $this->rowToStoredMessage($row);
            if ($message !== null) {
                $messages[] = $message;
            }
        }

        return $messages;
    }

    public function appendTurn(
        string $conversationId,
        int $storeId,
        array $messages,
        int $maxMessages,
        ?array $lastMessageResponsePayload = null
    ): void {
        if ($conversationId === '' || $storeId < 1 || $messages === []) {
            return;
        }

        try {
            $connection = $this->resource->getConnection();
            $now = $this->now();
            $lastMessage = end($messages);

            foreach ($messages as $message) {
                if (!$message instanceof ChatMessage || !in_array($message->role, self::ALLOWED_ROLES, true)) {
                    continue;
                }

                $isLastMessage = $message === $lastMessage;

                $connection->insert($this->table(), [
                    'conversation_id' => $conversationId,
                    'store_id' => $storeId,
                    'role' => $message->role,
                    'content' => $message->content,
                    'tool_call_id' => $message->toolCallId,
                    'tool_calls' => $message->toolCalls !== [] ? $this->encodeToolCalls($message->toolCalls) : null,
                    'response_payload' => $isLastMessage && $lastMessageResponsePayload !== null
                        ? $this->encodeResponsePayload($lastMessageResponsePayload)
                        : null,
                    'created_at' => $now,
                ]);
            }

            $this->prune($connection, $conversationId, $storeId, $maxMessages);
        } catch (Throwable $throwable) {
            $this->logger->error('AI shopping assistant: failed to persist conversation history.', [
                'conversation_id' => $conversationId,
                'store_id' => $storeId,
                'exception' => $throwable->getMessage(),
            ]);
        }
    }

    /**
     * @return list<array<string, mixed>> oldest-first
     */
    private function fetchRows(string $conversationId, int $storeId, int $maxMessages, string $errorMessage): array
    {
        if ($conversationId === '' || $storeId < 1 || $maxMessages < 1) {
            return [];
        }

        try {
            $connection = $this->resource->getConnection();
            $rows = $connection->fetchAll(
                $connection->select()
                    ->from($this->table())
                    ->where('conversation_id = ?', $conversationId)
                    ->where('store_id = ?', $storeId)
                    ->where('created_at >= ?', $this->cutoff())
                    ->order('message_id DESC')
                    ->limit($maxMessages)
            );
        } catch (Throwable $throwable) {
            $this->logger->error($errorMessage, [
                'conversation_id' => $conversationId,
                'store_id' => $storeId,
                'exception' => $throwable->getMessage(),
            ]);

            return [];
        }

        return array_reverse($rows);
    }

    private function prune(AdapterInterface $connection, string $conversationId, int $storeId, int $maxMessages): void
    {
        $keepIds = $connection->fetchCol(
            $connection->select()
                ->from($this->table(), ['message_id'])
                ->where('conversation_id = ?', $conversationId)
                ->where('store_id = ?', $storeId)
                ->order('message_id DESC')
                ->limit($maxMessages)
        );

        if ($keepIds === []) {
            return;
        }

        $connection->delete(
            $this->table(),
            [
                'conversation_id = ?' => $conversationId,
                'store_id = ?' => $storeId,
                'message_id NOT IN (?)' => $keepIds,
            ]
        );
    }

    /**
     * @param array<string, mixed> $row
     */
    private function rowToMessage(array $row): ?ChatMessage
    {
        $role = (string) ($row['role'] ?? '');
        if (!in_array($role, self::ALLOWED_ROLES, true)) {
            return null;
        }

        $toolCalls = [];
        $rawToolCalls = $row['tool_calls'] ?? null;
        if (is_string($rawToolCalls) && $rawToolCalls !== '') {
            $decoded = $this->decodeToolCalls($rawToolCalls);
            if ($decoded === null) {
                return null;
            }
            $toolCalls = $decoded;
        }

        try {
            return new ChatMessage(
                $role,
                (string) ($row['content'] ?? ''),
                isset($row['tool_call_id']) && $row['tool_call_id'] !== null ? (string) $row['tool_call_id'] : null,
                $toolCalls
            );
        } catch (InvalidArgumentException) {
            return null;
        }
    }

    /**
     * StoredConversationMessage's own constructor (role must be user/
     * assistant, content must be non-empty) is what actually filters out
     * `tool`-role rows and intermediate assistant tool-call-request rows
     * (content is legitimately empty on those) — no separate filtering
     * logic is needed here beyond "try to build one, skip it if it
     * doesn't fit," the same skip-on-InvalidArgumentException pattern
     * rowToMessage() already uses.
     *
     * @param array<string, mixed> $row
     */
    private function rowToStoredMessage(array $row): ?StoredConversationMessage
    {
        try {
            return new StoredConversationMessage(
                (string) ($row['role'] ?? ''),
                (string) ($row['content'] ?? ''),
                $this->decodeResponsePayload($row['response_payload'] ?? null)
            );
        } catch (InvalidArgumentException) {
            return null;
        }
    }

    /**
     * @param list<ToolCall> $toolCalls
     */
    private function encodeToolCalls(array $toolCalls): string
    {
        $encoded = array_map(
            static fn (ToolCall $toolCall): array => [
                'id' => $toolCall->id,
                'name' => $toolCall->name,
                'arguments' => $toolCall->arguments,
            ],
            $toolCalls
        );

        try {
            return json_encode($encoded, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return '[]';
        }
    }

    /**
     * @return list<ToolCall>|null null when the stored JSON is malformed
     */
    private function decodeToolCalls(string $raw): ?array
    {
        try {
            $decoded = json_decode($raw, true, 8, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return null;
        }

        if (!is_array($decoded)) {
            return null;
        }

        $toolCalls = [];
        foreach ($decoded as $entry) {
            if (
                !is_array($entry)
                || !isset($entry['id'], $entry['name'], $entry['arguments'])
                || !is_string($entry['id'])
                || !is_string($entry['name'])
                || !is_array($entry['arguments'])
            ) {
                return null;
            }

            try {
                $toolCalls[] = new ToolCall($entry['id'], $entry['name'], $entry['arguments']);
            } catch (InvalidArgumentException) {
                return null;
            }
        }

        return $toolCalls;
    }

    /**
     * @param array{products: list<array<string, mixed>>, follow_up_questions: list<string>, actions: list<array<string, mixed>>} $payload
     */
    private function encodeResponsePayload(array $payload): string
    {
        try {
            return json_encode($payload, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return json_encode(['products' => [], 'follow_up_questions' => [], 'actions' => []]);
        }
    }

    /**
     * @return array{products: list<array<string, mixed>>, follow_up_questions: list<string>, actions: list<array<string, mixed>>}|null
     *     null for a row with no payload stored (every non-final-assistant
     *     message, and every message persisted before this column
     *     existed) or one whose stored JSON is malformed/incomplete —
     *     never a partial/best-effort shape.
     */
    private function decodeResponsePayload(mixed $raw): ?array
    {
        if (!is_string($raw) || $raw === '') {
            return null;
        }

        try {
            $decoded = json_decode($raw, true, 8, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return null;
        }

        if (
            !is_array($decoded)
            || !isset($decoded['products'], $decoded['follow_up_questions'], $decoded['actions'])
            || !is_array($decoded['products'])
            || !is_array($decoded['follow_up_questions'])
            || !is_array($decoded['actions'])
        ) {
            return null;
        }

        return [
            'products' => $decoded['products'],
            'follow_up_questions' => $decoded['follow_up_questions'],
            'actions' => $decoded['actions'],
        ];
    }

    private function table(): string
    {
        return $this->resource->getTableName(self::TABLE);
    }

    private function now(): string
    {
        return $this->clock->now()->format('Y-m-d H:i:s');
    }

    private function cutoff(): string
    {
        return $this->clock->now()->modify('-' . self::TTL_HOURS . ' hours')->format('Y-m-d H:i:s');
    }
}
