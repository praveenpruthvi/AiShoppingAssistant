<?php

declare(strict_types=1);

namespace Aavirbhava\AiShoppingAssistant\Model\Provider\Llm;

use Aavirbhava\AiShoppingAssistant\Model\Config\SecretValue;
use Aavirbhava\AiShoppingAssistant\Model\Provider\ProviderCapabilities;
use Aavirbhava\AiShoppingAssistant\Model\Provider\ProviderIdentifiers;

/**
 * Local OpenAI-compatible chat adapter — architecture.md's original
 * "OpenAiCompatibleProvider," scoped generically to any server speaking
 * OpenAI's /v1/chat/completions wire format (Ollama, vLLM, llama.cpp,
 * LM Studio), with Ollama as the immediate, documented example rather
 * than a Ollama-only class.
 *
 * An explicit base URL is required (mirrors
 * LocalOpenAiCompatibleEmbeddingProvider on the embedding side exactly)
 * and may use HTTP or HTTPS — most local servers, including a default
 * Ollama install, are plain HTTP. The API key is optional: when empty, no
 * Authorization header is sent at all, since most local servers require
 * none; a configured key is only ever used to build the per-request
 * header, never retained or logged.
 *
 * Ollama's own OpenAI-compatible endpoint (verified against Ollama's
 * current docs before writing this, not assumed) accepts `tools` and
 * `response_format` in the same shape OpenAI's real API does — both are
 * inherited unmodified from AbstractChatProvider's shared request-body
 * builder — but documents and exercises the older `max_tokens` field for
 * bounding output length, not the newer `max_completion_tokens`
 * OpenAiProvider uses (an open, unresolved upstream Ollama issue tracks
 * `max_completion_tokens` support); maxOutputTokensField() below reflects
 * that.
 */
final class OpenAiCompatibleProvider extends AbstractChatProvider
{
    public function identifier(): string
    {
        return ProviderIdentifiers::LLM_OPENAI_COMPATIBLE;
    }

    public function capabilities(): ProviderCapabilities
    {
        return new ProviderCapabilities(
            chatGeneration: true,
            toolCalling: true,
            structuredOutput: true,
            apiKeyOptional: true,
            configurableBaseUrl: true
        );
    }

    protected function defaultBaseUrl(): string
    {
        // No default: a local server has no universal well-known address,
        // so ChatEndpointPolicy::localEndpoint() requires an explicit
        // configured base URL and fails closed when none is set.
        return '';
    }

    protected function apiKeyRequired(): bool
    {
        return false;
    }

    /**
     * @return array<string, string>
     */
    protected function buildHeaders(SecretValue $apiKey): array
    {
        if ($apiKey->isEmpty()) {
            return [];
        }

        return [
            'Authorization' => 'Bearer ' . $apiKey->reveal(),
        ];
    }

    protected function maxOutputTokensField(): string
    {
        return 'max_tokens';
    }
}
