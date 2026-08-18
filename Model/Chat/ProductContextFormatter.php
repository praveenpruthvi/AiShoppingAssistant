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
 */
final class ProductContextFormatter
{
    private const INSTRUCTIONS = <<<'TEXT'
The following products from this store's catalogue may be relevant to the
customer's request, ordered by relevance. This list is the complete and
only set of products you may mention — never add a product you recognize
from any other source, even one you believe is a real, plausible product
for this store; if it is not in this list, it does not exist for this
conversation, and you must never invent a SKU, product name, price, stock
status, or URL. This list does not include price or stock information; do not state
or imply either. If none of these products actually fit the request, say
so instead of forcing a recommendation.
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
