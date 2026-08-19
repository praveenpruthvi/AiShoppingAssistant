<?php

declare(strict_types=1);

namespace Aavirbhava\AiShoppingAssistant\Model\Chat\Debug;

/**
 * Mutable, request-scoped accumulator for one ChatEntryPipeline::handle()
 * call's diagnostic facts.
 *
 * Deliberately a plain mutable holder rather than an immutable value
 * object: ChatEntryPipeline fills in each field as the pipeline actually
 * reaches that stage (scope decision, retrieval, the live-availability
 * filter, the final response), and several stages are conditionally
 * skipped entirely (a disabled store or an out-of-scope message never
 * reaches retrieval at all). Every field stays null until the stage that
 * sets it is actually reached, so a logged entry always reflects exactly
 * how far this request really got — never a guessed or default value.
 */
final class ChatDebugTrace
{
    public ?bool $inScope = null;
    public ?string $scopeReasonCode = null;

    public ?string $retrievalQuery = null;

    /**
     * @var list<array{sku: string, bm25_score: float, vector_score: float, rank_score: float}>|null
     */
    public ?array $candidates = null;

    public ?int $availabilityFilterBeforeCount = null;
    public ?int $availabilityFilterAfterCount = null;

    /**
     * @var list<string>|null
     */
    public ?array $availabilityFilterDroppedSkus = null;

    /**
     * @var array{max: ?float, max_inclusive: bool, min: ?float, min_inclusive: bool}|null
     */
    public ?array $priceConstraint = null;

    /**
     * @var list<string>|null
     */
    public ?array $priceConstraintAddedSkus = null;

    /**
     * @var list<string>|null
     */
    public ?array $priceConstraintRemovedSkus = null;

    /**
     * Real, re-revalidated SKUs carried forward from the immediately
     * preceding assistant turn (Task 26) — null when no conversation
     * history exists for this turn or the prior turn had no products;
     * an empty array when history exists but nothing carried over
     * revalidated successfully (e.g. everything sold out since).
     *
     * @var list<string>|null
     */
    public ?array $carriedOverSkus = null;

    /**
     * @var list<string>|null
     */
    public ?array $finalProductSkus = null;

    public string $outcome = 'incomplete';

    public function __construct(
        public readonly string $message
    ) {
    }
}
