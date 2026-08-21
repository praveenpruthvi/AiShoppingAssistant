<?php

declare(strict_types=1);

namespace Aavirbhava\AiShoppingAssistant\Api\CostCap;

interface CostUsageTrackerInterface
{
    /**
     * Atomically increments the given period's accumulated token counts and
     * cost — never a read-then-write, so concurrent requests in the same
     * period never lose an increment to a race.
     */
    public function recordUsage(
        string $periodKey,
        string $periodType,
        int $inputTokens,
        int $outputTokens,
        float $costAmount
    ): void;

    public function currentUsage(string $periodKey): CostUsageSnapshotInterface;

    /**
     * Atomically claims a threshold-crossing notification for one period:
     * succeeds (returns true) only if the stored notified_threshold_rank is
     * strictly lower than $thresholdRank, and if it succeeds it also raises
     * the stored rank to $thresholdRank in the same compare-and-swap
     * update. A caller must send the corresponding notification if and only
     * if this returns true — this is the sole source of "once per
     * threshold-crossing per period" deduplication, safe under concurrent
     * requests in the same period.
     */
    public function claimThresholdNotification(string $periodKey, int $thresholdRank): bool;
}
