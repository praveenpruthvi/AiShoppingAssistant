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
 * The registry IS the runtime allowlist: only providers contributed by
 * installed Magento modules through DI are resolvable. A provider instance
 * must implement EmbeddingProviderInterface to be accepted here, even if it
 * also implements LlmProviderInterface.
 *
 * Magento DI merges array arguments across modules before this constructor
 * runs, so duplicate keys have already been collapsed into a single entry.
 * Duplicate-contribution detection is therefore not possible inside the
 * registry and is intentionally not attempted.
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
            if (!is_string($identifier)) {
                throw new ProviderConfigurationException(
                    new Phrase('Provider identifiers must be strings.')
                );
            }

            ProviderIdentifiers::assertValid($identifier);

            if (!$provider instanceof EmbeddingProviderInterface) {
                throw new ProviderConfigurationException(
                    new Phrase('A registered embedding provider does not implement the provider contract.')
                );
            }

            ProviderIdentifiers::assertValid($provider->identifier());

            if ($provider->identifier() !== $identifier) {
                throw new ProviderConfigurationException(
                    new Phrase('A registered embedding provider identifier does not match its declaration.')
                );
            }

            $this->providers[$identifier] = $provider;
        }
    }

    public function has(string $identifier): bool
    {
        return isset($this->providers[$identifier]);
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
