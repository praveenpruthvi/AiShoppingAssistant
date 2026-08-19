<?php

declare(strict_types=1);

namespace Aavirbhava\AiShoppingAssistant\Model\Chat;

use Aavirbhava\AiShoppingAssistant\Api\Chat\ConversationHistoryStoreInterface;

/**
 * Recovers the SKUs shown in the most recent assistant turn that actually
 * had products, so ChatEntryPipeline can carry them forward into this
 * turn's verified set (Task 26) — a short follow-up ("medium size", "the
 * cheaper one") is, on its own, a weak retrieval query with no product-
 * type signal (live-reproduced returning eight completely unrelated
 * candidates for "the cheaper one"), so without this, whether the model
 * can still answer correctly depends entirely on it independently
 * choosing to call a tool (e.g. check_inventory) with a SKU it merely
 * remembers from conversation-history text — which worked in one live
 * test and failed (fabricated_sku, generic fallback) in another. This
 * makes the immediately preceding turn's real products available every
 * time, not just when the model happens to recover on its own.
 *
 * Reads via recentMessagesWithResponsePayloads() (Task 20's UI-restore
 * read path), not recentMessages() — only that one carries structured
 * product data; only ever returns SKUs from an assistant message that was
 * actually persisted, which — per ChatEntryPipeline's own persistence
 * rule — only ever happens for a turn that already passed OutputValidator,
 * so there is no path for a hallucinated or rejected SKU to be carried
 * forward. The caller is still expected to re-revalidate every SKU
 * returned here live before trusting it for this turn — a product legitimately
 * shown two messages ago may have sold out or been disabled since.
 */
final class PriorTurnProductCarryOver
{
    public function __construct(
        private readonly ConversationHistoryStoreInterface $historyStore
    ) {
    }

    /**
     * @return list<string>
     */
    public function skus(string $conversationId, int $storeId, int $maxMessages): array
    {
        $messages = $this->historyStore->recentMessagesWithResponsePayloads($conversationId, $storeId, $maxMessages);

        for ($index = count($messages) - 1; $index >= 0; $index--) {
            $message = $messages[$index];

            if ($message->role !== 'assistant' || $message->responsePayload === null) {
                continue;
            }

            $skus = array_values(array_unique(array_map(
                static fn (array $product): string => (string) $product['sku'],
                $message->responsePayload['products']
            )));

            if ($skus !== []) {
                return $skus;
            }
        }

        return [];
    }
}
