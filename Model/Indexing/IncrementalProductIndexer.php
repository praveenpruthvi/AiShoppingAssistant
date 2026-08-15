<?php

declare(strict_types=1);

namespace Aavirbhava\AiShoppingAssistant\Model\Indexing;

use Aavirbhava\AiShoppingAssistant\Api\Catalog\ContentHashServiceInterface;
use Aavirbhava\AiShoppingAssistant\Api\Catalog\ProductDocumentInterface;
use Aavirbhava\AiShoppingAssistant\Api\Catalog\ProductDocumentNormalizerInterface;
use Aavirbhava\AiShoppingAssistant\Api\Catalog\ProductNormalizationResultInterface;
use Aavirbhava\AiShoppingAssistant\Api\Catalog\ProductSnapshotBatchInterface;
use Aavirbhava\AiShoppingAssistant\Api\Catalog\ProductSnapshotInterface;
use Aavirbhava\AiShoppingAssistant\Api\Catalog\ProductSnapshotProviderInterface;
use Aavirbhava\AiShoppingAssistant\Api\Config\ConfigurationReaderInterface;
use Aavirbhava\AiShoppingAssistant\Api\Config\IndexingConfigInterface;
use Aavirbhava\AiShoppingAssistant\Api\Indexing\AssistantSearchClientInterface;
use Aavirbhava\AiShoppingAssistant\Api\Indexing\EmbeddingConfigSnapshotServiceInterface;
use Aavirbhava\AiShoppingAssistant\Api\Indexing\EmbeddingEnrichmentServiceInterface;
use Aavirbhava\AiShoppingAssistant\Api\Indexing\FrozenEmbeddingConfigInterface;
use Aavirbhava\AiShoppingAssistant\Api\Indexing\IndexedProductDocumentInterface;
use Aavirbhava\AiShoppingAssistant\Api\Indexing\IndexedDocumentStateInterface;
use Aavirbhava\AiShoppingAssistant\Api\Indexing\IndexNamingServiceInterface;
use Aavirbhava\AiShoppingAssistant\Api\Indexing\ProductIncrementalIndexerInterface;
use Aavirbhava\AiShoppingAssistant\Api\Indexing\ProductIndexMappingInterface;
use Aavirbhava\AiShoppingAssistant\Api\Store\StoreScopeInterface;
use Aavirbhava\AiShoppingAssistant\Api\Store\StoreScopeProviderInterface;
use Aavirbhava\AiShoppingAssistant\Model\Catalog\Exception\CatalogException;
use Aavirbhava\AiShoppingAssistant\Model\Catalog\ProductDocumentSchema;
use Aavirbhava\AiShoppingAssistant\Model\Catalog\ProductEligibilityContext;
use Aavirbhava\AiShoppingAssistant\Model\Config\Exception\ConfigurationException;
use Aavirbhava\AiShoppingAssistant\Model\Embedding\EmbeddingVector;
use Aavirbhava\AiShoppingAssistant\Model\Indexing\Document\IndexedDocumentPayloadBuilder;
use Aavirbhava\AiShoppingAssistant\Model\Indexing\Document\IndexedProductDocument;
use Aavirbhava\AiShoppingAssistant\Model\Indexing\Exception\EmbeddingEnrichmentException;
use Aavirbhava\AiShoppingAssistant\Model\Indexing\Exception\IncrementalIndexTargetInvalidException;
use Aavirbhava\AiShoppingAssistant\Model\Indexing\Exception\IndexCompatibilityMismatchException;
use Aavirbhava\AiShoppingAssistant\Model\Indexing\Exception\IndexScopeMismatchException;
use Aavirbhava\AiShoppingAssistant\Model\Indexing\Exception\InvalidProductIndexEntityIdsException;
use Aavirbhava\AiShoppingAssistant\Model\Indexing\Exception\OpenSearchConfigurationInvalidException;
use Aavirbhava\AiShoppingAssistant\Model\Indexing\Exception\ProductIndexBatchNormalizationException;
use Aavirbhava\AiShoppingAssistant\Model\Indexing\Exception\ProductIndexingException;

/**
 * Idempotent incremental indexing core for one Magento product entity id.
 *
 * The service is transport-independent and keeps no processed cache. Duplicate
 * delivery and retry both reload Magento state, live alias metadata, and
 * existing indexed state before doing any write/delete.
 */
final class IncrementalProductIndexer implements ProductIncrementalIndexerInterface
{
    public function __construct(
        private readonly StoreScopeProviderInterface $storeScopeProvider,
        private readonly ConfigurationReaderInterface $configurationReader,
        private readonly ProductSnapshotProviderInterface $snapshotProvider,
        private readonly ProductDocumentNormalizerInterface $documentNormalizer,
        private readonly IndexNamingServiceInterface $namingService,
        private readonly AssistantSearchClientInterface $client,
        private readonly EmbeddingConfigSnapshotServiceInterface $configSnapshot,
        private readonly EmbeddingEnrichmentServiceInterface $enrichment,
        private readonly IndexedDocumentPayloadBuilder $payloadBuilder,
        private readonly ContentHashServiceInterface $contentHashService
    ) {
    }

    public function process(int $productId): void
    {
        if ($productId < 1) {
            throw new InvalidProductIndexEntityIdsException();
        }

        foreach ($this->storeScopeProvider->activeStores() as $scope) {
            $this->processStore($scope, $productId);
        }
    }

    private function processStore(StoreScopeInterface $scope, int $productId): void
    {
        try {
            $general = $this->configurationReader->readGeneral($scope->storeId());
            $indexing = $this->configurationReader->readIndexing($scope->storeId());
        } catch (ConfigurationException $exception) {
            throw new OpenSearchConfigurationInvalidException();
        }

        if (!$general->isEnabled()) {
            return;
        }

        try {
            $embedding = $this->configSnapshot->capture($scope->storeId());
        } catch (ConfigurationException $exception) {
            throw new OpenSearchConfigurationInvalidException();
        }

        $alias = $this->namingService->readAlias($indexing->indexPrefix(), $scope);
        $this->assertAliasTargetCompatible($alias, $indexing->indexPrefix(), $scope, $embedding);

        $documentId = $this->documentId($scope->storeId(), $productId);
        $batch = $this->loadSnapshot($scope, $indexing, $productId);

        if (in_array($productId, $batch->missingProductIds(), true) || $batch->snapshots() === []) {
            $this->client->deleteDocument($alias, $documentId);

            return;
        }

        foreach ($batch->snapshots() as $snapshot) {
            $result = $this->normalize($snapshot, $scope);
            if (!$result->eligible() || $result->document() === null) {
                $this->client->deleteDocument($alias, $documentId);

                return;
            }

            $this->writeEligibleDocument($alias, $scope, $embedding, $result->document());

            return;
        }
    }

    private function assertAliasTargetCompatible(
        string $alias,
        string $prefix,
        StoreScopeInterface $scope,
        FrozenEmbeddingConfigInterface $embedding
    ): void {
        $targets = $this->client->aliasTargets($alias);
        if (count($targets) !== 1) {
            throw new IncrementalIndexTargetInvalidException();
        }

        $target = $targets[0];
        $parsed = $this->namingService->parseAssistantIndex($prefix, $target);
        if ($parsed === null || $parsed['store_id'] !== $scope->storeId()) {
            throw new IncrementalIndexTargetInvalidException();
        }

        $meta = $this->client->indexMeta($target);
        $runToken = $this->runTokenFromMeta($meta);

        if (($meta['assistant_index'] ?? null) !== true
            || ($meta['store_id'] ?? null) !== $scope->storeId()
            || ($meta['website_id'] ?? null) !== $scope->websiteId()
            || ($meta['physical_index'] ?? null) !== $target
            || $runToken === null
            || $runToken !== $parsed['run_token']
            || ($meta['schema_version'] ?? null) !== ProductDocumentSchema::VERSION
            || ($meta['mapping_version'] ?? null) !== ProductIndexMappingInterface::MAPPING_VERSION
            || ($meta['embedding_dimensions'] ?? null) !== $embedding->dimensions()
            || ($meta['embedding_fingerprint'] ?? null) !== $embedding->fingerprint()
            || ($meta['embedding_base_url_hash'] ?? null) !== $embedding->baseUrlHash()
        ) {
            throw new IncrementalIndexTargetInvalidException();
        }
    }

    private function writeEligibleDocument(
        string $alias,
        StoreScopeInterface $scope,
        FrozenEmbeddingConfigInterface $embedding,
        ProductDocumentInterface $document
    ): void {
        $this->assertDocumentMatchesScope($scope, $document);

        $state = $this->client->documentState($alias, $document->documentId());
        $reusableVector = $this->reusableVector($state, $document, $embedding);

        if ($state !== null
            && $state->completeDocumentHash() === $document->completeDocumentHash()
            && $reusableVector !== null
        ) {
            return;
        }

        if ($reusableVector !== null) {
            $embeddingHash = $this->hashEmbedding($reusableVector);
            $indexed = new IndexedProductDocument(
                $document,
                new EmbeddingVector($reusableVector, $embedding->dimensions()),
                $embeddingHash,
                $embedding->fingerprint(),
                gmdate('c')
            );
        } else {
            $indexed = $this->embed($embedding, $document);
        }

        $this->client->writeDocument($alias, $this->payloadBuilder->build($indexed));
    }

    private function assertDocumentMatchesScope(StoreScopeInterface $scope, ProductDocumentInterface $document): void
    {
        if ($document->schemaVersion() !== ProductDocumentSchema::VERSION) {
            throw new IndexCompatibilityMismatchException();
        }
        if ($document->storeId() !== $scope->storeId()) {
            throw new IndexScopeMismatchException();
        }
        if (!in_array($scope->websiteId(), $document->websiteIds(), true)) {
            throw new IndexScopeMismatchException();
        }
    }

    /**
     * @return list<float>|null
     */
    private function reusableVector(
        ?IndexedDocumentStateInterface $state,
        ProductDocumentInterface $document,
        FrozenEmbeddingConfigInterface $embedding
    ): ?array {
        if ($state === null
            || $state->embeddingContentHash() !== $document->embeddingContentHash()
            || $state->embeddingFingerprint() !== $embedding->fingerprint()
        ) {
            return null;
        }

        $rawVector = $state->embedding();
        if (!is_array($rawVector) || count($rawVector) !== $embedding->dimensions() || !array_is_list($rawVector)) {
            return null;
        }

        $vector = [];
        foreach ($rawVector as $value) {
            if ((!is_int($value) && !is_float($value)) || !is_finite((float)$value)) {
                return null;
            }
            $vector[] = (float)$value;
        }

        return $vector;
    }

    private function embed(
        FrozenEmbeddingConfigInterface $embedding,
        ProductDocumentInterface $document
    ): IndexedProductDocumentInterface {
        $indexed = $this->enrichment->enrich($embedding, [$document]);
        if (count($indexed) !== 1) {
            throw new EmbeddingEnrichmentException();
        }

        return $indexed[0];
    }

    /**
     * @return \Aavirbhava\AiShoppingAssistant\Api\Catalog\ProductSnapshotBatchInterface
     */
    private function loadSnapshot(
        StoreScopeInterface $scope,
        IndexingConfigInterface $indexing,
        int $productId
    ): ProductSnapshotBatchInterface
    {
        try {
            return $this->snapshotProvider->load($scope, $indexing, [$productId]);
        } catch (CatalogException $exception) {
            throw new ProductIndexBatchNormalizationException($exception);
        }
    }

    private function normalize(
        ProductSnapshotInterface $snapshot,
        StoreScopeInterface $scope
    ): ProductNormalizationResultInterface
    {
        try {
            return $this->documentNormalizer->normalize(
                $snapshot,
                new ProductEligibilityContext($scope->storeId(), $scope->websiteId())
            );
        } catch (CatalogException $exception) {
            throw new ProductIndexBatchNormalizationException($exception);
        }
    }

    /**
     * @param list<float> $vector
     */
    private function hashEmbedding(array $vector): string
    {
        try {
            return $this->contentHashService->hash($vector);
        } catch (\Throwable $throwable) {
            throw new EmbeddingEnrichmentException();
        }
    }

    private function documentId(int $storeId, int $productId): string
    {
        return sprintf('%d_%d', $storeId, $productId);
    }

    /**
     * @param array<string, mixed> $meta
     */
    private function runTokenFromMeta(array $meta): ?string
    {
        $runId = $meta['run_id'] ?? null;
        if (!is_string($runId)
            || preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i', $runId) !== 1
        ) {
            return null;
        }

        $token = strtolower((string)preg_replace('/[^a-zA-Z0-9]/', '', $runId));
        $token = substr($token, 0, IndexNamingServiceInterface::MAX_RUN_TOKEN_LENGTH);

        return $token !== '' ? $token : null;
    }
}
