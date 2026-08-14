<?php

declare(strict_types=1);

namespace Aavirbhava\AiShoppingAssistant\Model\Config\Source;

use Magento\Framework\Data\OptionSourceInterface;

final class EmbeddingProvider implements OptionSourceInterface
{
    /**
     * @return array<int, array{value: string, label: string}>
     */
    public function toOptionArray(): array
    {
        return [
            ['value' => 'openai', 'label' => 'OpenAI'],
            ['value' => 'voyage', 'label' => 'Voyage AI'],
            ['value' => 'openai_compatible', 'label' => 'Local / OpenAI-Compatible'],
        ];
    }
}
