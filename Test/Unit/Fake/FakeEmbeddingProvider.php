<?php

declare(strict_types=1);

namespace Aavirbhava\AiShoppingAssistant\Test\Unit\Fake;

use Aavirbhava\AiShoppingAssistant\Api\EmbeddingProviderInterface;
use Aavirbhava\AiShoppingAssistant\Model\Dto\EmbeddingBatch;
use Aavirbhava\AiShoppingAssistant\Model\Provider\ProviderCapabilities;

/**
 * Minimal fake used by provider contract tests.
 */
final class FakeEmbeddingProvider implements EmbeddingProviderInterface
{
    public function __construct(
        private readonly string $identifier,
        private readonly ProviderCapabilities $capabilities = new ProviderCapabilities(
            embeddings: true
        )
    ) {
    }

    public function identifier(): string
    {
        return $this->identifier;
    }

    public function embed(array $texts): EmbeddingBatch
    {
        throw new \RuntimeException('Fake embedding provider is not expected to be invoked.');
    }

    public function dimensions(): int
    {
        return 384;
    }

    public function fingerprint(): string
    {
        return 'fake-embedding-provider';
    }

    public function capabilities(): ProviderCapabilities
    {
        return $this->capabilities;
    }
}