<?php

declare(strict_types=1);

namespace Aavirbhava\AiShoppingAssistant\Model\CostCap;

/**
 * Ordinal ranks for the two dedupe-able notification thresholds, stored in
 * aavirbhava_ai_cost_cap_usage.notified_threshold_rank. Ranks are
 * monotonically increasing so a single compare-and-swap UPDATE
 * (`notified_threshold_rank < :rank`) can claim either threshold — including
 * both in the same recording call if a single large usage jump crosses the
 * warning threshold and the cap simultaneously (see CostUsageRecorder).
 */
final class CostCapThreshold
{
    public const NONE = 0;
    public const WARNING = 1;
    public const CAP = 2;

    private function __construct()
    {
    }
}
