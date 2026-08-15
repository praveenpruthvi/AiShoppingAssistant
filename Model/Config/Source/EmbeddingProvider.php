<?php

declare(strict_types=1);

namespace Aavirbhava\AiShoppingAssistant\Model\Config\Source;

use Aavirbhava\AiShoppingAssistant\Api\Provider\EmbeddingProviderRegistryInterface;
use Aavirbhava\AiShoppingAssistant\Model\Provider\ProviderOptionService;
use Magento\Framework\Data\OptionSourceInterface;

/**
 * Admin options for the embedding provider field.
 *
 * Options are derived from the DI registry, so registered third-party
 * embedding providers appear automatically. Only identifiers and trusted
 * labels are returned; provider objects and credentials are never exposed.
 */
final class EmbeddingProvider implements OptionSourceInterface
{
    public function __construct(
        private readonly EmbeddingProviderRegistryInterface $embeddingProviderRegistry,
        private readonly ProviderOptionService $providerOptionService
    ) {
    }

    /**
     * @return array<int, array{value: string, label: string}>
     */
    public function toOptionArray(): array
    {
        $options = [];
        foreach ($this->providerOptionService->build($this->embeddingProviderRegistry->all()) as $option) {
            $options[] = ['value' => $option->identifier(), 'label' => $option->label()];
        }

        return $options;
    }
}
