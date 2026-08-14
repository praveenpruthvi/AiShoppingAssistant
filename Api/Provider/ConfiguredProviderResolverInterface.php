<?php

declare(strict_types=1);

namespace Aavirbhava\AiShoppingAssistant\Api\Provider;

use Aavirbhava\AiShoppingAssistant\Api\EmbeddingProviderInterface;
use Aavirbhava\AiShoppingAssistant\Api\LlmProviderInterface;

/**
 * Resolves a store-scoped configured provider name to a registered provider.
 *
 * Implementations must never expose secrets and must never build providers
 * from customer input or arbitrary class names found in configuration.
 */
interface ConfiguredProviderResolverInterface
{
    public function primaryLlmProvider(int $storeId): LlmProviderInterface;

    /**
     * Returns null when fallback is disabled or unset, and throws when it is
     * explicitly enabled but cannot be resolved.
     */
    public function fallbackLlmProvider(int $storeId): ?LlmProviderInterface;

    public function embeddingProvider(int $storeId): EmbeddingProviderInterface;
}