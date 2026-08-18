<?php

declare(strict_types=1);

namespace Aavirbhava\AiShoppingAssistant\Model\Chat;

use Aavirbhava\AiShoppingAssistant\Api\Chat\ConversationHistoryStoreInterface;

/**
 * Shapes ConversationHistoryStoreInterface::recentMessagesWithResponsePayloads()'s
 * result into exactly what the widget needs to restore its transcript
 * after a page reload/new tab — the same shape a live turn's response
 * already carries (products/follow_up_questions/actions, via
 * ChatResponseSerializer::serializeDisplayPayload()), so a restored turn
 * renders through the identical product-card code a live turn uses.
 *
 * The store's own recentMessagesWithResponsePayloads() (via
 * StoredConversationMessage's constructor invariants) already excludes
 * the intermediate tool-call-request/tool-result messages a turn's
 * round-trip produces — a customer never saw those, and this class has
 * nothing further to filter; it only reshapes what's already the correct,
 * customer-visible set.
 *
 * A message whose response payload was never persisted (every `user`
 * message, and any `assistant` message written before Task 20 added this
 * column) restores with empty products/follow_up_questions/actions —
 * still a correct, readable transcript, just without cards for that one
 * older turn.
 */
final class ConversationHistoryViewBuilder
{
    public function __construct(
        private readonly ConversationHistoryStoreInterface $historyStore
    ) {
    }

    /**
     * @return list<array{role: string, message: string, products: list<array<string, mixed>>, follow_up_questions: list<string>, actions: list<array<string, mixed>>}>
     */
    public function build(string $conversationId, int $storeId, int $maxMessages): array
    {
        $messages = $this->historyStore->recentMessagesWithResponsePayloads($conversationId, $storeId, $maxMessages);

        return array_map(
            static function (StoredConversationMessage $message): array {
                $payload = $message->responsePayload;

                return [
                    'role' => $message->role,
                    'message' => $message->content,
                    'products' => $payload['products'] ?? [],
                    'follow_up_questions' => $payload['follow_up_questions'] ?? [],
                    'actions' => $payload['actions'] ?? [],
                ];
            },
            $messages
        );
    }
}
