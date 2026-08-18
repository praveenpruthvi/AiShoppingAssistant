<?php

declare(strict_types=1);

namespace Aavirbhava\AiShoppingAssistant\Api\Chat;

use Aavirbhava\AiShoppingAssistant\Model\Chat\StoredConversationMessage;
use Aavirbhava\AiShoppingAssistant\Model\Dto\ChatMessage;

/**
 * Persists and retrieves prior conversation turns for one opaque,
 * session-scoped conversation id, so ChatEntryPipeline can thread real
 * multi-turn memory into every new message instead of building a fresh,
 * single-message context on every call (the gap flagged since Task 7).
 *
 * $conversationId is never a customer/order/session identifier itself —
 * see Model\Session\ChatSession — and every read/write is scoped by
 * $storeId, so a conversation started on one store view never bleeds into
 * another. Only `user`/`assistant`/`tool` role messages are persisted; the
 * ephemeral product-context `system` message ChatEntryPipeline builds fresh
 * every turn is never stored here.
 *
 * Implementations must degrade gracefully on persistence failure — a
 * transient storage problem must never break the chat turn itself, only
 * cost that turn its memory of prior context.
 */
interface ConversationHistoryStoreInterface
{
    /**
     * The most recent messages for this conversation, oldest-first, capped
     * at $maxMessages and silently excluding anything past the store's
     * retention window. Returns an empty list for an unknown, empty, or
     * fully expired conversation — never throws for that reason.
     *
     * @return list<ChatMessage>
     */
    public function recentMessages(string $conversationId, int $storeId, int $maxMessages): array;

    /**
     * Like recentMessages(), but for restoring a widget's UI transcript
     * rather than threading context into a new LLM request — returns each
     * message's persisted structured response payload
     * (products/follow_up_questions/actions) alongside its text when one
     * was stored for it. Only `user`/`assistant` messages are ever
     * returned here (never the intermediate tool-call-request/tool-result
     * messages recentMessages() also returns — a customer never saw
     * those, and StoredConversationMessage has no role for them). A
     * separate read path from recentMessages() because that one feeds
     * ChatRequest's conversation array directly and has no use for
     * UI-only display data.
     *
     * @return list<StoredConversationMessage>
     */
    public function recentMessagesWithResponsePayloads(string $conversationId, int $storeId, int $maxMessages): array;

    /**
     * Appends one turn's messages, in order, then prunes anything beyond
     * $maxMessages for this conversation. A persistence failure here must
     * not propagate — losing this turn's memory is an acceptable
     * degradation, breaking the customer's chat turn is not.
     *
     * @param list<ChatMessage> $messages
     * @param array{products: list<array<string, mixed>>, follow_up_questions: list<string>, actions: list<array<string, mixed>>}|null $lastMessageResponsePayload
     *     attached only to the LAST message in $messages (the turn's
     *     final, customer-visible assistant reply, e.g.
     *     ChatResponseSerializer::serializeDisplayPayload()'s output) —
     *     every earlier message in this same call is stored with no
     *     payload regardless of this value.
     */
    public function appendTurn(
        string $conversationId,
        int $storeId,
        array $messages,
        int $maxMessages,
        ?array $lastMessageResponsePayload = null
    ): void;
}
