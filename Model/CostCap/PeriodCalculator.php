<?php

declare(strict_types=1);

namespace Aavirbhava\AiShoppingAssistant\Model\CostCap;

use Aavirbhava\AiShoppingAssistant\Model\Config\Source\CapPeriod;
use DateTimeImmutable;
use InvalidArgumentException;

/**
 * Computes the period-start key a given moment falls into — this key is
 * the aavirbhava_ai_cost_cap_usage.period_key primary key, so two calls
 * landing on different sides of a period boundary naturally accumulate
 * into two separate rows: "reset at a new period" falls out of this
 * keying scheme rather than needing an explicit reset/cron step.
 */
final class PeriodCalculator
{
    /**
     * Weekly periods start on Monday (ISO-8601), matching PHP's own 'N'/'W'
     * week-numbering convention used below — a deliberate, disclosed
     * choice, not the only valid convention (US-style Sunday-start weeks
     * exist too).
     */
    public function periodKey(string $periodType, DateTimeImmutable $now): string
    {
        return match ($periodType) {
            CapPeriod::DAILY => $now->format('Y-m-d'),
            CapPeriod::WEEKLY => $this->weekStart($now)->format('Y-m-d'),
            CapPeriod::MONTHLY => $now->format('Y-m-01'),
            default => throw new InvalidArgumentException('Unknown cost cap period type: ' . $periodType),
        };
    }

    private function weekStart(DateTimeImmutable $now): DateTimeImmutable
    {
        $isoDayOfWeek = (int) $now->format('N');

        return $now->modify('-' . ($isoDayOfWeek - 1) . ' days');
    }
}
