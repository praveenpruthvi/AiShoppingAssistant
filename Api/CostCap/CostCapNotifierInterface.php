<?php

declare(strict_types=1);

namespace Aavirbhava\AiShoppingAssistant\Api\CostCap;

use Aavirbhava\AiShoppingAssistant\Api\Config\CostCapConfigInterface;

interface CostCapNotifierInterface
{
    /**
     * Sends one threshold-crossing notification. Callers must only invoke
     * this after CostUsageTrackerInterface::claimThresholdNotification()
     * returns true for the same threshold/period — this interface itself
     * performs no deduplication.
     *
     * @param int $thresholdRank One of Model\CostCap\CostCapThreshold::WARNING/CAP.
     */
    public function notify(
        int $storeId,
        CostCapConfigInterface $config,
        CostUsageSnapshotInterface $usage,
        string $periodKey,
        int $thresholdRank
    ): void;
}
