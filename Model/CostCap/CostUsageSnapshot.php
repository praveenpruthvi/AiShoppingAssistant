<?php

declare(strict_types=1);

namespace Aavirbhava\AiShoppingAssistant\Model\CostCap;

use Aavirbhava\AiShoppingAssistant\Api\CostCap\CostUsageSnapshotInterface;
use InvalidArgumentException;

final readonly class CostUsageSnapshot implements CostUsageSnapshotInterface
{
    public function __construct(
        private bool $exists,
        private float $costAmount,
        private int $notifiedThresholdRank
    ) {
        if ($costAmount < 0.0) {
            throw new InvalidArgumentException('Cost amount must not be negative.');
        }

        if ($notifiedThresholdRank < CostCapThreshold::NONE || $notifiedThresholdRank > CostCapThreshold::CAP) {
            throw new InvalidArgumentException('Notified threshold rank is out of range.');
        }
    }

    public function exists(): bool
    {
        return $this->exists;
    }

    public function costAmount(): float
    {
        return $this->costAmount;
    }

    public function notifiedThresholdRank(): int
    {
        return $this->notifiedThresholdRank;
    }
}
