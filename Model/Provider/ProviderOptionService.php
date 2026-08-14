<?php

declare(strict_types=1);

namespace Aavirbhava\AiShoppingAssistant\Model\Provider;

use Aavirbhava\AiShoppingAssistant\Api\Provider\ProviderLabelRegistryInterface;

/**
 * Builds deterministic Admin option lists from registry metadata.
 *
 * Options are sorted by identifier so output is stable regardless of DI merge
 * order. The result contains only identifiers and trusted labels; provider
 * instances, capabilities, and credentials never leave the registry.
 */
final class ProviderOptionService
{
    public function __construct(
        private readonly ProviderLabelRegistryInterface $labelRegistry
    ) {
    }

    /**
     * @param array<string, object> $providers
     * @return list<ProviderOption>
     */
    public function build(array $providers): array
    {
        $identifiers = array_keys($providers);
        sort($identifiers, SORT_STRING);

        $options = [];
        foreach ($identifiers as $identifier) {
            $options[] = new ProviderOption(
                (string) $identifier,
                $this->labelRegistry->get((string) $identifier)
            );
        }

        return $options;
    }
}