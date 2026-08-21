<?php

declare(strict_types=1);

namespace Aavirbhava\AiShoppingAssistant\Model\Config\Source;

use Magento\Framework\Data\OptionSourceInterface;

/**
 * Admin options for the cost cap period field. Values are stored and read
 * back verbatim (see Model\CostCap\PeriodCalculator) — never renamed
 * without a matching change there.
 */
class CapPeriod implements OptionSourceInterface
{
    public const DAILY = 'daily';
    public const WEEKLY = 'weekly';
    public const MONTHLY = 'monthly';

    /**
     * @return array<int, array{value: string, label: string}>
     */
    public function toOptionArray(): array
    {
        return [
            ['value' => self::DAILY, 'label' => __('Daily')],
            ['value' => self::WEEKLY, 'label' => __('Weekly')],
            ['value' => self::MONTHLY, 'label' => __('Monthly')],
        ];
    }
}
