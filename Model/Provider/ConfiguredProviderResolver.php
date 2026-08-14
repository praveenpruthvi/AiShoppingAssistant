<?php

declare(strict_types=1);

namespace Aavirbhava\AiShoppingAssistant\Model\Provider;

use Aavirbhava\AiShoppingAssistant\Api\Config\ConfigurationReaderInterface;
use Aavirbhava\AiShoppingAssistant\Api\EmbeddingProviderInterface;
use Aavirbhava\AiShoppingAssistant\Api\LlmProviderInterface;
use Aavirbhava\AiShoppingAssistant\Api\Provider\ConfiguredProviderResolverInterface;
use Aavirbhava\AiShoppingAssistant\Api\Provider\EmbeddingProviderRegistryInterface;
use Aavirbhava\AiShoppingAssistant\Api\Provider\LlmProviderRegistryInterface;

/**
 * Maps store-scoped configuration to registered provider instances.
 *
 * The registries enforce the identifier allowlist and fail closed, so this
 * resolver stays free of secrets, Object Manager access, and dynamic class
 * resolution. No state is cached across calls; every lookup is scoped to the
 * requested store view.
 */
final class ConfiguredProviderResolver implements ConfiguredProviderResolverInterface
{
    public function __construct(
        private readonly ConfigurationReaderInterface $configurationReader,
        private readonly LlmProviderRegistryInterface $llmProviderRegistry,
        private readonly EmbeddingProviderRegistryInterface $embeddingProviderRegistry
    ) {
    }

    public function primaryLlmProvider(int $storeId): LlmProviderInterface
    {
        return $this->llmProviderRegistry->get(
            $this->configurationReader->readLlm($storeId)->provider()
        );
    }

    public function fallbackLlmProvider(int $storeId): ?LlmProviderInterface
    {
        $fallback = $this->configurationReader->readFallback($storeId);

        if (!$fallback->isEnabled() || $fallback->provider() === '') {
            return null;
        }

        return $this->llmProviderRegistry->get($fallback->provider());
    }

    public function embeddingProvider(int $storeId): EmbeddingProviderInterface
    {
        return $this->embeddingProviderRegistry->get(
            $this->configurationReader->readEmbedding($storeId)->provider()
        );
    }
}