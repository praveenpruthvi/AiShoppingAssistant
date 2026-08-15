<?php

declare(strict_types=1);

namespace Aavirbhava\AiShoppingAssistant\Test\Unit\Model\Indexing;

use Aavirbhava\AiShoppingAssistant\Api\Config\ConfigurationReaderInterface;
use Aavirbhava\AiShoppingAssistant\Api\Config\EmbeddingConfigInterface;
use Aavirbhava\AiShoppingAssistant\Api\Config\IndexingConfigInterface;
use Aavirbhava\AiShoppingAssistant\Api\Indexing\IndexNamingServiceInterface;
use Aavirbhava\AiShoppingAssistant\Api\Indexing\ProductDocumentWriterInterface;
use Aavirbhava\AiShoppingAssistant\Api\Indexing\ProductIndexMappingInterface;
use Aavirbhava\AiShoppingAssistant\Api\Indexing\RebuildRunContextInterface;
use Aavirbhava\AiShoppingAssistant\Api\Store\StoreScopeInterface;
use Aavirbhava\AiShoppingAssistant\Model\Catalog\ContentHashService;
use Aavirbhava\AiShoppingAssistant\Model\Config\Exception\ConfigurationException;
use Aavirbhava\AiShoppingAssistant\Model\Indexing\Document\IndexedDocumentPayloadBuilder;
use Aavirbhava\AiShoppingAssistant\Model\Indexing\Embedding\EmbeddingEnrichmentService;
use Aavirbhava\AiShoppingAssistant\Model\Indexing\Exception\IndexCompatibilityMismatchException;
use Aavirbhava\AiShoppingAssistant\Model\Indexing\Exception\IndexRunStateInvalidException;
use Aavirbhava\AiShoppingAssistant\Model\Indexing\Exception\IndexScopeMismatchException;
use Aavirbhava\AiShoppingAssistant\Model\Indexing\Exception\OpenSearchBackendUnavailableException;
use Aavirbhava\AiShoppingAssistant\Model\Indexing\Exception\OpenSearchCapabilityUnsupportedException;
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

    private FakeAssistantSearchClient $client;

    private FakeEmbeddingGenerationService $generation;

    private ConfigurationReaderInterface $configurationReader;

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
    }

    private function buildWriter(): OpenSearchProductDocumentWriter
    {
        return new OpenSearchProductDocumentWriter(
            $this->configurationReader,
            new ContentHashService(),
            new IndexNamingService(),
            $this->client,
            new ProductIndexMapping(),
            new EmbeddingEnrichmentService($this->generation, new ContentHashService()),
            new IndexedDocumentPayloadBuilder()
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
        self::assertSame('2_42', $this->client->documentsByIndex[$index][0]['_id']);
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
        self::assertSame('2_42', $this->client->documentsByIndex[$indexTwo][0]['_id']);
        self::assertCount(1, $this->client->documentsByIndex[$indexThree]);
        self::assertSame('3_43', $this->client->documentsByIndex[$indexThree][0]['_id']);
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

    public function testActivateRunRemovesOnlyAssistantOwnedTargets(): void
    {
        $writer = $this->buildWriter();
        $index = $this->physicalIndexName();
        $staleAssistant = self::PREFIX . '_store_2_run_staleoldtoken';
        $foreign = 'magento_product_2_default';

        $this->client->aliases[$this->readAliasName()] = [$staleAssistant, $foreign];

        $writer->beginRun($this->context);
        $writer->beginStore($this->scope);
        $writer->finishStore();
        $writer->activateRun();

        $targets = $this->client->aliasTargets($this->readAliasName());
        self::assertContains($index, $targets);
        self::assertNotContains($staleAssistant, $targets);
        self::assertContains($foreign, $targets);
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
            new ContentHashService(),
            new IndexNamingService(),
            $this->client,
            new ProductIndexMapping(),
            new EmbeddingEnrichmentService($this->generation, new ContentHashService()),
            new IndexedDocumentPayloadBuilder()
        );

        $this->expectException(\Aavirbhava\AiShoppingAssistant\Model\Indexing\Exception\OpenSearchConfigurationInvalidException::class);
        $writer->beginRun($this->context);
    }
}