<?php

declare(strict_types=1);

namespace Aavirbhava\AiShoppingAssistant\Model\Provider\Embedding;

use Aavirbhava\AiShoppingAssistant\Api\Embedding\EmbeddingRequestInterface;
use Aavirbhava\AiShoppingAssistant\Model\Provider\ProviderCapabilities;

/**
 * Local OpenAI-compatible embeddings adapter (Ollama, llama.cpp, vLLM, and
 * similar servers exposing an OpenAI-compatible /embeddings endpoint).
 *
 * An explicit base URL is required and may use HTTP or HTTPS. The API key is
 * optional: when empty, no Authorization header is sent. A configured key is
 * only used to build the per-request header and is never retained or logged.
 */
final class LocalOpenAiCompatibleEmbeddingProvider extends AbstractEmbeddingProvider
{
    public function identifier(): string
    {
        return 'local_openai_compatible';
    }

    public function dimensions(): int
    {
        // Dimensions are model-dependent and config-scoped; they are validated
        // per request against the configured value.
        return 0;
    }

    public function fingerprint(): string
    {
        return 'local-openai-compatible-embeddings';
    }

    public function capabilities(): ProviderCapabilities
    {
        return new ProviderCapabilities(
            embeddings: true,
            apiKeyOptional: true,
            configurableBaseUrl: true
        );
    }

    protected function defaultBaseUrl(): string
    {
        return '';
    }

    protected function apiKeyRequired(): bool
    {
        return false;
    }

    protected function buildHeaders(EmbeddingRequestInterface $request): array
    {
        if ($request->apiKey()->isEmpty()) {
            return [];
        }

        return [
            'Authorization' => 'Bearer ' . $request->apiKey()->reveal(),
        ];
    }

    protected function buildRequestBody(EmbeddingRequestInterface $request): array
    {
        return [
            'model' => $request->model(),
            'input' => array_map(
                static fn ($input): string => $input->text(),
                $request->inputs()
            ),
            'encoding_format' => 'float',
        ];
    }
}