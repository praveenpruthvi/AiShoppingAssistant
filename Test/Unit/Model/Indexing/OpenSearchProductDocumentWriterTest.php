<?php

declare(strict_types=1);

namespace Aavirbhava\AiShoppingAssistant\Test\Unit\Model\Indexing;

use Aavirbhava\AiShoppingAssistant\Api\Config\ConfigurationReaderInterface;
use Aavirbhava\AiShoppingAssistant\Api\Config\EmbeddingConfigInterface;
use Aavirbhava\AiShoppingAssistant\Api\Config\IndexingConfigInterface;
use Aavirbhava\AiShoppingAssistant\Api\Indexing\EmbeddingConfigSnapshotServiceInterface;
use Aavirbhava\AiShoppingAssistant\Api\Indexing\FrozenEmbeddingConfigInterface;
use Aavirbhava\AiShoppingAssistant\Api\Indexing\IndexNamingServiceInterface;
use Aavirbhava\AiShoppingAssistant\Api\Indexing\ProductDocumentWriterInterface;
use Aavirbhava\AiShoppingAssistant\Api\Indexing\ProductIndexMappingInterface;
use Aavirbhava\AiShoppingAssistant\Api\Indexing\RebuildRunContextInterface;
use Aavirbhava\AiShoppingAssistant\Api\Store\StoreScopeInterface;
use Aavirbhava\AiShoppingAssistant\Model\Catalog\ContentHashService;
use Aavirbhava\AiShoppingAssistant\Model\Config\Exception\ConfigurationException;
use Aavirbhava\AiShoppingAssistant\Model\Indexing\Document\IndexedDocumentPayloadBuilder;
use Aavirbhava\AiShoppingAssistant\Model\Indexing\Embedding\EmbeddingEnrichmentService;
use Aavirbhava\AiShoppingAssistant\Model\Indexing\Embedding\FrozenEmbeddingConfig;
use Aavirbhava\AiShoppingAssistant\Model\Indexing\Exception\AliasActivationFailedException;
use Aavirbhava\AiShoppingAssistant\Model\Indexing\Exception\IndexCompatibilityMismatchException;
use Aavirbhava\AiShoppingAssistant\Model\Indexing\Exception\IndexRunStateInvalidException;
use Aavirbhava\AiShoppingAssistant\Model\Indexing\Exception\IndexScopeMismatchException;
use Aavirbhava\AiShoppingAssistant\Model\Indexing\Exception\OpenSearchBackendUnavailableException;
use Aavirbhava\AiShoppingAssistant\Model\Indexing\Exception\OpenSearchCapabilityUnsupportedException;
use Aavirbhava\AiShoppingAssistant\Model\Indexing\Exception\ProductIndexAbortFailedException;
use Aavirbhava\AiShoppingAssistant\Model\Indexing\Exception\ProductIndexCreateFailedException;
use Aavirbhava\AiShoppingAssistant\Model\Indexing\Naming\IndexNamingService;
use Aavirbhava\AiShoppingAssistant\Model\Indexing\Mapping\ProductIndexMapping;
use Aavirbhava\AiShoppingAssistant\Model\Indexing\OpenSearchProductDocumentWriter;
use Aavirbhava\AiShoppingAssistant\Model\Indexing\RebuildRunContext;
use Aavirbhava\AiShoppingAssistant\Model\Store\StoreScope;
use Aavirbhava\AiShoppingAssistant\Test\Unit\Fake\FakeAssistantSearchClient;
use Aavirbhava\AiShoppingAssistant\Test\Unit\Fake\FakeEmbeddingGenerationService;
use Aavirbhava\AiShoppingAssistant\Test\Unit\Fake\FakeProductDocumentFactory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(OpenSearchProductDocumentWriter::class)]
final class OpenSearchProductDocumentWriterTest extends TestCase
{
    private const RUN_ID = '9f6f0c80-5d3b-4b2a-8e7c-1a2b3c4d5e6f';
    private const PREFIX = 'aavirbhava_ai';
    private const FINGERPRINT = 'bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb';
    private const BASE_URL_HASH = 'cccccccccccccccccccccccccccccccccccccccccccccccccccccccccccccccc';

    private FakeAssistantSearchClient $client;

    private FakeEmbeddingGenerationService $generation;

    private ConfigurationReaderInterface $configurationReader;

    /**
     * @var EmbeddingConfigSnapshotServiceInterface&\PHPUnit\Framework\MockObject\MockObject
     */
    private $configSnapshot;

    private bool $configMatches = true;

    private StoreScope $scope;

    private RebuildRunContext $context;

    protected function setUp(): void
    {
        $this->client = new FakeAssistantSearchClient();
        $this->generation = new FakeEmbeddingGenerationService();
        $this->configurationReader = $this->createMock(ConfigurationReaderInterface::class);
        $this->scope = new StoreScope(2, 1, 'default');
        $this->context = new RebuildRunContext(self::RUN_ID, 1, [$this->scope], 1.0);

        $indexing = $this->createMock(IndexingConfigInterface::class);
        $indexing->method('indexPrefix')->willReturn(self::PREFIX);
        $this->configurationReader->method('readIndexing')->willReturn($indexing);

        $embedding = $this->createMock(EmbeddingConfigInterface::class);
        $embedding->method('provider')->willReturn('openai');
        $embedding->method('model')->willReturn('text-embedding-3-small');
        $embedding->method('baseUrl')->willReturn('https://api.example.com');
        $embedding->method('dimensions')->willReturn(4);
        $this->configurationReader->method('readEmbedding')->willReturn($embedding);

        $this->configSnapshot = $this->createMock(EmbeddingConfigSnapshotServiceInterface::class);
        $this->configSnapshot->method('capture')->willReturn($this->frozenConfig());
        $this->configSnapshot->method('matches')->willReturnCallback(fn (): bool => $this->configMatches);
    }

    private function frozenConfig(): FrozenEmbeddingConfigInterface
    {
        return new FrozenEmbeddingConfig(
            $this->scope->storeId(),
            'openai',
            'text-embedding-3-small',
            'https://api.example.com',
            4,
            self::FINGERPRINT,
            self::BASE_URL_HASH
        );
    }

    private function buildWriter(): OpenSearchProductDocumentWriter
    {
        return new OpenSearchProductDocumentWriter(
            $this->configurationReader,
            new IndexNamingService(),
            $this->client,
            new ProductIndexMapping(),
            new EmbeddingEnrichmentService($this->generation, new ContentHashService(), $this->configSnapshot),
            new IndexedDocumentPayloadBuilder(),
            $this->configSnapshot
        );
    }

    private function physicalIndexName(): string
    {
        return (new IndexNamingService())->physicalIndex(self::PREFIX, $this->scope, $this->context);
    }

    private function readAliasName(): string
    {
        return (new IndexNamingService())->readAlias(self::PREFIX, $this->scope);
    }

    /**
     * @return array<string, mixed>
     */
    private function metaFor(StoreScopeInterface $scope, RebuildRunContextInterface $context, string $index): array
    {
        return [
            'assistant_index' => true,
            'schema_version' => $context->schemaVersion(),
            'mapping_version' => ProductIndexMappingInterface::MAPPING_VERSION,
            'store_id' => $scope->storeId(),
            'website_id' => $scope->websiteId(),
            'run_id' => $context->runId(),
            'physical_index' => $index,
            'embedding_fingerprint' => self::FINGERPRINT,
            'embedding_dimensions' => 4,
            'embedding_base_url_hash' => self::BASE_URL_HASH,
        ];
    }

    public function testFullLifecycleActivatesAtomically(): void
    {
        $writer = $this->buildWriter();
        $documents = [(new FakeProductDocumentFactory())->make(2, 42, 'SKU-42')];

        $writer->beginRun($this->context);
        $writer->beginStore($this->scope);
        $writer->writeBatch($documents);
        $writer->finishStore();
        $writer->activateRun();

        $index = $this->physicalIndexName();
        self::assertTrue($this->client->indexExists($index));
        self::assertCount(1, $this->client->documentsByIndex[$index]);
        self::assertArrayNotHasKey('_id', $this->client->documentsByIndex[$index][0]);
        self::assertSame('2_42', $this->client->documentsByIndex[$index][0]['document_id']);
        self::assertContains($index, $this->client->refreshed);
        self::assertSame([$index], $this->client->aliasTargets($this->readAliasName()));
        self::assertNotEmpty($this->client->aliasActions);
    }

    public function testCreatesIndexesForAllStoresIncludingEmpty(): void
    {
        $writer = $this->buildWriter();

        $writer->beginRun($this->context);
        $writer->beginStore($this->scope);
        $writer->finishStore();
        $writer->activateRun();

        $index = $this->physicalIndexName();
        self::assertTrue($this->client->indexExists($index));
        self::assertSame([$index], $this->client->aliasTargets($this->readAliasName()));
    }

    public function testWritesAreScopedToCurrentStore(): void
    {
        $writer = $this->buildWriter();
        $otherScope = new StoreScope(3, 1, 'other');
        $context = new RebuildRunContext(self::RUN_ID, 1, [$this->scope, $otherScope], 1.0);

        $writer->beginRun($context);
        $writer->beginStore($this->scope);
        $writer->writeBatch([(new FakeProductDocumentFactory())->make(2, 42, 'SKU-42')]);
        $writer->finishStore();

        $writer->beginStore($otherScope);
        $writer->writeBatch([(new FakeProductDocumentFactory())->make(3, 43, 'SKU-43')]);
        $writer->finishStore();
        $writer->activateRun();

        $indexTwo = (new IndexNamingService())->physicalIndex(self::PREFIX, $this->scope, $context);
        $indexThree = (new IndexNamingService())->physicalIndex(self::PREFIX, $otherScope, $context);

        self::assertCount(1, $this->client->documentsByIndex[$indexTwo]);
        self::assertSame('2_42', $this->client->documentsByIndex[$indexTwo][0]['document_id']);
        self::assertCount(1, $this->client->documentsByIndex[$indexThree]);
        self::assertSame('3_43', $this->client->documentsByIndex[$indexThree][0]['document_id']);
    }

    public function testRejectsDocumentForWrongStore(): void
    {
        $writer = $this->buildWriter();

        $writer->beginRun($this->context);
        $writer->beginStore($this->scope);

        $this->expectException(IndexScopeMismatchException::class);
        $writer->writeBatch([(new FakeProductDocumentFactory())->make(3, 43, 'SKU-43')]);
    }

    public function testRejectsWriteWithoutBeginStore(): void
    {
        $writer = $this->buildWriter();
        $writer->beginRun($this->context);

        $this->expectException(IndexRunStateInvalidException::class);
        $writer->writeBatch([(new FakeProductDocumentFactory())->make(2, 42, 'SKU-42')]);
    }

    public function testWriteRejectsAfterFinishStore(): void
    {
        $writer = $this->buildWriter();

        $writer->beginRun($this->context);
        $writer->beginStore($this->scope);
        $writer->finishStore();

        $this->expectException(IndexRunStateInvalidException::class);
        $writer->beginStore($this->scope);
        $writer->writeBatch([(new FakeProductDocumentFactory())->make(2, 42, 'SKU-42')]);
    }

    public function testBeginStoreRejectsSameStoreDuplicateBegin(): void
    {
        $writer = $this->buildWriter();
        $writer->beginRun($this->context);
        $writer->beginStore($this->scope);

        $this->expectException(IndexRunStateInvalidException::class);
        $writer->beginStore($this->scope);
    }

    public function testBeginStoreRejectsDifferentStoreInterleavingWithoutOverwritingCurrentStore(): void
    {
        $writer = $this->buildWriter();
        $otherScope = new StoreScope(3, 1, 'other');
        $context = new RebuildRunContext(self::RUN_ID, 1, [$this->scope, $otherScope], 1.0);

        $writer->beginRun($context);
        $writer->beginStore($this->scope);

        try {
            $writer->beginStore($otherScope);
            self::fail('beginStore should reject interleaved store processing');
        } catch (IndexRunStateInvalidException $exception) {
            self::assertSame('index_run_state_invalid', $exception->errorCode());
        }

        $writer->writeBatch([(new FakeProductDocumentFactory())->make(2, 42, 'SKU-42')]);
        $writer->finishStore();
        self::assertCount(
            1,
            $this->client->documentsByIndex[(new IndexNamingService())->physicalIndex(self::PREFIX, $this->scope, $context)]
        );
    }

    public function testRejectsBeginStoreForUnknownScope(): void
    {
        $writer = $this->buildWriter();
        $writer->beginRun($this->context);

        $this->expectException(IndexScopeMismatchException::class);
        $writer->beginStore(new StoreScope(99, 1, 'unknown'));
    }

    public function testBeginRunFailsClosedWhenBackendUnavailable(): void
    {
        $this->client->available = false;
        $writer = $this->buildWriter();

        $this->expectException(OpenSearchBackendUnavailableException::class);
        $writer->beginRun($this->context);
    }

    public function testBeginRunFailsClosedWhenVectorSearchUnsupported(): void
    {
        $this->client->vectorSearchSupported = false;
        $writer = $this->buildWriter();

        $this->expectException(OpenSearchCapabilityUnsupportedException::class);
        $writer->beginRun($this->context);
    }

    public function testBeginRunSelfCleansPartialIndexCreation(): void
    {
        $this->client->failOn('createIndex', new \RuntimeException('create failed'));
        $writer = $this->buildWriter();

        try {
            $writer->beginRun($this->context);
            self::fail('beginRun should have thrown');
        } catch (\Throwable $throwable) {
            self::assertInstanceOf(ProductIndexCreateFailedException::class, $throwable);
        }

        self::assertSame([], $this->client->indexes);
        self::assertSame([], $this->client->deleted);
    }

    public function testAbortRunDeletesUnaliasedRunIndexes(): void
    {
        $writer = $this->buildWriter();
        $index = $this->physicalIndexName();

        $writer->beginRun($this->context);
        $writer->beginStore($this->scope);
        $writer->writeBatch([(new FakeProductDocumentFactory())->make(2, 42, 'SKU-42')]);
        $writer->abortRun();

        self::assertFalse($this->client->indexExists($index));
        self::assertContains($index, $this->client->deleted);
    }

    public function testAbortRunNeverDeletesAliasedIndexes(): void
    {
        $writer = $this->buildWriter();
        $index = $this->physicalIndexName();
        $this->client->aliases[$this->readAliasName()] = [$index];

        $writer->beginRun($this->context);
        $writer->abortRun();

        self::assertTrue($this->client->indexExists($index));
        self::assertNotContains($index, $this->client->deleted);
    }

    public function testAbortRunIsIdempotentAndSafe(): void
    {
        $writer = $this->buildWriter();
        $writer->beginRun($this->context);
        $writer->abortRun();
        $writer->abortRun();
        self::assertTrue(true);
    }

    public function testAbortRunWithoutRunIsSafe(): void
    {
        $this->buildWriter()->abortRun();
        self::assertTrue(true);
    }

    public function testActivateRunRefusesWhenStoreNotFinished(): void
    {
        $writer = $this->buildWriter();
        $writer->beginRun($this->context);
        $writer->beginStore($this->scope);

        $this->expectException(IndexRunStateInvalidException::class);
        $writer->activateRun();
    }

    public function testActivateRunRefusesAfterAbort(): void
    {
        $writer = $this->buildWriter();
        $writer->beginRun($this->context);
        $writer->abortRun();

        $this->expectException(IndexRunStateInvalidException::class);
        $writer->activateRun();
    }

    public function testActivateRunRemovesOnlyMetaProvenAssistantTargets(): void
    {
        $writer = $this->buildWriter();
        $index = $this->physicalIndexName();
        $staleAssistant = self::PREFIX . '_store_2_run_staleoldtoken';

        $this->client->aliases[$this->readAliasName()] = [$staleAssistant];
        $this->client->metaByIndex[$staleAssistant] = $this->metaFor($this->scope, $this->context, $staleAssistant);

        $writer->beginRun($this->context);
        $writer->beginStore($this->scope);
        $writer->finishStore();
        $writer->activateRun();

        $targets = $this->client->aliasTargets($this->readAliasName());
        self::assertContains($index, $targets);
        self::assertNotContains($staleAssistant, $targets);
    }

    public function testActivateRunRejectsMixedAliasWithForeignTargetBeforeChangingAlias(): void
    {
        $writer = $this->buildWriter();
        $foreign = 'magento_product_2_default';

        $this->client->aliases[$this->readAliasName()] = [$foreign];

        $writer->beginRun($this->context);
        $writer->beginStore($this->scope);
        $writer->finishStore();

        try {
            $writer->activateRun();
            self::fail('activateRun should reject a mixed alias');
        } catch (AliasActivationFailedException $exception) {
            self::assertSame('alias_activation_failed', $exception->errorCode());
        }

        self::assertSame([$foreign], $this->client->aliasTargets($this->readAliasName()));
        self::assertSame([], $this->client->aliasActions);
    }

    public function testActivateRunRejectsAssistantNamedAliasTargetWithoutProvenMeta(): void
    {
        $writer = $this->buildWriter();
        $staleAssistant = self::PREFIX . '_store_2_run_staleoldtoken';
        $this->client->aliases[$this->readAliasName()] = [$staleAssistant];
        $this->client->metaByIndex[$staleAssistant] = [];

        $writer->beginRun($this->context);
        $writer->beginStore($this->scope);
        $writer->finishStore();

        $this->expectException(AliasActivationFailedException::class);
        $writer->activateRun();
    }

    public function testActivateRunVerifiesNewPhysicalIndexMetaBeforeAliasChange(): void
    {
        $writer = $this->buildWriter();
        $index = $this->physicalIndexName();

        $writer->beginRun($this->context);
        $this->client->metaByIndex[$index]['run_id'] = '00000000-0000-4000-8000-000000000000';
        $writer->beginStore($this->scope);
        $writer->finishStore();

        try {
            $writer->activateRun();
            self::fail('activateRun should reject a new index with mismatched meta');
        } catch (AliasActivationFailedException $exception) {
            self::assertSame('alias_activation_failed', $exception->errorCode());
        }

        self::assertSame([], $this->client->aliasTargets($this->readAliasName()));
        self::assertSame([], $this->client->aliasActions);
        self::assertNotContains($index, $this->client->refreshed);
    }

    public function testActivateRunRejectsNewIndexWithMismatchedEmbeddingDimensions(): void
    {
        $writer = $this->buildWriter();
        $index = $this->physicalIndexName();

        $writer->beginRun($this->context);
        $this->client->metaByIndex[$index]['embedding_dimensions'] = 8;
        $writer->beginStore($this->scope);
        $writer->finishStore();

        $this->expectException(AliasActivationFailedException::class);
        $writer->activateRun();
    }

    public function testActivateRunRejectsNewIndexWithMissingEmbeddingFingerprint(): void
    {
        $writer = $this->buildWriter();
        $index = $this->physicalIndexName();

        $writer->beginRun($this->context);
        unset($this->client->metaByIndex[$index]['embedding_fingerprint']);
        $writer->beginStore($this->scope);
        $writer->finishStore();

        $this->expectException(AliasActivationFailedException::class);
        $writer->activateRun();
    }

    public function testChunksBulkWrites(): void
    {
        $writer = $this->buildWriter();
        $factory = new FakeProductDocumentFactory();
        $documents = [];
        for ($i = 0; $i < 250; $i++) {
            $documents[] = $factory->make(2, $i + 1, 'SKU-' . $i);
        }

        $writer->beginRun($this->context);
        $writer->beginStore($this->scope);
        $writer->writeBatch($documents);
        $writer->finishStore();
        $writer->activateRun();

        $index = $this->physicalIndexName();
        self::assertCount(250, $this->client->documentsByIndex[$index]);
    }

    public function testRejectsSchemaMismatch(): void
    {
        $writer = $this->buildWriter();
        $document = (new FakeProductDocumentFactory())->make(2, 42, 'SKU-42');

        $writer->beginRun($this->context);
        $writer->beginStore($this->scope);

        $wrongSchema = $this->createMock(\Aavirbhava\AiShoppingAssistant\Api\Catalog\ProductDocumentInterface::class);
        $wrongSchema->method('schemaVersion')->willReturn(99);
        $wrongSchema->method('storeId')->willReturn(2);

        $this->expectException(IndexCompatibilityMismatchException::class);
        $writer->writeBatch([$wrongSchema]);
    }

    public function testRejectsEmbeddingDimensionMismatch(): void
    {
        $this->generation->vectorDimension = 8;

        $writer = $this->buildWriter();

        $writer->beginRun($this->context);
        $writer->beginStore($this->scope);

        $this->expectException(IndexCompatibilityMismatchException::class);
        $writer->writeBatch([(new FakeProductDocumentFactory())->make(2, 42, 'SKU-42')]);
    }

    public function testRejectsInvalidIndexingConfig(): void
    {
        $configurationReader = $this->createMock(ConfigurationReaderInterface::class);
        $configurationReader->method('readIndexing')->willThrowException(
            new ConfigurationException(__('invalid'))
        );

        $writer = new OpenSearchProductDocumentWriter(
            $configurationReader,
            new IndexNamingService(),
            $this->client,
            new ProductIndexMapping(),
            new EmbeddingEnrichmentService($this->generation, new ContentHashService(), $this->configSnapshot),
            new IndexedDocumentPayloadBuilder(),
            $this->configSnapshot
        );

        $this->expectException(\Aavirbhava\AiShoppingAssistant\Model\Indexing\Exception\OpenSearchConfigurationInvalidException::class);
        $writer->beginRun($this->context);
    }

    public function testWriterCanBeReusedAfterActivation(): void
    {
        $writer = $this->buildWriter();
        $documents = [(new FakeProductDocumentFactory())->make(2, 42, 'SKU-42')];

        $writer->beginRun($this->context);
        $writer->beginStore($this->scope);
        $writer->writeBatch($documents);
        $writer->finishStore();
        $writer->activateRun();

        $secondContext = new RebuildRunContext(
            '9f6f0c80-5d3b-4b2a-8e7c-1a2b3c4d5e7f',
            1,
            [$this->scope],
            1.0
        );
        $writer->beginRun($secondContext);
        $writer->beginStore($this->scope);
        $writer->finishStore();
        $writer->activateRun();

        $secondIndex = (new IndexNamingService())->physicalIndex(self::PREFIX, $this->scope, $secondContext);
        self::assertTrue($this->client->indexExists($secondIndex));
        self::assertContains($secondIndex, $this->client->refreshed);
    }

    public function testWriterCanBeReusedAfterAbort(): void
    {
        $writer = $this->buildWriter();

        $writer->beginRun($this->context);
        $writer->abortRun();

        $secondContext = new RebuildRunContext(
            '9f6f0c80-5d3b-4b2a-8e7c-1a2b3c4d5e7f',
            1,
            [$this->scope],
            1.0
        );
        $writer->beginRun($secondContext);
        $writer->beginStore($this->scope);
        $writer->finishStore();
        $writer->activateRun();

        $secondIndex = (new IndexNamingService())->physicalIndex(self::PREFIX, $this->scope, $secondContext);
        self::assertTrue($this->client->indexExists($secondIndex));
    }

    public function testWriteAfterActivatedRunIsImpossible(): void
    {
        $writer = $this->buildWriter();
        $writer->beginRun($this->context);
        $writer->beginStore($this->scope);
        $writer->finishStore();
        $writer->activateRun();

        $this->expectException(IndexRunStateInvalidException::class);
        $writer->writeBatch([(new FakeProductDocumentFactory())->make(2, 42, 'SKU-42')]);
    }

    public function testAbortReportsCleanupFailureAndResetsState(): void
    {
        $writer = $this->buildWriter();
        $this->client->failOn('deleteIndex', new \RuntimeException('delete failed'));

        $writer->beginRun($this->context);

        try {
            $writer->abortRun();
            self::fail('abortRun should have reported the failed cleanup');
        } catch (ProductIndexAbortFailedException $exception) {
            self::assertSame('index_abort_failed', $exception->errorCode());
        }

        self::assertTrue($this->client->indexExists($this->physicalIndexName()));

        $secondContext = new RebuildRunContext(
            '9f6f0c80-5d3b-4b2a-8e7c-1a2b3c4d5e7f',
            1,
            [$this->scope],
            1.0
        );
        $writer->beginRun($secondContext);
        self::assertTrue($this->client->indexExists(
            (new IndexNamingService())->physicalIndex(self::PREFIX, $this->scope, $secondContext)
        ));
    }

    public function testAbortNeverDeletesIndexWithoutProvenMeta(): void
    {
        $writer = $this->buildWriter();
        $index = $this->physicalIndexName();

        $writer->beginRun($this->context);
        $this->client->metaByIndex[$index] = [];

        try {
            $writer->abortRun();
            self::fail('abortRun should have reported the unproven index');
        } catch (ProductIndexAbortFailedException $exception) {
            self::assertSame('index_abort_failed', $exception->errorCode());
        }

        self::assertTrue($this->client->indexExists($index));
        self::assertNotContains($index, $this->client->deleted);
    }

    public function testBeginStoreRejectsWebsiteMismatch(): void
    {
        $writer = $this->buildWriter();
        $writer->beginRun($this->context);

        $this->expectException(IndexScopeMismatchException::class);
        $writer->beginStore(new StoreScope(2, 99, 'other'));
    }

    public function testWriteRejectsDocumentNotOnActiveWebsite(): void
    {
        $writer = $this->buildWriter();
        $writer->beginRun($this->context);
        $writer->beginStore($this->scope);

        $document = $this->createMock(\Aavirbhava\AiShoppingAssistant\Api\Catalog\ProductDocumentInterface::class);
        $document->method('schemaVersion')->willReturn(1);
        $document->method('storeId')->willReturn(2);
        $document->method('websiteIds')->willReturn([99]);

        $this->expectException(IndexScopeMismatchException::class);
        $writer->writeBatch([$document]);
    }

    public function testEnrichmentConfigChangeFailsBatch(): void
    {
        $this->configMatches = false;
        $writer = $this->buildWriter();

        $writer->beginRun($this->context);
        $writer->beginStore($this->scope);

        $this->expectException(IndexCompatibilityMismatchException::class);
        $writer->writeBatch([(new FakeProductDocumentFactory())->make(2, 42, 'SKU-42')]);
    }
}
