<?php

declare(strict_types=1);

namespace Aavirbhava\AiShoppingAssistant\Model\Chat;

use Aavirbhava\AiShoppingAssistant\Model\Dto\ChatMessage;
use Aavirbhava\AiShoppingAssistant\Model\Retrieval\SearchCandidate;

/**
 * Formats ranked SearchCandidates into a system ChatMessage the LLM can
 * ground its answer on.
 *
 * Deliberately minimal — this is not the structured response contract (that
 * governs what leaves the backend to the customer; this only governs what
 * goes into the prompt) and it explicitly instructs the model not to state
 * prices or stock, since the index carries neither and nothing has
 * revalidated them live yet (a later task).
 *
 * The instructions explicitly permit a product already named with its
 * real SKU earlier in the same conversation, not just this turn's own
 * list (Task 26) — live-reproduced that the original "this list is the
 * complete and only set of products you may mention" wording actively
 * discouraged the model from answering a short follow-up ("medium size",
 * "the cheaper one") by referencing a product from the immediately
 * preceding turn, even once ChatEntryPipeline started carrying that
 * turn's real, re-revalidated SKUs forward into this turn's verified set
 * (PriorTurnProductCarryOver) — the prompt was still telling it not to.
 * Safe to relax: OutputValidator's fabricated_sku check is the actual
 * security boundary (it only accepts a SKU genuinely present in this
 * turn's verified set), so this wording change can only make the model
 * more willing to reference something already legitimately available,
 * never able to smuggle in something that wasn't.
 */
final class ProductContextFormatter
{
    private const INSTRUCTIONS = <<<'TEXT'
The following products from this store's catalogue may be relevant to the
customer's request, ordered by relevance. Recommend only a product from
this list, a product you already named with its real SKU earlier in this
same conversation, or a product a tool call in this turn returned — never
invent a SKU, product name, price, stock status, or URL from any other
source. This list does not include price or stock information; do not state
or imply either. If nothing here or from earlier in the conversation
actually fits the request, say so instead of forcing a recommendation.
TEXT;

    /**
     * @param list<SearchCandidate> $candidates
     */
    public function format(array $candidates): ?ChatMessage
    {
        if ($candidates === []) {
            return null;
        }

        $lines = array_map(
            fn (SearchCandidate $candidate): string => $this->formatCandidate($candidate),
            $candidates
        );

        return new ChatMessage('system', self::INSTRUCTIONS . "\n\n" . implode("\n", $lines));
    }

    private function formatCandidate(SearchCandidate $candidate): string
    {
        $parts = ['SKU: ' . $candidate->sku, 'Name: ' . $candidate->name];

        if ($candidate->categoryNames !== []) {
            $parts[] = 'Categories: ' . implode(', ', $candidate->categoryNames);
        }

        foreach ($candidate->attributes as $attribute) {
            if ($attribute['values'] === []) {
                continue;
            }
            $parts[] = $attribute['label'] . ': ' . implode(', ', $attribute['values']);
        }

        return '- ' . implode(' | ', $parts);
    }
}
