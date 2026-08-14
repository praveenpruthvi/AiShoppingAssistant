<?php

declare(strict_types=1);

namespace Aavirbhava\AiShoppingAssistant\Model\Config\Source;

use Magento\Framework\Data\OptionSourceInterface;

final class Provider implements OptionSourceInterface
{
    /**
     * @return array<int, array{value: string, label: string}>
     */
    public function toOptionArray(): array
    {
        return [
            ['value' => 'openai', 'label' => 'OpenAI'],
            ['value' => 'anthropic', 'label' => 'Anthropic Claude'],
            ['value' => 'xai', 'label' => 'xAI Grok'],
            ['value' => 'openai_compatible', 'label' => 'Local / OpenAI-Compatible'],
        ];
    }
}
