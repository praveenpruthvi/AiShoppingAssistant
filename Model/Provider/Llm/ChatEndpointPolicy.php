<?php

declare(strict_types=1);

namespace Aavirbhava\AiShoppingAssistant\Model\Provider\Llm;

use Aavirbhava\AiShoppingAssistant\Model\Provider\Exception\ProviderConfigurationException;
use Aavirbhava\AiShoppingAssistant\Model\Provider\Http\HttpUrlPolicy;
use Aavirbhava\AiShoppingAssistant\Model\Provider\ProviderIdentifiers;
use Magento\Framework\Phrase;

/**
 * Strict endpoint policy for chat providers — mirrors
 * Model\Provider\Embedding\ProviderEndpointPolicy exactly, one level up
 * (chat rather than embeddings): cloud providers (OpenAI, Anthropic, xAI)
 * may only use their documented official HTTPS base URL, a configured
 * override that differs from it is rejected fail-closed; the local
 * OpenAI-compatible provider requires an explicit base URL and may use
 * HTTP or HTTPS, but never credentials or fragments. The inspected URL is
 * never placed in any exception message.
 *
 * Task 1 built OpenAiProvider with this logic inlined (only one chat
 * adapter existed, extracting a shared policy was premature); Task 13
 * extracts it now that a second, genuinely different-shaped chat adapter
 * exists, mirroring the embedding side's already-proven split between
 * ProviderEndpointPolicy and AbstractEmbeddingProvider.
 */
final class ChatEndpointPolicy
{
    public function __construct(
        private readonly HttpUrlPolicy $urlPolicy
    ) {
    }

    public function chatEndpoint(string $providerIdentifier, string $configuredBaseUrl, string $defaultBaseUrl): string
    {
        if ($providerIdentifier === ProviderIdentifiers::LLM_OPENAI_COMPATIBLE) {
            return $this->localEndpoint($configuredBaseUrl);
        }

        return $this->cloudEndpoint($configuredBaseUrl, $defaultBaseUrl);
    }

    private function localEndpoint(string $configuredBaseUrl): string
    {
        if ($configuredBaseUrl === '') {
            throw new ProviderConfigurationException(
                new Phrase('The base URL is not configured for the local chat provider.')
            );
        }

        if (!$this->urlPolicy->isAllowed($configuredBaseUrl)) {
            throw new ProviderConfigurationException(
                new Phrase('The configured chat provider endpoint is not allowed.')
            );
        }

        return rtrim($configuredBaseUrl, '/') . '/chat/completions';
    }

    private function cloudEndpoint(string $configuredBaseUrl, string $defaultBaseUrl): string
    {
        $defaultBaseUrl = rtrim($defaultBaseUrl, '/');

        if ($configuredBaseUrl === '') {
            return $defaultBaseUrl . '/chat/completions';
        }

        if (strcasecmp(rtrim($configuredBaseUrl, '/'), $defaultBaseUrl) !== 0) {
            throw new ProviderConfigurationException(
                new Phrase('A custom API endpoint is not allowed for this chat provider.')
            );
        }

        return $defaultBaseUrl . '/chat/completions';
    }
}
