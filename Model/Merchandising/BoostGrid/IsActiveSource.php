<?php

declare(strict_types=1);

namespace Aavirbhava\AiShoppingAssistant\Model\Merchandising\BoostGrid;

use Magento\Framework\Data\OptionSourceInterface;

/**
 * Options for the boost grid's is_active select filter/column.
 */
class IsActiveSource implements OptionSourceInterface
{
    public function toOptionArray(): array
    {
        return [
            ['value' => 1, 'label' => __('Yes')],
            ['value' => 0, 'label' => __('No')],
        ];
    }
}
