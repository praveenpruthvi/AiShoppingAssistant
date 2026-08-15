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
use Aavirbhava\AiShoppingAssistant\Api\Indexing\StoragePayloadInterface;
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
        } catch (ConfigurationException $exception) {
            throw new OpenSearchConfigurationInvalidException();
        }

        if (!$general->isEnabled()) {
            return;
        }

        try {
            $indexing = $this->configurationReader->readIndexing($scope->storeId());
        } catch (ConfigurationException $exception) {
            throw new OpenSearchConfigurationInvalidException();
        }

        try {
            $embedding = $this->configSnapshot->capture($scope->storeId());
        } catch (ConfigurationException $exception) {
            throw new OpenSearchConfigurationInvalidException();
        }

        $target = $this->resolveTarget($indexing->indexPrefix(), $scope, $embedding);

        $documentId = $this->documentId($scope->storeId(), $productId);
        $batch = $this->loadSnapshot($scope, $indexing, $productId);

        if ($this->batchProvesMissing($batch, $productId)) {
            $this->deleteDocument($target, $indexing->indexPrefix(), $scope, $embedding, $documentId);

            return;
        }

        $snapshot = $this->snapshotForProduct($batch, $productId);
        $result = $this->normalize($snapshot, $scope);
        if (!$result->eligible() || $result->document() === null) {
            $this->deleteDocument($target, $indexing->indexPrefix(), $scope, $embedding, $documentId);

            return;
        }

        $this->writeEligibleDocument(
            $target,
            $indexing->indexPrefix(),
            $scope,
            $embedding,
            $productId,
            $result->document()
        );
    }

    private function resolveTarget(
        string $prefix,
        StoreScopeInterface $scope,
        FrozenEmbeddingConfigInterface $embedding
    ): IncrementalIndexTarget {
        $alias = $this->namingService->readAlias($prefix, $scope);
        $targets = $this->client->aliasTargets($alias);
        if (count($targets) !== 1) {
            throw new IncrementalIndexTargetInvalidException();
        }

        return $this->targetFromPhysicalIndex($alias, $prefix, $scope, $embedding, $targets[0]);
    }

    private function targetFromPhysicalIndex(
        string $alias,
        string $prefix,
        StoreScopeInterface $scope,
        FrozenEmbeddingConfigInterface $embedding,
        string $physicalIndex
    ): IncrementalIndexTarget {
        $parsed = $this->namingService->parseAssistantIndex($prefix, $physicalIndex);
        if ($parsed === null || $parsed['store_id'] !== $scope->storeId()) {
            throw new IncrementalIndexTargetInvalidException();
        }

        $meta = $this->client->indexMeta($physicalIndex);
        $runToken = $this->runTokenFromMeta($meta);

        if (($meta['assistant_index'] ?? null) !== true
            || ($meta['store_id'] ?? null) !== $scope->storeId()
            || ($meta['website_id'] ?? null) !== $scope->websiteId()
            || ($meta['physical_index'] ?? null) !== $physicalIndex
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

        $runId = $meta['run_id'] ?? null;
        if (!is_string($runId)) {
            throw new IncrementalIndexTargetInvalidException();
        }

        return new IncrementalIndexTarget(
            $alias,
            $physicalIndex,
            $scope->storeId(),
            $scope->websiteId(),
            $runId,
            $runToken,
            ProductDocumentSchema::VERSION,
            ProductIndexMappingInterface::MAPPING_VERSION,
            $embedding->dimensions(),
            $embedding->fingerprint(),
            $embedding->baseUrlHash()
        );
    }

    private function assertTargetStillCurrent(
        IncrementalIndexTarget $target,
        string $prefix,
        StoreScopeInterface $scope,
        FrozenEmbeddingConfigInterface $embedding
    ): void {
        if (!$this->configSnapshot->matches($embedding)) {
            throw new IncrementalIndexTargetInvalidException();
        }

        $current = $this->resolveTarget($prefix, $scope, $embedding);
        if (!$target->samePhysicalTarget($current)) {
            throw new IncrementalIndexTargetInvalidException();
        }
    }

    private function writeEligibleDocument(
        IncrementalIndexTarget $target,
        string $prefix,
        StoreScopeInterface $scope,
        FrozenEmbeddingConfigInterface $embedding,
        int $productId,
        ProductDocumentInterface $document
    ): void {
        $this->assertDocumentMatchesScope($scope, $productId, $document);

        $state = $this->client->documentState($target->physicalIndex(), $document->documentId());
        $reusableVector = $this->reusableVector($state, $document, $embedding);

        if ($state !== null
            && $state->completeDocumentHash() === $document->completeDocumentHash()
            && $reusableVector !== null
        ) {
            $this->assertTargetStillCurrent($target, $prefix, $scope, $embedding);

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

        $this->writeDocument($target, $prefix, $scope, $embedding, $this->payloadBuilder->build($indexed));
    }

    private function assertDocumentMatchesScope(
        StoreScopeInterface $scope,
        int $productId,
        ProductDocumentInterface $document
    ): void {
        if ($document->schemaVersion() !== ProductDocumentSchema::VERSION) {
            throw new IndexCompatibilityMismatchException();
        }
        if ($document->entityId() !== $productId) {
            throw new IndexScopeMismatchException();
        }
        if ($document->documentId() !== $this->documentId($scope->storeId(), $productId)) {
            throw new IndexScopeMismatchException();
        }
        if ($document->storeId() !== $scope->storeId()) {
            throw new IndexScopeMismatchException();
        }
        if (!in_array($scope->websiteId(), $document->websiteIds(), true)) {
            throw new IndexScopeMismatchException();
        }
    }

    private function deleteDocument(
        IncrementalIndexTarget $target,
        string $prefix,
        StoreScopeInterface $scope,
        FrozenEmbeddingConfigInterface $embedding,
        string $documentId
    ): void {
        $this->assertTargetStillCurrent($target, $prefix, $scope, $embedding);
        $this->client->deleteDocument($target->physicalIndex(), $documentId);
        $this->assertTargetStillCurrent($target, $prefix, $scope, $embedding);
    }

    private function writeDocument(
        IncrementalIndexTarget $target,
        string $prefix,
        StoreScopeInterface $scope,
        FrozenEmbeddingConfigInterface $embedding,
        StoragePayloadInterface $payload
    ): void {
        $this->assertTargetStillCurrent($target, $prefix, $scope, $embedding);
        $this->client->writeDocument($target->physicalIndex(), $payload);
        $this->assertTargetStillCurrent($target, $prefix, $scope, $embedding);
    }

    private function batchProvesMissing(ProductSnapshotBatchInterface $batch, int $productId): bool
    {
        $snapshots = $batch->snapshots();
        $missing = $batch->missingProductIds();

        if ($snapshots === [] && $missing === [$productId]) {
            return true;
        }

        if (count($snapshots) === 1
            && $missing === []
            && $snapshots[0]->entityId() === $productId
        ) {
            return false;
        }

        throw new ProductIndexBatchNormalizationException();
    }

    private function snapshotForProduct(ProductSnapshotBatchInterface $batch, int $productId): ProductSnapshotInterface
    {
        $snapshots = $batch->snapshots();
        if (count($snapshots) !== 1 || $batch->missingProductIds() !== [] || $snapshots[0]->entityId() !== $productId) {
            throw new ProductIndexBatchNormalizationException();
        }

        return $snapshots[0];
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
    ): ProductSnapshotBatchInterface {
        try {
            return $this->snapshotProvider->load($scope, $indexing, [$productId]);
        } catch (CatalogException $exception) {
            throw new ProductIndexBatchNormalizationException($exception);
        }
    }

    private function normalize(
        ProductSnapshotInterface $snapshot,
        StoreScopeInterface $scope
    ): ProductNormalizationResultInterface {
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
