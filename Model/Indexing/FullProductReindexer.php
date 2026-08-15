<?php

declare(strict_types=1);

namespace Aavirbhava\AiShoppingAssistant\Model\Indexing;

use Aavirbhava\AiShoppingAssistant\Api\Catalog\ProductDocumentNormalizerInterface;
use Aavirbhava\AiShoppingAssistant\Api\Catalog\ProductIdBatchProviderInterface;
use Aavirbhava\AiShoppingAssistant\Api\Catalog\ProductSnapshotProviderInterface;
use Aavirbhava\AiShoppingAssistant\Api\Config\ConfigurationReaderInterface;
use Aavirbhava\AiShoppingAssistant\Api\Config\IndexingConfigInterface;
use Aavirbhava\AiShoppingAssistant\Api\Indexing\FullProductReindexerInterface;
use Aavirbhava\AiShoppingAssistant\Api\Indexing\ProductDocumentWriterInterface;
use Aavirbhava\AiShoppingAssistant\Api\Indexing\RebuildMetricsInterface;
use Aavirbhava\AiShoppingAssistant\Api\Indexing\RebuildResultInterface;
use Aavirbhava\AiShoppingAssistant\Api\Indexing\RebuildRunContextFactoryInterface;
use Aavirbhava\AiShoppingAssistant\Api\Store\StoreScopeInterface;
use Aavirbhava\AiShoppingAssistant\Api\Store\StoreScopeProviderInterface;
use Aavirbhava\AiShoppingAssistant\Model\Catalog\Exception\CatalogException;
use Aavirbhava\AiShoppingAssistant\Model\Catalog\ProductEligibilityContext;
use Aavirbhava\AiShoppingAssistant\Model\Indexing\Exception\InvalidProductIndexEntityIdsException;
use Aavirbhava\AiShoppingAssistant\Model\Indexing\Exception\AliasActivationFailedException;
use Aavirbhava\AiShoppingAssistant\Model\Indexing\Exception\BulkIndexFailedException;
use Aavirbhava\AiShoppingAssistant\Model\Indexing\Exception\BulkResponseInvalidException;
use Aavirbhava\AiShoppingAssistant\Model\Indexing\Exception\EmbeddingEnrichmentException;
use Aavirbhava\AiShoppingAssistant\Model\Indexing\Exception\IndexCompatibilityMismatchException;
use Aavirbhava\AiShoppingAssistant\Model\Indexing\Exception\IndexDocumentStateInvalidException;
use Aavirbhava\AiShoppingAssistant\Model\Indexing\Exception\IndexRunStateInvalidException;
use Aavirbhava\AiShoppingAssistant\Model\Indexing\Exception\IndexScopeMismatchException;
use Aavirbhava\AiShoppingAssistant\Model\Indexing\Exception\IncrementalIndexTargetInvalidException;
use Aavirbhava\AiShoppingAssistant\Model\Indexing\Exception\OpenSearchBackendUnavailableException;
use Aavirbhava\AiShoppingAssistant\Model\Indexing\Exception\OpenSearchCapabilityUnsupportedException;
use Aavirbhava\AiShoppingAssistant\Model\Indexing\Exception\OpenSearchConfigurationInvalidException;
use Aavirbhava\AiShoppingAssistant\Model\Indexing\Exception\ProductIndexAbortException;
use Aavirbhava\AiShoppingAssistant\Model\Indexing\Exception\ProductIndexAbortFailedException;
use Aavirbhava\AiShoppingAssistant\Model\Indexing\Exception\ProductIndexActivationException;
use Aavirbhava\AiShoppingAssistant\Model\Indexing\Exception\ProductIndexBackendUnavailableException;
use Aavirbhava\AiShoppingAssistant\Model\Indexing\Exception\ProductIndexBatchNormalizationException;
use Aavirbhava\AiShoppingAssistant\Model\Indexing\Exception\ProductIndexBatchWriteException;
use Aavirbhava\AiShoppingAssistant\Model\Indexing\Exception\ProductIndexCreateFailedException;
use Aavirbhava\AiShoppingAssistant\Model\Indexing\Exception\ProductIndexIncrementalSchedulerUnavailableException;
use Aavirbhava\AiShoppingAssistant\Model\Indexing\Exception\ProductIndexingException;
use Aavirbhava\AiShoppingAssistant\Model\Indexing\Exception\ProductIndexMappingInvalidException;
use Aavirbhava\AiShoppingAssistant\Model\Indexing\Exception\ProductIndexNameInvalidException;
use Aavirbhava\AiShoppingAssistant\Model\Indexing\Exception\ProductIndexRunInitException;
use Aavirbhava\AiShoppingAssistant\Model\Indexing\Exception\ProductIndexStorePrepException;

/**
 * Safe full-rebuild orchestration for the assistant product index.
 *
 * Algorithm:
 *  1. Resolve active store scopes and their explicit store-scoped configuration.
 *  2. Skip stores where the assistant is disabled; if none remain, return a safe
 *     no-op result without ever touching the document writer.
 *  3. Generate an immutable run context (server-side run id, schema version).
 *  4. Open a run in the writer, then for each enabled store prepare the store,
 *     stream bounded product batches, load snapshots, normalize them, and send
 *     only eligible ProductDocuments to the writer.
 *  5. Only after every enabled store finished, activate the run.
 *
 * On failure: no new batches are started, abortRun is called exactly once if the
 * run began, activateRun is never called, and a sanitized ProductIndexingException
 * is thrown carrying the aborted-run result. Memory stays bounded: only one batch
 * of ids, snapshots, and documents exists at a time.
 */
final class FullProductReindexer implements FullProductReindexerInterface
{
    /**
     * @var list<array{scope: StoreScopeInterface, config: IndexingConfigInterface}>
     */
    private array $enabled = [];

    /**
     * @var array<string, int>
     */
    private array $counts = [
        'storesSkipped' => 0,
        'storesPrepared' => 0,
        'productIdsExamined' => 0,
        'snapshotsLoaded' => 0,
        'missingProducts' => 0,
        'eligibleDocuments' => 0,
        'batchesWritten' => 0,
    ];

    /**
     * @var array<string, int>
     */
    private array $ineligible = [];

    /**
     * Total active store scopes considered, including disabled ones.
     *
     * @var int
     */
    private int $storesConsidered = 0;

    public function __construct(
        private readonly StoreScopeProviderInterface $storeScopeProvider,
        private readonly ConfigurationReaderInterface $configurationReader,
        private readonly RebuildRunContextFactoryInterface $runContextFactory,
        private readonly ProductIdBatchProviderInterface $idBatchProvider,
        private readonly ProductSnapshotProviderInterface $snapshotProvider,
        private readonly ProductDocumentNormalizerInterface $documentNormalizer,
        private readonly ProductDocumentWriterInterface $documentWriter
    ) {
    }

    public function rebuild(): RebuildResultInterface
    {
        $startedAt = microtime(true);

        try {
            $this->resolveEnabled();
        } catch (ProductIndexingException $exception) {
            throw $exception;
        } catch (\Throwable $throwable) {
            throw new ProductIndexRunInitException($this->safeCause($throwable));
        }

        if ($this->enabled === []) {
            return $this->noOpResult($startedAt);
        }

        $runBegun = false;

        try {
            $context = $this->runContextFactory->create($this->scopeList());
            $this->documentWriter->beginRun($context);
            $runBegun = true;

            foreach ($this->enabled as $entry) {
                $this->prepareStore($entry['scope']);
                $this->reindexStore($entry['scope'], $entry['config']);
                $this->finishStore($entry['scope']);
            }

            $this->activateRun();
        } catch (ProductIndexingException $exception) {
            $aborted = $this->abortedResult($startedAt);
            $this->abort($runBegun, $exception, $aborted);
            throw $this->withResult($exception, $aborted);
        } catch (\Throwable $throwable) {
            $aborted = $this->abortedResult($startedAt);
            $this->abort($runBegun, $throwable, $aborted);
            throw new ProductIndexRunInitException(
                $this->safeCause($throwable),
                $aborted
            );
        }

        return new RebuildResult(
            $this->buildMetrics($startedAt, true),
            RebuildResultInterface::OUTCOME_ACTIVATED
        );
    }

    private function resolveEnabled(): void
    {
        $this->enabled = [];
        $this->counts['storesSkipped'] = 0;

        $allStores = $this->storeScopeProvider->activeStores();
        $this->storesConsidered = count($allStores);

        foreach ($allStores as $scope) {
            $general = $this->configurationReader->readGeneral($scope->storeId());
            if (!$general->isEnabled()) {
                $this->counts['storesSkipped']++;
                continue;
            }

            $this->enabled[] = [
                'scope' => $scope,
                'config' => $this->configurationReader->readIndexing($scope->storeId()),
            ];
        }
    }

    /**
     * @return list<StoreScopeInterface>
     */
    private function scopeList(): array
    {
        $scopes = [];
        foreach ($this->enabled as $entry) {
            $scopes[] = $entry['scope'];
        }

        return $scopes;
    }

    private function prepareStore(StoreScopeInterface $scope): void
    {
        try {
            $this->documentWriter->beginStore($scope);
        } catch (ProductIndexingException $exception) {
            throw new ProductIndexStorePrepException(
                $this->safeCause($exception),
                $exception->rebuildResult()
            );
        } catch (\Throwable $throwable) {
            throw new ProductIndexStorePrepException($this->safeCause($throwable));
        }
    }

    private function finishStore(StoreScopeInterface $scope): void
    {
        try {
            $this->documentWriter->finishStore();
        } catch (ProductIndexingException $exception) {
            throw new ProductIndexStorePrepException(
                $this->safeCause($exception),
                $exception->rebuildResult()
            );
        } catch (\Throwable $throwable) {
            throw new ProductIndexStorePrepException($this->safeCause($throwable));
        }

        $this->counts['storesPrepared']++;
    }

    private function reindexStore(StoreScopeInterface $scope, IndexingConfigInterface $config): void
    {
        foreach ($this->idBatchProvider->batches($scope, $config->batchSize()) as $batch) {
            $this->counts['productIdsExamined'] += count($batch);

            try {
                $snapshotBatch = $this->snapshotProvider->load($scope, $config, $batch);
            } catch (CatalogException $exception) {
                throw new ProductIndexBatchNormalizationException($this->safeCause($exception));
            }

            $this->counts['snapshotsLoaded'] += count($snapshotBatch->snapshots());
            $this->counts['missingProducts'] += count($snapshotBatch->missingProductIds());

            $eligible = [];
            $eligibilityContext = new ProductEligibilityContext($scope->storeId(), $scope->websiteId());

            foreach ($snapshotBatch->snapshots() as $snapshot) {
                $result = $this->documentNormalizer->normalize($snapshot, $eligibilityContext);

                if ($result->eligible() && $result->document() !== null) {
                    $eligible[] = $result->document();
                    $this->counts['eligibleDocuments']++;
                    continue;
                }

                $reason = $result->reasonCode();
                $this->ineligible[$reason] = ($this->ineligible[$reason] ?? 0) + 1;
            }

            if ($eligible === []) {
                continue;
            }

            try {
                $this->documentWriter->writeBatch($eligible);
            } catch (ProductIndexingException $exception) {
                throw new ProductIndexBatchWriteException(
                    $this->safeCause($exception),
                    $exception->rebuildResult()
                );
            } catch (\Throwable $throwable) {
                throw new ProductIndexBatchWriteException($this->safeCause($throwable));
            }

            $this->counts['batchesWritten']++;
        }
    }

    private function activateRun(): void
    {
        try {
            $this->documentWriter->activateRun();
        } catch (ProductIndexingException $exception) {
            throw new ProductIndexActivationException(
                $this->safeCause($exception),
                $exception->rebuildResult()
            );
        } catch (\Throwable $throwable) {
            throw new ProductIndexActivationException($this->safeCause($throwable));
        }
    }

    private function abort(bool $runBegun, ?\Throwable $primary, RebuildResultInterface $aborted): void
    {
        if (!$runBegun) {
            return;
        }

        try {
            $this->documentWriter->abortRun();
        } catch (ProductIndexAbortFailedException $abortFailure) {
            $previous = $primary instanceof \Exception ? $primary : $abortFailure;
            throw new ProductIndexAbortFailedException($previous, $aborted);
        } catch (\Throwable $throwable) {
            // Preserve the primary failure via the previous chain and report the
            // failed cleanup as the stable sanitized index-cleanup code.
            $previous = $primary instanceof \Exception ? $primary : null;
            throw new ProductIndexAbortFailedException($previous, $aborted);
        }
    }

    private function noOpResult(float $startedAt): RebuildResultInterface
    {
        return new RebuildResult(
            $this->buildMetrics($startedAt, false),
            RebuildResultInterface::OUTCOME_NO_OP
        );
    }

    private function abortedResult(float $startedAt): RebuildResultInterface
    {
        return new RebuildResult(
            $this->buildMetrics($startedAt, false),
            RebuildResultInterface::OUTCOME_ABORTED
        );
    }

    private function buildMetrics(float $startedAt, bool $activated): RebuildMetricsInterface
    {
        return new RebuildMetrics(
            $this->storesConsidered,
            $this->counts['storesSkipped'],
            $this->counts['storesPrepared'],
            $this->counts['productIdsExamined'],
            $this->counts['snapshotsLoaded'],
            $this->counts['missingProducts'],
            $this->counts['eligibleDocuments'],
            $this->ineligible,
            $this->counts['batchesWritten'],
            $activated,
            microtime(true) - $startedAt
        );
    }

    private function withResult(
        ProductIndexingException $exception,
        RebuildResultInterface $result
    ): ProductIndexingException {
        if ($exception->rebuildResult() !== null) {
            return $exception;
        }

        $previous = $exception;
        $code = $exception->errorCode();

        return match ($code) {
            ProductIndexingException::ERROR_BACKEND_UNAVAILABLE => new ProductIndexBackendUnavailableException($previous, $result),
            ProductIndexingException::ERROR_INVALID_ENTITY_IDS => new InvalidProductIndexEntityIdsException($previous, $result),
            ProductIndexingException::ERROR_RUN_INIT => new ProductIndexRunInitException($previous, $result),
            ProductIndexingException::ERROR_STORE_PREP => new ProductIndexStorePrepException($previous, $result),
            ProductIndexingException::ERROR_BATCH_NORMALIZATION => new ProductIndexBatchNormalizationException($previous, $result),
            ProductIndexingException::ERROR_BATCH_WRITE => new ProductIndexBatchWriteException($previous, $result),
            ProductIndexingException::ERROR_ACTIVATION => new ProductIndexActivationException($previous, $result),
            ProductIndexingException::ERROR_ABORT => new ProductIndexAbortException($previous, $result),
            ProductIndexingException::ERROR_INCREMENTAL_SCHEDULER_UNAVAILABLE => new ProductIndexIncrementalSchedulerUnavailableException($previous, $result),
            ProductIndexingException::ERROR_INCREMENTAL_TARGET_INVALID => new IncrementalIndexTargetInvalidException($previous, $result),
            ProductIndexingException::ERROR_INDEX_DOCUMENT_STATE_INVALID => new IndexDocumentStateInvalidException($previous, $result),
            ProductIndexingException::ERROR_OPENSEARCH_BACKEND_UNAVAILABLE => new OpenSearchBackendUnavailableException($previous, $result),
            ProductIndexingException::ERROR_OPENSEARCH_CONFIGURATION_INVALID => new OpenSearchConfigurationInvalidException($previous, $result),
            ProductIndexingException::ERROR_OPENSEARCH_CAPABILITY_UNSUPPORTED => new OpenSearchCapabilityUnsupportedException($previous, $result),
            ProductIndexingException::ERROR_INDEX_NAME_INVALID => new ProductIndexNameInvalidException($previous, $result),
            ProductIndexingException::ERROR_INDEX_CREATE_FAILED => new ProductIndexCreateFailedException($previous, $result),
            ProductIndexingException::ERROR_INDEX_MAPPING_INVALID => new ProductIndexMappingInvalidException($previous, $result),
            ProductIndexingException::ERROR_EMBEDDING_ENRICHMENT => new EmbeddingEnrichmentException($previous, $result),
            ProductIndexingException::ERROR_BULK_INDEX => new BulkIndexFailedException($previous, $result),
            ProductIndexingException::ERROR_BULK_RESPONSE_INVALID => new BulkResponseInvalidException($previous, $result),
            ProductIndexingException::ERROR_ALIAS_ACTIVATION => new AliasActivationFailedException($previous, $result),
            ProductIndexingException::ERROR_INDEX_ABORT => new ProductIndexAbortFailedException($previous, $result),
            ProductIndexingException::ERROR_INDEX_RUN_STATE_INVALID => new IndexRunStateInvalidException($previous, $result),
            ProductIndexingException::ERROR_INDEX_SCOPE_MISMATCH => new IndexScopeMismatchException($previous, $result),
            ProductIndexingException::ERROR_INDEX_COMPATIBILITY_MISMATCH => new IndexCompatibilityMismatchException($previous, $result),
            default => new ProductIndexRunInitException($previous, $result),
        };
    }

    private function safeCause(\Throwable $throwable): ?\Exception
    {
        return $throwable instanceof \Exception ? $throwable : null;
    }
}
