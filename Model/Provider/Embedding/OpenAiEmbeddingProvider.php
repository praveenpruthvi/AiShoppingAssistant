<?php

declare(strict_types=1);

namespace Aavirbhava\AiShoppingAssistant\Model\Provider\Embedding;

use Aavirbhava\AiShoppingAssistant\Api\Embedding\EmbeddingRequestInterface;
use Aavirbhava\AiShoppingAssistant\Model\Provider\ProviderCapabilities;

/**
 * OpenAI embeddings adapter.
 *
 * Uses the official HTTPS endpoint. A configured base-URL override is rejected
 * by the endpoint policy. An API key is mandatory and is read from the request
 * (never retained or logged).
 */
final class OpenAiEmbeddingProvider extends AbstractEmbeddingProvider
{
    private const DEFAULT_BASE_URL = 'https://api.openai.com/v1';

    public function identifier(): string
    {
        return 'openai';
    }

    public function dimensions(): int
    {
        // Dimensions are model-dependent and config-scoped; they are validated
        // per request against the configured value.
        return 0;
    }

    public function fingerprint(): string
    {
        return 'openai-embeddings';
    }

    public function capabilities(): ProviderCapabilities
    {
        return new ProviderCapabilities(
            embeddings: true,
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

    protected function buildHeaders(EmbeddingRequestInterface $request): array
    {
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