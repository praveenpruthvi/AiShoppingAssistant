<?php

declare(strict_types=1);

namespace Aavirbhava\AiShoppingAssistant\Api\Config;

interface CostCapConfigInterface
{
    /**
     * The configured spend cap for one period, in the store's base
     * currency units. A value of 0.0 means no cap is configured — the
     * cap is treated as disabled everywhere a caller reads this.
     */
    public function capAmount(): float;

    /**
     * One of Model\Config\Source\CapPeriod::DAILY/WEEKLY/MONTHLY.
     */
    public function period(): string;

    public function warningThresholdPercent(): int;

    public function allowOverride(): bool;

    /**
     * @return list<string>
     */
    public function notificationEmails(): array;
}
