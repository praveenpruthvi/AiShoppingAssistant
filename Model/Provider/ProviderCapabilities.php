<?php

declare(strict_types=1);

namespace Aavirbhava\AiShoppingAssistant\Model\Provider;

/**
 * Immutable capability metadata for a provider.
 *
 * Every capability defaults to false so that providers must explicitly declare
 * support. The value object never carries secrets or mutable provider
 * instances; it is infrastructure for future adapters and fake contract tests.
 */
final readonly class ProviderCapabilities
{
    public function __construct(
        private bool $chatGeneration = false,
        private bool $embeddings = false,
        private bool $toolCalling = false,
        private bool $structuredOutput = false,
        private bool $streaming = false,
        private bool $apiKeyOptional = false,
        private bool $configurableBaseUrl = false
    ) {
    }

    public function supportsChatGeneration(): bool
    {
        return $this->chatGeneration;
    }

    public function supportsEmbeddings(): bool
    {
        return $this->embeddings;
    }

    public function supportsToolCalling(): bool
    {
        return $this->toolCalling;
    }

    public function supportsStructuredOutput(): bool
    {
        return $this->structuredOutput;
    }

    public function supportsStreaming(): bool
    {
        return $this->streaming;
    }

    public function isApiKeyOptional(): bool
    {
        return $this->apiKeyOptional;
    }

    public function supportsConfigurableBaseUrl(): bool
    {
        return $this->configurableBaseUrl;
    }
}