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
 * The registry IS the runtime allowlist: only providers contributed by
 * installed Magento modules through DI are resolvable. Installed modules are
 * trusted application code. Configuration only ever stores an identifier and
 * never a class name; identifiers are never turned into class names, and
 * unregistered identifiers always fail closed with a sanitized
 * ProviderNotFoundException.
 *
 * Magento DI merges array arguments across modules before this constructor
 * runs, so duplicate keys have already been collapsed into a single entry.
 * Duplicate-contribution detection is therefore not possible inside the
 * registry and is intentionally not attempted.
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
            if (!is_string($identifier)) {
                throw new ProviderConfigurationException(
                    new Phrase('Provider identifiers must be strings.')
                );
            }

            ProviderIdentifiers::assertValid($identifier);

            if (!$provider instanceof LlmProviderInterface) {
                throw new ProviderConfigurationException(
                    new Phrase('A registered LLM provider does not implement the provider contract.')
                );
            }

            ProviderIdentifiers::assertValid($provider->identifier());

            if ($provider->identifier() !== $identifier) {
                throw new ProviderConfigurationException(
                    new Phrase('A registered LLM provider identifier does not match its declaration.')
                );
            }

            $this->providers[$identifier] = $provider;
        }
    }

    public function has(string $identifier): bool
    {
        return isset($this->providers[$identifier]);
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