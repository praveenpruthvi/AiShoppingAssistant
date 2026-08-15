<?php

declare(strict_types=1);

namespace Aavirbhava\AiShoppingAssistant\Model\Provider\Embedding;

use Aavirbhava\AiShoppingAssistant\Model\Embedding\Exception\EmbeddingConfigurationException;
use Aavirbhava\AiShoppingAssistant\Model\Provider\Http\HttpUrlPolicy;
use Aavirbhava\AiShoppingAssistant\Model\Provider\ProviderIdentifiers;
use Magento\Framework\Phrase;

/**
 * Strict endpoint policy for embedding providers.
 *
 * Cloud providers (OpenAI, Voyage) may only use their documented official HTTPS
 * base URL; a configured override that differs from the official base URL is
 * rejected fail-closed. The local OpenAI-compatible provider requires an
 * explicit base URL and may use HTTP or HTTPS, but never credentials or
 * fragments. The inspected URL is never placed in any exception message.
 */
final class ProviderEndpointPolicy
{
    public function __construct(
        private readonly HttpUrlPolicy $urlPolicy
    ) {
    }

    public function embeddingsEndpoint(string $providerIdentifier, string $configuredBaseUrl, string $defaultBaseUrl): string
    {
        if ($providerIdentifier === ProviderIdentifiers::EMBEDDING_LOCAL_OPENAI_COMPATIBLE) {
            return $this->localEndpoint($configuredBaseUrl);
        }

        return $this->cloudEndpoint($configuredBaseUrl, $defaultBaseUrl);
    }

    private function localEndpoint(string $configuredBaseUrl): string
    {
        if ($configuredBaseUrl === '') {
            throw new EmbeddingConfigurationException(
                new Phrase('The base URL is not configured for the local embedding provider.')
            );
        }

        if (!$this->urlPolicy->isAllowed($configuredBaseUrl)) {
            throw new EmbeddingConfigurationException(
                new Phrase('The configured embedding provider endpoint is not allowed.')
            );
        }

        return rtrim($configuredBaseUrl, '/') . '/embeddings';
    }

    private function cloudEndpoint(string $configuredBaseUrl, string $defaultBaseUrl): string
    {
        $defaultBaseUrl = rtrim($defaultBaseUrl, '/');

        if ($configuredBaseUrl === '') {
            return $defaultBaseUrl . '/embeddings';
        }

        if (strcasecmp(rtrim($configuredBaseUrl, '/'), $defaultBaseUrl) !== 0) {
            throw new EmbeddingConfigurationException(
                new Phrase('A custom API endpoint is not allowed for this embedding provider.')
            );
        }

        return $defaultBaseUrl . '/embeddings';
    }
}
