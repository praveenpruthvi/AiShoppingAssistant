<?php

declare(strict_types=1);

namespace Aavirbhava\AiShoppingAssistant\Model\Config\Source;

use Aavirbhava\AiShoppingAssistant\Api\Provider\LlmProviderRegistryInterface;
use Aavirbhava\AiShoppingAssistant\Model\Provider\ProviderOptionService;
use Magento\Framework\Data\OptionSourceInterface;

/**
 * Admin options for the primary and fallback LLM provider fields.
 *
 * Options are derived from the DI registry, so registered third-party LLM
 * providers appear automatically. Only identifiers and trusted labels are
 * returned; provider objects and credentials are never exposed.
 */
final class Provider implements OptionSourceInterface
{
    public function __construct(
        private readonly LlmProviderRegistryInterface $llmProviderRegistry,
        private readonly ProviderOptionService $providerOptionService
    ) {
    }

    /**
     * @return array<int, array{value: string, label: string}>
     */
    public function toOptionArray(): array
    {
        $options = [];
        foreach ($this->providerOptionService->build($this->llmProviderRegistry->all()) as $option) {
            $options[] = ['value' => $option->identifier(), 'label' => $option->label()];
        }

        return $options;
    }
}