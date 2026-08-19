<?php

declare(strict_types=1);

namespace Aavirbhava\AiShoppingAssistant\Model\Chat;

use Aavirbhava\AiShoppingAssistant\Model\Chat\Response\AssistantResponse;

/**
 * PriceConstraintReconciler's outcome: the (possibly corrected)
 * AssistantResponse, plus exactly which SKUs were added or removed —
 * carried separately so ChatEntryPipeline can log them to the debug trace
 * without re-deriving the diff.
 */
final readonly class PriceConstraintReconciliationResult
{
    /**
     * @param list<string> $addedSkus
     * @param list<string> $removedSkus
     */
    public function __construct(
        public AssistantResponse $response,
        public array $addedSkus,
        public array $removedSkus
    ) {
    }
}
