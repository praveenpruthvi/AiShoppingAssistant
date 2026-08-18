<?php

declare(strict_types=1);

namespace Aavirbhava\AiShoppingAssistant\Model\Chat;

use InvalidArgumentException;

/**
 * One persisted conversation message, read back for UI-restore purposes
 * (Controller\Chat\History) — deliberately a separate read shape from
 * Dto\ChatMessage, which recentMessages() still returns unchanged for
 * threading prior turns into a new LLM request. ChatMessage has no room
 * for $responsePayload (products/follow_up_questions/actions) and
 * shouldn't gain one: that data is UI-only, and re-sending it to the LLM
 * as if it were conversation text would inflate token cost for no benefit
 * the model needs — it already made these product/action decisions once
 * and is not asked to reconsider them from a page reload.
 */
final readonly class StoredConversationMessage
{
    private const ALLOWED_ROLES = ['user', 'assistant'];

    /**
     * @param array{products: list<array<string, mixed>>, follow_up_questions: list<string>, actions: list<array<string, mixed>>}|null $responsePayload
     *     only ever set on the final assistant message of a turn — see
     *     ConversationHistoryStoreInterface::appendTurn()
     */
    public function __construct(
        public string $role,
        public string $content,
        public ?array $responsePayload = null
    ) {
        if (!in_array($role, self::ALLOWED_ROLES, true)) {
            throw new InvalidArgumentException(sprintf('Unsupported stored-conversation-message role: %s', $role));
        }

        if ($content === '') {
            throw new InvalidArgumentException('Stored conversation message content must not be empty.');
        }
    }
}
