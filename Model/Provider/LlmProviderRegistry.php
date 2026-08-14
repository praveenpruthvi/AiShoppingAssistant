<?php

declare(strict_types=1);

namespace Aavirbhava\AiShoppingAssistant\Model\Provider;

use Aavirbhava\AiShoppingAssistant\Api\LlmProviderInterface;
use Aavirbhava\AiShoppingAssistant\Api\Provider\LlmProviderRegistryInterface;
use Aavirbhava\AiShoppingAssistant\Model\Provider\Exception\ProviderConfigurationException;
use Aavirbhava\AiShoppingAssistant\Model\Provider\Exception\ProviderNotFoundException;
use Magento\Framework\Phrase;

/**
 * Registry of LLM providers contributed through Magento DI.
 *
 * Providers are injected as a named array keyed by allowlisted provider
 * identifiers. Identifiers are never turned into class names, and unknown
 * identifiers always fail closed with a sanitized ProviderNotFoundException.
 */
final class LlmProviderRegistry implements LlmProviderRegistryInterface
{
    /**
     * @var array<string, LlmProviderInterface>
     */
    private array $providers = [];

    /**
     * @param array<string, LlmProviderInterface> $providers
     */
    public function __construct(array $providers = [])
    {
        foreach ($providers as $identifier => $provider) {
            if (!is_string($identifier) || $identifier === '') {
                throw new ProviderConfigurationException(
                    new Phrase('Provider identifiers must be non-empty strings.')
                );
            }

            if (!$provider instanceof LlmProviderInterface) {
                throw new ProviderConfigurationException(
                    new Phrase('A registered LLM provider does not implement the provider contract.')
                );
            }

            $this->providers[$identifier] = $provider;
        }
    }

    public function has(string $identifier): bool
    {
        return ProviderIdentifiers::isKnownLlm($identifier) && isset($this->providers[$identifier]);
    }

    public function get(string $identifier): LlmProviderInterface
    {
        if (!$this->has($identifier)) {
            throw new ProviderNotFoundException(
                new Phrase('The requested LLM provider is not available for this store.')
            );
        }

        return $this->providers[$identifier];
    }

    public function all(): array
    {
        return $this->providers;
    }

    public function capabilities(string $identifier): ProviderCapabilities
    {
        return $this->get($identifier)->capabilities();
    }
}