<?php

declare(strict_types=1);

namespace Aavirbhava\AiShoppingAssistant\Test\Unit\Model\Indexing;

use Aavirbhava\AiShoppingAssistant\Api\Catalog\ProductDocumentInterface;
use Aavirbhava\AiShoppingAssistant\Api\Catalog\ProductDocumentNormalizerInterface;
use Aavirbhava\AiShoppingAssistant\Api\Catalog\ProductIdBatchProviderInterface;
use Aavirbhava\AiShoppingAssistant\Api\Catalog\ProductNormalizationResultInterface;
use Aavirbhava\AiShoppingAssistant\Api\Catalog\ProductSnapshotBatchInterface;
use Aavirbhava\AiShoppingAssistant\Api\Catalog\ProductSnapshotInterface;
use Aavirbhava\AiShoppingAssistant\Api\Catalog\ProductSnapshotProviderInterface;
use Aavirbhava\AiShoppingAssistant\Api\Config\ConfigurationReaderInterface;
use Aavirbhava\AiShoppingAssistant\Api\Config\GeneralConfigInterface;
use Aavirbhava\AiShoppingAssistant\Api\Config\IndexingConfigInterface;
use Aavirbhava\AiShoppingAssistant\Api\Indexing\RebuildResultInterface;
use Aavirbhava\AiShoppingAssistant\Api\Indexing\RebuildRunContextFactoryInterface;
use Aavirbhava\AiShoppingAssistant\Api\Store\StoreScopeInterface;
use Aavirbhava\AiShoppingAssistant\Api\Store\StoreScopeProviderInterface;
use Aavirbhava\AiShoppingAssistant\Model\Catalog\Exception\CatalogException;
use Aavirbhava\AiShoppingAssistant\Model\Indexing\Exception\EmbeddingEnrichmentException;
use Aavirbhava\AiShoppingAssistant\Model\Indexing\Exception\OpenSearchBackendUnavailableException;
use Aavirbhava\AiShoppingAssistant\Model\Indexing\Exception\ProductIndexAbortFailedException;
use Aavirbhava\AiShoppingAssistant\Model\Indexing\Exception\ProductIndexActivationException;
use Aavirbhava\AiShoppingAssistant\Model\Indexing\Exception\ProductIndexBackendUnavailableException;
use Aavirbhava\AiShoppingAssistant\Model\Indexing\Exception\ProductIndexBatchNormalizationException;
use Aavirbhava\AiShoppingAssistant\Model\Indexing\Exception\ProductIndexBatchWriteException;
use Aavirbhava\AiShoppingAssistant\Model\Indexing\Exception\ProductIndexRunInitException;
use Aavirbhava\AiShoppingAssistant\Model\Indexing\FullProductReindexer;
use Aavirbhava\AiShoppingAssistant\Model\Indexing\RebuildRunContext;
use Aavirbhava\AiShoppingAssistant\Test\Unit\Fake\FakeProductDocumentWriter;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

#[CoversClass(FullProductReindexer::class)]
final class FullProductReindexerTest extends TestCase
{
    private const RUN_ID = '9f6f0c80-5d3b-4b2a-8e7c-1a2b3c4d5e6f';

    private FakeProductDocumentWriter $writer;

    /**
     * @var StoreScopeInterface&MockObject
     */
    private $scope;

    /**
     * @var StoreScopeProviderInterface&MockObject
     */
    private $storeScopeProvider;

    /**
     * @var ConfigurationReaderInterface&MockObject
     */
    private $configurationReader;

    /**
     * @var RebuildRunContextFactoryInterface&MockObject
     */
    private $runContextFactory;

    /**
     * @var ProductIdBatchProviderInterface&MockObject
     */
    private $idBatchProvider;

    /**
     * @var ProductSnapshotProviderInterface&MockObject
     */
    private $snapshotProvider;

    /**
     * @var ProductDocumentNormalizerInterface&MockObject
     */
    private $documentNormalizer;

    /**
     * @var list<ProductSnapshotInterface>
     */
    private array $snapshots = [];

    protected function setUp(): void
    {
        $this->writer = new FakeProductDocumentWriter();

        $this->scope = $this->createMock(StoreScopeInterface::class);
        $this->scope->method('storeId')->willReturn(2);
        $this->scope->method('websiteId')->willReturn(1);

        $this->storeScopeProvider = $this->createMock(StoreScopeProviderInterface::class);
        $this->configurationReader = $this->createMock(ConfigurationReaderInterface::class);
        $this->runContextFactory = $this->createMock(RebuildRunContextFactoryInterface::class);
        $this->idBatchProvider = $this->createMock(ProductIdBatchProviderInterface::class);
        $this->snapshotProvider = $this->createMock(ProductSnapshotProviderInterface::class);
        $this->documentNormalizer = $this->createMock(ProductDocumentNormalizerInterface::class);
    }

    private function buildReindexer(): FullProductReindexer
    {
        return new FullProductReindexer(
            $this->storeScopeProvider,
            $this->configurationReader,
            $this->runContextFactory,
            $this->idBatchProvider,
            $this->snapshotProvider,
            $this->documentNormalizer,
            $this->writer
        );
    }

    /**
     * Configures one enabled store with the given batch size.
     */
    private function stubEnabledStore(int $batchSize = 100): void
    {
        $this->storeScopeProvider->method('activeStores')->willReturn([$this->scope]);

        $general = $this->createMock(GeneralConfigInterface::class);
        $general->method('isEnabled')->willReturn(true);
        $this->configurationReader->method('readGeneral')->willReturn($general);

        $config = $this->createMock(IndexingConfigInterface::class);
        $config->method('batchSize')->willReturn($batchSize);
        $this->configurationReader->method('readIndexing')->willReturn($config);
    }

    private function stubDisabledStore(): void
    {
        $this->storeScopeProvider->method('activeStores')->willReturn([$this->scope]);

        $general = $this->createMock(GeneralConfigInterface::class);
        $general->method('isEnabled')->willReturn(false);
        $this->configurationReader->method('readGeneral')->willReturn($general);
    }

    /**
     * @param list<list<int>> $batches
     */
    private function stubBatches(array $batches): void
    {
        $this->idBatchProvider->method('batches')->willReturnCallback(
            static function () use ($batches): \Generator {
                foreach ($batches as $batch) {
                    yield $batch;
                }
            }
        );
    }

    /**
     * @param list<int> $missingIds
     */
    private function stubSnapshots(int $snapshotCount, array $missingIds = []): void
    {
        $this->snapshots = [];
        for ($i = 0; $i < $snapshotCount; $i++) {
            $this->snapshots[] = $this->createMock(ProductSnapshotInterface::class);
        }

        $snapshotBatch = $this->createMock(ProductSnapshotBatchInterface::class);
        $snapshotBatch->method('snapshots')->willReturn($this->snapshots);
        $snapshotBatch->method('missingProductIds')->willReturn($missingIds);
        $this->snapshotProvider->method('load')->willReturn($snapshotBatch);
    }

    /**
     * The first $eligibleCount snapshots normalize to eligible documents; the
     * rest normalize to ineligible with the given reason code.
     */
    private function stubNormalizer(int $eligibleCount, string $ineligibleReason = 'disabled'): void
    {
        $this->documentNormalizer->method('normalize')->willReturnCallback(
            function (ProductSnapshotInterface $snapshot) use ($eligibleCount, $ineligibleReason) {
                $index = array_search($snapshot, $this->snapshots, true);
                $isEligible = $index !== false && $index < $eligibleCount;

                $result = $this->createMock(ProductNormalizationResultInterface::class);
                $result->method('eligible')->willReturn($isEligible);
                if ($isEligible) {
                    $result->method('document')->willReturn($this->createMock(ProductDocumentInterface::class));
                } else {
                    $result->method('document')->willReturn(null);
                    $result->method('reasonCode')->willReturn($ineligibleReason);
                }

                return $result;
            }
        );
    }

    public function testNoEnabledStoresReturnsNoOpWithoutTouchingWriter(): void
    {
        $this->stubDisabledStore();

        $result = $this->buildReindexer()->rebuild();

        self::assertInstanceOf(RebuildResultInterface::class, $result);
        self::assertTrue($result->noOp());
        self::assertFalse($result->activated());
        self::assertSame(1, $result->metrics()->storesConsidered());
        self::assertSame(1, $result->metrics()->storesSkipped());
        self::assertSame(0, $result->metrics()->storesPrepared());
        self::assertFalse($this->writer->begun);
        self::assertFalse($this->writer->activated);
        self::assertSame(0, $this->writer->abortCount);
    }

    public function testSingleBatchAllEligibleActivatesRun(): void
    {
        $this->stubEnabledStore();
        $this->stubBatches([[10, 11]]);
        $this->stubSnapshots(2);
        $this->stubNormalizer(2);

        $context = new RebuildRunContext(self::RUN_ID, 1, [$this->scope], 1.0);
        $this->runContextFactory->method('create')->willReturn($context);

        $result = $this->buildReindexer()->rebuild();

        self::assertTrue($result->activated());
        self::assertSame(2, $result->metrics()->productIdsExamined());
        self::assertSame(2, $result->metrics()->snapshotsLoaded());
        self::assertSame(2, $result->metrics()->eligibleDocuments());
        self::assertSame(1, $result->metrics()->batchesWritten());
        self::assertSame(0, $result->metrics()->missingProducts());
        self::assertTrue($this->writer->begun);
        self::assertTrue($this->writer->activated);
        self::assertSame(0, $this->writer->abortCount);
        self::assertSame(1, count($this->writer->preparedStores));
        self::assertSame(1, count($this->writer->writtenBatches));
        self::assertSame(2, count($this->writer->writtenBatches[0]['documents']));
        self::assertSame($context, $this->writer->lastContext);
    }

    public function testBatchSizeIsPassedThrough(): void
    {
        $this->stubEnabledStore(37);
        $this->stubBatches([[10]]);
        $this->stubSnapshots(1);
        $this->stubNormalizer(1);

        $this->idBatchProvider->expects(self::once())
            ->method('batches')
            ->with($this->scope, 37)
            ->willReturnCallback(static function (): \Generator {
                yield [10];
            });

        $result = $this->buildReindexer()->rebuild();

        self::assertTrue($result->activated());
    }

    public function testIneligibleAndMissingAreCountedByReason(): void
    {
        $this->stubEnabledStore();
        $this->stubBatches([[10, 11, 12]]);
        $this->stubSnapshots(2, [999]);
        $this->stubNormalizer(1, 'not_search_visible');

        $result = $this->buildReindexer()->rebuild();

        self::assertTrue($result->activated());
        self::assertSame(3, $result->metrics()->productIdsExamined());
        self::assertSame(2, $result->metrics()->snapshotsLoaded());
        self::assertSame(1, $result->metrics()->eligibleDocuments());
        self::assertSame(['not_search_visible' => 1], $result->metrics()->ineligibleByReason());
        self::assertSame(1, $result->metrics()->missingProducts());
        self::assertSame(1, $result->metrics()->batchesWritten());
    }

    public function testEmptySnapshotBatchWritesNothingButStillActivates(): void
    {
        $this->stubEnabledStore();
        $this->stubBatches([[10]]);
        $this->stubSnapshots(0);
        $this->stubNormalizer(0);

        $result = $this->buildReindexer()->rebuild();

        self::assertTrue($result->activated());
        self::assertSame(0, $result->metrics()->batchesWritten());
        self::assertCount(0, $this->writer->writtenBatches);
    }

    public function testSnapshotLoadFailureAbortsRunOnce(): void
    {
        $this->stubEnabledStore();
        $this->stubBatches([[10]]);
        $this->snapshotProvider->method('load')->willThrowException(
            new CatalogException(__('snapshot boom'))
        );

        try {
            $this->buildReindexer()->rebuild();
            self::fail('Expected ProductIndexingException');
        } catch (ProductIndexBatchNormalizationException $exception) {
            self::assertSame('batch_normalization_failed', $exception->errorCode());
            self::assertNotNull($exception->rebuildResult());
            self::assertTrue($exception->rebuildResult()->aborted());
        }

        self::assertTrue($this->writer->begun);
        self::assertFalse($this->writer->activated);
        self::assertSame(1, $this->writer->abortCount);
    }

    public function testBeginRunBackendUnavailableThrowsWithoutAbort(): void
    {
        $this->stubEnabledStore();
        $this->stubBatches([]);
        $this->writer->failOn('beginRun', new ProductIndexBackendUnavailableException());

        try {
            $this->buildReindexer()->rebuild();
            self::fail('Expected ProductIndexingException');
        } catch (ProductIndexBackendUnavailableException $exception) {
            self::assertSame('backend_unavailable', $exception->errorCode());
            self::assertNotNull($exception->rebuildResult());
            self::assertTrue($exception->rebuildResult()->aborted());
        }

        self::assertFalse($this->writer->begun);
        self::assertFalse($this->writer->activated);
        self::assertSame(0, $this->writer->abortCount);
    }

    public function testWriteBatchFailureAbortsRunOnce(): void
    {
        $this->stubEnabledStore();
        $this->stubBatches([[10]]);
        $this->stubSnapshots(1);
        $this->stubNormalizer(1);
        $this->writer->failOn('writeBatch', new \RuntimeException('disk full'));

        try {
            $this->buildReindexer()->rebuild();
            self::fail('Expected ProductIndexingException');
        } catch (ProductIndexBatchWriteException $exception) {
            self::assertSame('batch_write_failed', $exception->errorCode());
            $inner = $exception->getPrevious();
            self::assertNotNull($inner);
            if ($inner->getPrevious() !== null) {
                self::assertSame('disk full', $inner->getPrevious()->getMessage());
            } else {
                self::assertSame('disk full', $inner->getMessage());
            }
        }

        self::assertTrue($this->writer->begun);
        self::assertFalse($this->writer->activated);
        self::assertSame(1, $this->writer->abortCount);
    }

    public function testActivationFailureAbortsRunOnce(): void
    {
        $this->stubEnabledStore();
        $this->stubBatches([]);
        $this->writer->failOn('activateRun', new \RuntimeException('alias swap failed'));

        try {
            $this->buildReindexer()->rebuild();
            self::fail('Expected ProductIndexingException');
        } catch (ProductIndexActivationException $exception) {
            self::assertSame('activation_failed', $exception->errorCode());
            self::assertNotNull($exception->rebuildResult());
        }

        self::assertTrue($this->writer->begun);
        self::assertFalse($this->writer->activated);
        self::assertSame(1, $this->writer->abortCount);
    }

    public function testAbortFailurePreservesPrimaryFailure(): void
    {
        $this->stubEnabledStore();
        $this->stubBatches([[10]]);
        $this->stubSnapshots(1);
        $this->stubNormalizer(1);
        $this->writer->failOn('writeBatch', new \RuntimeException('disk full'));
        $this->writer->failOn('abortRun', new ProductIndexAbortFailedException());

        try {
            $this->buildReindexer()->rebuild();
            self::fail('Expected ProductIndexAbortFailedException');
        } catch (ProductIndexAbortFailedException $exception) {
            self::assertSame('index_abort_failed', $exception->errorCode());
            self::assertNotNull($exception->rebuildResult());
            self::assertTrue($exception->rebuildResult()->aborted());
            self::assertInstanceOf(ProductIndexBatchWriteException::class, $exception->getPrevious());
        }
    }

    public function testRunContextFactoryFailureIsRunInitFailure(): void
    {
        $this->stubEnabledStore();
        $this->stubBatches([]);
        $this->runContextFactory->method('create')->willThrowException(
            new ProductIndexRunInitException()
        );

        try {
            $this->buildReindexer()->rebuild();
            self::fail('Expected ProductIndexingException');
        } catch (ProductIndexRunInitException $exception) {
            self::assertSame('run_init_failed', $exception->errorCode());
        }

        self::assertFalse($this->writer->begun);
        self::assertFalse($this->writer->activated);
        self::assertSame(0, $this->writer->abortCount);
    }

    public function testConfigReadFailureIsRunInitFailureWithoutResult(): void
    {
        $this->storeScopeProvider->method('activeStores')->willReturn([$this->scope]);
        $this->configurationReader->method('readGeneral')->willThrowException(
            new \RuntimeException('config boom')
        );

        try {
            $this->buildReindexer()->rebuild();
            self::fail('Expected ProductIndexingException');
        } catch (ProductIndexRunInitException $exception) {
            self::assertSame('run_init_failed', $exception->errorCode());
            self::assertNull($exception->rebuildResult());
        }

        self::assertFalse($this->writer->begun);
        self::assertSame(0, $this->writer->abortCount);
    }

    public function testOpenSearchBackendFailureMapsToStableCode(): void
    {
        $this->stubEnabledStore();
        $this->stubBatches([]);
        $this->writer->failOn(
            'beginRun',
            new OpenSearchBackendUnavailableException()
        );

        try {
            $this->buildReindexer()->rebuild();
            self::fail('Expected ProductIndexingException');
        } catch (OpenSearchBackendUnavailableException $exception) {
            self::assertSame('opensearch_backend_unavailable', $exception->errorCode());
            self::assertNotNull($exception->rebuildResult());
            self::assertTrue($exception->rebuildResult()->aborted());
        }

        self::assertFalse($this->writer->begun);
        self::assertSame(0, $this->writer->abortCount);
    }

    public function testEmbeddingEnrichmentFailureMapsToStableCode(): void
    {
        $this->stubEnabledStore();
        $this->stubBatches([[10]]);
        $this->stubSnapshots(1);
        $this->stubNormalizer(1);
        $this->writer->failOn(
            'writeBatch',
            new EmbeddingEnrichmentException()
        );

        try {
            $this->buildReindexer()->rebuild();
            self::fail('Expected ProductIndexingException');
        } catch (ProductIndexBatchWriteException $exception) {
            self::assertSame('batch_write_failed', $exception->errorCode());
            self::assertNotNull($exception->rebuildResult());
            self::assertChainContains($exception, EmbeddingEnrichmentException::class);
        }

        self::assertTrue($this->writer->begun);
        self::assertFalse($this->writer->activated);
        self::assertSame(1, $this->writer->abortCount);
    }

    /**
     * Asserts that the exception chain contains an instance of the given class.
     *
     * @param class-string $class
     */
    private static function assertChainContains(\Throwable $throwable, string $class): void
    {
        $current = $throwable;
        while ($current !== null) {
            if ($current instanceof $class) {
                self::assertTrue(true);

                return;
            }
            $current = $current->getPrevious();
        }

        self::fail(sprintf('Exception chain does not contain %s', $class));
    }

    public function testConsecutiveRebuildsProduceDistinctRunIds(): void
    {
        $this->stubEnabledStore();
        $this->stubBatches([]);

        $this->runContextFactory->method('create')->willReturnCallback(
            function () {
                static $counter = 0;
                $counter++;
                return new RebuildRunContext(
                    sprintf('9f6f0c80-5d3b-4b2a-8e7c-0000000000%02d', $counter),
                    1,
                    [$this->scope],
                    1.0
                );
            }
        );

        $reindexer = $this->buildReindexer();

        $first = $reindexer->rebuild();
        $firstId = $this->writer->lastContext->runId();
        $second = $reindexer->rebuild();
        $secondId = $this->writer->lastContext->runId();

        self::assertTrue($first->activated());
        self::assertTrue($second->activated());
        self::assertNotSame($firstId, $secondId);
    }
}
