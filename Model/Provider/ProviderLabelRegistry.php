<?php

declare(strict_types=1);

namespace Aavirbhava\AiShoppingAssistant\Model\Provider;

use Aavirbhava\AiShoppingAssistant\Api\Provider\ProviderLabelRegistryInterface;
use Aavirbhava\AiShoppingAssistant\Model\Provider\Exception\ProviderConfigurationException;
use Magento\Framework\Phrase;

/**
 * Static, trusted provider display labels contributed through Magento DI.
 *
 * Labels are application metadata, not customer input. When no label is
 * registered for an identifier, a deterministic humanized fallback is
 * produced from the (already trusted, DI-validated) identifier itself.
 */
final class ProviderLabelRegistry implements ProviderLabelRegistryInterface
{
    /**
     * @var array<string, string>
     */
    private array $labels = [];

    /**
     * @param array<string, string> $labels
     */
    public function __construct(array $labels = [])
    {
        foreach ($labels as $identifier => $label) {
            if (!is_string($identifier)) {
                throw new ProviderConfigurationException(
                    new Phrase('Provider label identifiers must be strings.')
                );
            }

            ProviderIdentifiers::assertValid($identifier);

            if (!is_string($label) || $label === '') {
                throw new ProviderConfigurationException(
                    new Phrase('Provider labels must be non-empty strings.')
                );
            }

            $this->labels[$identifier] = $label;
        }
    }

    public function get(string $identifier): string
    {
        if (isset($this->labels[$identifier])) {
            return $this->labels[$identifier];
        }

        return ucwords(str_replace('_', ' ', $identifier));
    }
}