<?php

declare(strict_types=1);

namespace Aavirbhava\AiShoppingAssistant\Model\Provider\Llm;

use Aavirbhava\AiShoppingAssistant\Model\Config\SecretValue;
use Aavirbhava\AiShoppingAssistant\Model\Provider\ProviderCapabilities;
use Aavirbhava\AiShoppingAssistant\Model\Provider\ProviderIdentifiers;

/**
 * OpenAI chat/completions adapter.
 *
 * Uses the official HTTPS endpoint; a configured base-URL override is
 * rejected fail-closed unless it matches the official default — enforced
 * by ChatEndpointPolicy::cloudEndpoint() (Task 13 extracted this from
 * being inlined here, once a second, genuinely local-server-shaped chat
 * adapter — OpenAiCompatibleProvider — existed to justify sharing it).
 * An API key is mandatory and is read from the request (never retained or
 * logged). Stateless between calls: every call receives a fully resolved
 * store-scoped ChatRequest and never retains config, secrets, or raw
 * responses.
 */
final class OpenAiProvider extends AbstractChatProvider
{
    private const DEFAULT_BASE_URL = 'https://api.openai.com/v1';

    public function identifier(): string
    {
        return ProviderIdentifiers::LLM_OPENAI;
    }

    public function capabilities(): ProviderCapabilities
    {
        return new ProviderCapabilities(
            chatGeneration: true,
            toolCalling: true,
            structuredOutput: true,
            apiKeyOptional: false,
            configurableBaseUrl: false
        );
    }

    protected function defaultBaseUrl(): string
    {
        return self::DEFAULT_BASE_URL;
    }

    protected function apiKeyRequired(): bool
    {
        return true;
    }

    /**
     * @return array<string, string>
     */
    protected function buildHeaders(SecretValue $apiKey): array
    {
        return [
            'Authorization' => 'Bearer ' . $apiKey->reveal(),
        ];
    }

    protected function maxOutputTokensField(): string
    {
        return 'max_completion_tokens';
    }
}
