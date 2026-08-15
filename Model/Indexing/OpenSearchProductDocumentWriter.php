<?php

declare(strict_types=1);

namespace Aavirbhava\AiShoppingAssistant\Model\Indexing;

use Aavirbhava\AiShoppingAssistant\Api\Catalog\ContentHashServiceInterface;
use Aavirbhava\AiShoppingAssistant\Api\Catalog\ProductDocumentInterface;
use Aavirbhava\AiShoppingAssistant\Api\Config\ConfigurationReaderInterface;
use Aavirbhava\AiShoppingAssistant\Api\Indexing\AssistantSearchClientInterface;
use Aavirbhava\AiShoppingAssistant\Api\Indexing\EmbeddingEnrichmentServiceInterface;
use Aavirbhava\AiShoppingAssistant\Api\Indexing\IndexedProductDocumentInterface;
use Aavirbhava\AiShoppingAssistant\Api\Indexing\IndexNamingServiceInterface;
use Aavirbhava\AiShoppingAssistant\Api\Indexing\ProductDocumentWriterInterface;
use Aavirbhava\AiShoppingAssistant\Api\Indexing\ProductIndexMappingInterface;
use Aavirbhava\AiShoppingAssistant\Api\Indexing\RebuildRunContextInterface;
use Aavirbhava\AiShoppingAssistant\Api\Store\StoreScopeInterface;
use Aavirbhava\AiShoppingAssistant\Model\Config\Exception\ConfigurationException;
use Aavirbhava\AiShoppingAssistant\Model\Indexing\Document\IndexedDocumentPayloadBuilder;
use Aavirbhava\AiShoppingAssistant\Model\Indexing\Exception\IndexCompatibilityMismatchException;
use Aavirbhava\AiShoppingAssistant\Model\Indexing\Exception\IndexRunStateInvalidException;
use Aavirbhava\AiShoppingAssistant\Model\Indexing\Exception\IndexScopeMismatchException;
use Aavirbhava\AiShoppingAssistant\Model\Indexing\Exception\OpenSearchBackendUnavailableException;
use Aavirbhava\AiShoppingAssistant\Model\Indexing\Exception\OpenSearchCapabilityUnsupportedException;
use Aavirbhava\AiShoppingAssistant\Model\Indexing\Exception\OpenSearchConfigurationInvalidException;
use Aavirbhava\AiShoppingAssistant\Model\Indexing\Exception\ProductIndexCreateFailedException;
use Aavirbhava\AiShoppingAssistant\Model\Indexing\Exception\ProductIndexingException;

/**
 * OpenSearch-backed product document writer implementing the two-phase
 * lifecycle.
 *
 * beginRun freezes per-store embedding configuration (dimensions and
 * fingerprint), verifies backend availability and capability, and creates one
 * isolated physical index per enabled store — including stores that will
 * receive no documents. beginStore/ writeBatch/ finishStore stream documents
 * into the isolated index; embeddings are generated in bounded batches and
 * every write is verified. activateRun performs one atomic alias update that
 * moves the store read aliases to the new physical indexes and drops only
 * assistant-owned targets; activation is the only moment the live index changes.
 *
 * abortRun is idempotent and never throws: it deletes only run-owned physical
 * indexes that are not currently aliased, so a partially built or failed run is
 * cleaned up without ever touching another store's live data.
 */
final class OpenSearchProductDocumentWriter implements ProductDocumentWriterInterface
{
    /** Maximum storage payloads written in one bulk request. */
    public const MAX_BULK_CHUNK = 100;

    private ?RebuildRunContextInterface $context = null;

    /**
     * @var array<int, array{
     *   scope: StoreScopeInterface,
     *   prefix: string,
     *   indexName: string,
     *   embeddingDimensions: int,
     *   embeddingFingerprint: string,
     *   embeddingBaseUrlHash: string,
     *   finished: bool
     * }>
     */
    private array $stores = [];

    private ?int $currentStoreId = null;

    private bool $activated = false;

    private bool $aborted = false;

    public function __construct(
        private readonly ConfigurationReaderInterface $configurationReader,
        private readonly ContentHashServiceInterface $contentHashService,
        private readonly IndexNamingServiceInterface $namingService,
        private readonly AssistantSearchClientInterface $client,
        private readonly ProductIndexMappingInterface $mapping,
        private readonly EmbeddingEnrichmentServiceInterface $enrichment,
        private readonly IndexedDocumentPayloadBuilder $payloadBuilder
    ) {
    }

    public function beginRun(RebuildRunContextInterface $context): void
    {
        if ($this->context !== null || $this->activated || $this->aborted) {
            throw new IndexRunStateInvalidException();
        }

        $stores = [];
        foreach ($context->enabledScopes() as $scope) {
            $stores[$scope->storeId()] = $this->resolveStore($scope, $context);
        }

        $this->assertBackendCapable();

        $created = [];
        try {
            foreach ($stores as $store) {
                $body = $this->mapping->createBody(
                    $store['scope'],
                    $context,
                    $store['embeddingDimensions'],
                    $store['embeddingFingerprint'],
                    $store['embeddingBaseUrlHash'],
                    $store['indexName']
                );
                $this->client->createIndex($store['indexName'], $body);
                $created[] = $store['indexName'];
            }
        } catch (\Throwable $throwable) {
            $this->cleanupCreated($created);
            throw $throwable instanceof ProductIndexingException
                ? $throwable
                : new ProductIndexCreateFailedException($this->safeCause($throwable));
        }

        $this->context = $context;
        $this->stores = $stores;
        $this->currentStoreId = null;
        $this->activated = false;
        $this->aborted = false;
    }

    public function beginStore(StoreScopeInterface $scope): void
    {
        $this->assertRunOpen();
        $this->assertActive();

        if (!isset($this->stores[$scope->storeId()])) {
            throw new IndexScopeMismatchException();
        }

        $this->currentStoreId = $scope->storeId();
    }

    public function writeBatch(array $documents): void
    {
        $this->assertRunOpen();
        $this->assertActive();
        $this->assertCurrentStore();

        $storeId = $this->currentStoreId;
        $store = $this->stores[$storeId];

        if ($store['finished']) {
            throw new IndexRunStateInvalidException();
        }

        $this->assertDocumentsValid($documents, $storeId);

        $indexed = $this->enrichment->enrich($storeId, $store['embeddingFingerprint'], $documents);

        $this->assertEmbeddingsValid($indexed, $store['embeddingDimensions'], $store['embeddingFingerprint']);

        $payloads = [];
        foreach ($indexed as $item) {
            $payloads[] = $this->payloadBuilder->build($item);
        }

        foreach (array_chunk($payloads, self::MAX_BULK_CHUNK) as $chunk) {
            $this->client->writeDocuments($store['indexName'], $chunk);
        }
    }

    public function finishStore(): void
    {
        $this->assertRunOpen();
        $this->assertActive();
        $this->assertCurrentStore();

        $storeId = $this->currentStoreId;
        if ($this->stores[$storeId]['finished']) {
            throw new IndexRunStateInvalidException();
        }

        $this->stores[$storeId]['finished'] = true;
        $this->currentStoreId = null;
    }

    public function activateRun(): void
    {
        $this->assertRunOpen();
        $this->assertActive();

        if ($this->currentStoreId !== null) {
            throw new IndexRunStateInvalidException();
        }

        $actions = [];
        foreach ($this->stores as $store) {
            if (!$store['finished']) {
                throw new IndexRunStateInvalidException();
            }
            if (!$this->client->indexExists($store['indexName'])) {
                throw new ProductIndexCreateFailedException();
            }

            $this->client->refresh($store['indexName']);

            $alias = $this->namingService->readAlias($store['prefix'], $store['scope']);

            foreach ($this->client->aliasTargets($alias) as $target) {
                if ($this->namingService->isAssistantOwnedIndex($store['prefix'], $target)) {
                    $actions[] = ['remove' => ['alias' => $alias, 'index' => $target]];
                }
            }

            $actions[] = ['add' => ['alias' => $alias, 'index' => $store['indexName']]];
        }

        $this->client->updateAliases($actions);

        $this->activated = true;
    }

    public function abortRun(): void
    {
        if ($this->context === null || $this->activated || $this->aborted) {
            return;
        }

        $this->aborted = true;

        foreach ($this->stores as $store) {
            $indexName = $store['indexName'];

            try {
                if (!$this->client->indexExists($indexName)) {
                    continue;
                }
            } catch (\Throwable $throwable) {
                continue;
            }

            try {
                $alias = $this->namingService->readAlias($store['prefix'], $store['scope']);
                $targets = $this->client->aliasTargets($alias);
                if (in_array($indexName, $targets, true)) {
                    continue;
                }
            } catch (\Throwable $throwable) {
                continue;
            }

            try {
                $this->client->deleteIndex($indexName);
            } catch (\Throwable $throwable) {
                continue;
            }
        }

        $this->stores = [];
        $this->currentStoreId = null;
        $this->context = null;
    }

    /**
     * @return array{
     *   scope: StoreScopeInterface,
     *   prefix: string,
     *   indexName: string,
     *   embeddingDimensions: int,
     *   embeddingFingerprint: string,
     *   embeddingBaseUrlHash: string,
     *   finished: bool
     * }
     */
    private function resolveStore(StoreScopeInterface $scope, RebuildRunContextInterface $context): array
    {
        try {
            $indexing = $this->configurationReader->readIndexing($scope->storeId());
            $embedding = $this->configurationReader->readEmbedding($scope->storeId());
        } catch (ConfigurationException $exception) {
            throw new OpenSearchConfigurationInvalidException($exception);
        }

        $embeddingFingerprint = $this->contentHashService->hash([
            'provider' => $embedding->provider(),
            'model' => $embedding->model(),
            'base_url' => $embedding->baseUrl(),
            'dimensions' => $embedding->dimensions(),
        ]);

        $embeddingBaseUrlHash = $this->contentHashService->hash([
            'base_url' => $embedding->baseUrl(),
        ]);

        return [
            'scope' => $scope,
            'prefix' => $indexing->indexPrefix(),
            'indexName' => $this->namingService->physicalIndex($indexing->indexPrefix(), $scope, $context),
            'embeddingDimensions' => $embedding->dimensions(),
            'embeddingFingerprint' => $embeddingFingerprint,
            'embeddingBaseUrlHash' => $embeddingBaseUrlHash,
            'finished' => false,
        ];
    }

    private function assertBackendCapable(): void
    {
        if (!$this->client->ping()) {
            throw new OpenSearchBackendUnavailableException();
        }

        if (!$this->client->supportsVectorSearch()) {
            throw new OpenSearchCapabilityUnsupportedException();
        }
    }

    /**
     * @param list<string> $created
     */
    private function cleanupCreated(array $created): void
    {
        foreach ($created as $indexName) {
            try {
                $this->client->deleteIndex($indexName);
            } catch (\Throwable $throwable) {
                continue;
            }
        }
    }

    private function assertDocumentsValid(array $documents, int $storeId): void
    {
        foreach ($documents as $document) {
            if (!$document instanceof ProductDocumentInterface) {
                throw new IndexScopeMismatchException();
            }
            if ($document->schemaVersion() !== $this->context->schemaVersion()) {
                throw new IndexCompatibilityMismatchException();
            }
            if ($document->storeId() !== $storeId) {
                throw new IndexScopeMismatchException();
            }
        }
    }

    /**
     * @param list<IndexedProductDocumentInterface> $indexed
     */
    private function assertEmbeddingsValid(array $indexed, int $dimensions, string $fingerprint): void
    {
        foreach ($indexed as $item) {
            if ($item->embeddingFingerprint() !== $fingerprint) {
                throw new IndexCompatibilityMismatchException();
            }
            if ($item->embedding()->dimension() !== $dimensions) {
                throw new IndexCompatibilityMismatchException();
            }
        }
    }

    private function assertRunOpen(): void
    {
        if ($this->context === null) {
            throw new IndexRunStateInvalidException();
        }
    }

    private function assertActive(): void
    {
        if ($this->activated || $this->aborted) {
            throw new IndexRunStateInvalidException();
        }
    }

    private function assertCurrentStore(): void
    {
        if ($this->currentStoreId === null || !isset($this->stores[$this->currentStoreId])) {
            throw new IndexRunStateInvalidException();
        }
    }

    private function safeCause(\Throwable $throwable): ?\Exception
    {
        return $throwable instanceof \Exception ? $throwable : null;
    }
}
