<?php

declare(strict_types=1);

namespace Aavirbhava\AiShoppingAssistant\Api\Indexing;

use Aavirbhava\AiShoppingAssistant\Model\Indexing\Exception\ProductIndexingException;

/**
 * Immutable outcome of one full assistant-index rebuild run.
 *
 * A run always ends in exactly one of three distinguishable outcomes:
 *   - activated: at least one store was prepared and the writer activated the index;
 *   - no_op: no enabled store required indexing, so the writer was never invoked;
 *   - aborted: a failure stopped the run and the writer was aborted safely.
 *
 * On aborted runs the same result is attached to the thrown sanitized
 * ProductIndexingException via its rebuildResult() accessor.
 */
interface RebuildResultInterface
{
    public const OUTCOME_ACTIVATED = 'activated';
    public const OUTCOME_NO_OP = 'no_op';
    public const OUTCOME_ABORTED = 'aborted';

    /**
     * One of the OUTCOME_* constants.
     *
     * @throws ProductIndexingException
     */
    public function outcome(): string;

    public function metrics(): RebuildMetricsInterface;

    public function activated(): bool;

    public function noOp(): bool;

    public function aborted(): bool;
}
