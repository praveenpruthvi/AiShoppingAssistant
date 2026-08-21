<?php

declare(strict_types=1);

namespace Aavirbhava\AiShoppingAssistant\Api\CostCap;

interface CostUsageSnapshotInterface
{
    /**
     * False for a period with no recorded usage yet — costAmount()/
     * notifiedThresholdRank() are both zero in that case too, so a caller
     * that only cares about "is the cap reached" never needs to branch on
     * this; it exists for callers that care about the distinction (tests,
     * diagnostics).
     */
    public function exists(): bool;

    public function costAmount(): float;

    /**
     * One of Model\CostCap\CostCapThreshold::NONE/WARNING/CAP.
     */
    public function notifiedThresholdRank(): int;
}
