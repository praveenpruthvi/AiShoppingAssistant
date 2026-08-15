<?php

declare(strict_types=1);

namespace Aavirbhava\AiShoppingAssistant\Model\Indexing\Embedding;

use Aavirbhava\AiShoppingAssistant\Api\Catalog\ContentHashServiceInterface;
use Aavirbhava\AiShoppingAssistant\Api\Config\ConfigurationReaderInterface;
use Aavirbhava\AiShoppingAssistant\Api\Config\EmbeddingConfigInterface;
use Aavirbhava\AiShoppingAssistant\Api\Indexing\EmbeddingConfigSnapshotServiceInterface;
use Aavirbhava\AiShoppingAssistant\Api\Indexing\FrozenEmbeddingConfigInterface;

/**
 * Captures and re-validates the store embedding configuration.
 *
 * The snapshot carries only derived hashes: the fingerprint hashes the
 * provider/model/baseUrl/dimensions tuple and the baseUrlHash hashes the base
 * URL. Neither contains a raw URL or secret, so snapshots are safe to store in
 * mapping _meta and to pass through the enrichment pipeline.
 */
final class EmbeddingConfigSnapshotService implements EmbeddingConfigSnapshotServiceInterface
{
    public function __construct(
        private readonly ConfigurationReaderInterface $configurationReader,
        private readonly ContentHashServiceInterface $contentHashService
    ) {
    }

    public function capture(int $storeId): FrozenEmbeddingConfigInterface
    {
        $embedding = $this->configurationReader->readEmbedding($storeId);

        return new FrozenEmbeddingConfig(
            $storeId,
            $embedding->provider(),
            $embedding->model(),
            $embedding->baseUrl(),
            $embedding->dimensions(),
            $this->fingerprint($embedding),
            $this->baseUrlHash($embedding->baseUrl())
        );
    }

    public function matches(FrozenEmbeddingConfigInterface $frozen): bool
    {
        $current = $this->capture($frozen->storeId());

        return $current->provider() === $frozen->provider()
            && $current->model() === $frozen->model()
            && $current->baseUrl() === $frozen->baseUrl()
            && $current->dimensions() === $frozen->dimensions()
            && $current->fingerprint() === $frozen->fingerprint();
    }

    private function fingerprint(EmbeddingConfigInterface $embedding): string
    {
        return $this->contentHashService->hash([
            'provider' => $embedding->provider(),
            'model' => $embedding->model(),
            'base_url' => $embedding->baseUrl(),
            'dimensions' => $embedding->dimensions(),
        ]);
    }

    private function baseUrlHash(string $baseUrl): string
    {
        return $this->contentHashService->hash([
            'base_url' => $baseUrl,
        ]);
    }
}