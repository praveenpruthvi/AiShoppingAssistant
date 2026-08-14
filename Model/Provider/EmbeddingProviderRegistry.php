<?php

declare(strict_types=1);

namespace Aavirbhava\AiShoppingAssistant\Model\Provider;

use Aavirbhava\AiShoppingAssistant\Api\EmbeddingProviderInterface;
use Aavirbhava\AiShoppingAssistant\Api\Provider\EmbeddingProviderRegistryInterface;
use Aavirbhava\AiShoppingAssistant\Model\Provider\Exception\ProviderConfigurationException;
use Aavirbhava\AiShoppingAssistant\Model\Provider\Exception\ProviderNotFoundException;
use Magento\Framework\Phrase;

/**
 * Registry of embedding providers contributed through Magento DI.
 *
 * Chat and embedding providers are deliberately kept in separate registries.
 * Identifiers are never turned into class names, and unknown identifiers
 * always fail closed with a sanitized ProviderNotFoundException.
 */
final class EmbeddingProviderRegistry implements EmbeddingProviderRegistryInterface
{
    /**
     * @var array<string, EmbeddingProviderInterface>
     */
    private array $providers = [];

    /**
     * @param array<string, EmbeddingProviderInterface> $providers
     */
    public function __construct(array $providers = [])
    {
        foreach ($providers as $identifier => $provider) {
            if (!is_string($identifier) || $identifier === '') {
                throw new ProviderConfigurationException(
                    new Phrase('Provider identifiers must be non-empty strings.')
                );
            }

            if (!$provider instanceof EmbeddingProviderInterface) {
                throw new ProviderConfigurationException(
                    new Phrase('A registered embedding provider does not implement the provider contract.')
                );
            }

            $this->providers[$identifier] = $provider;
        }
    }

    public function has(string $identifier): bool
    {
        return ProviderIdentifiers::isKnownEmbedding($identifier) && isset($this->providers[$identifier]);
    }

    public function get(string $identifier): EmbeddingProviderInterface
    {
        if (!$this->has($identifier)) {
            throw new ProviderNotFoundException(
                new Phrase('The requested embedding provider is not available for this store.')
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