<?php

declare(strict_types=1);

namespace Aavirbhava\AiShoppingAssistant\Model\Provider\Llm;

use Aavirbhava\AiShoppingAssistant\Model\Config\SecretValue;
use Aavirbhava\AiShoppingAssistant\Model\Provider\ProviderCapabilities;
use Aavirbhava\AiShoppingAssistant\Model\Provider\ProviderIdentifiers;

/**
 * xAI (Grok) chat/completions adapter.
 *
 * xAI's own API is documented as OpenAI-SDK-compatible — the same
 * `/chat/completions` wire format, `Authorization: Bearer` auth, and the
 * `max_tokens` output-length field (xAI's docs use the same field name as
 * every other OpenAI-compatible endpoint this module already talks to,
 * e.g. Ollama's own OpenAiCompatibleProvider — not OpenAI's own newer
 * `max_completion_tokens`) — so this extends AbstractChatProvider directly,
 * the same way OpenAiProvider does, rather than needing its own
 * request/response mapping the way AnthropicProvider/GeminiProvider do.
 *
 * Built to spec against xAI's published API reference; no live API key was
 * available to this session to exercise a real call — see the
 * accompanying status report for exactly what is and isn't verified.
 * ChatEndpointPolicy's existing cloudEndpoint() branch already covers this
 * provider correctly with no change needed there: it only special-cases
 * `openai_compatible`, treating every other identifier (including this
 * one) as a cloud provider restricted to its own documented default URL.
 */
final class XaiProvider extends AbstractChatProvider
{
    private const DEFAULT_BASE_URL = 'https://api.x.ai/v1';

    public function identifier(): string
    {
        return ProviderIdentifiers::LLM_XAI;
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
        return 'max_tokens';
    }
}
