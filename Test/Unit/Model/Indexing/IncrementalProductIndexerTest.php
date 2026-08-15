<?php

declare(strict_types=1);

namespace Aavirbhava\AiShoppingAssistant\Test\Unit\Model\Indexing;

use Aavirbhava\AiShoppingAssistant\Api\Catalog\ProductDocumentNormalizerInterface;
use Aavirbhava\AiShoppingAssistant\Api\Catalog\ProductEligibilityResultInterface;
use Aavirbhava\AiShoppingAssistant\Api\Catalog\ProductNormalizationResultInterface;
use Aavirbhava\AiShoppingAssistant\Api\Catalog\ProductSnapshotBatchInterface;
use Aavirbhava\AiShoppingAssistant\Api\Catalog\ProductSnapshotInterface;
use Aavirbhava\AiShoppingAssistant\Api\Catalog\ProductSnapshotProviderInterface;
use Aavirbhava\AiShoppingAssistant\Api\Config\ConfigurationReaderInterface;
use Aavirbhava\AiShoppingAssistant\Api\Config\GeneralConfigInterface;
use Aavirbhava\AiShoppingAssistant\Api\Config\IndexingConfigInterface;
use Aavirbhava\AiShoppingAssistant\Api\Indexing\EmbeddingConfigSnapshotServiceInterface;
use Aavirbhava\AiShoppingAssistant\Api\Indexing\ProductIndexMappingInterface;
use Aavirbhava\AiShoppingAssistant\Api\Store\StoreScopeInterface;
use Aavirbhava\AiShoppingAssistant\Api\Store\StoreScopeProviderInterface;
use Aavirbhava\AiShoppingAssistant\Model\Catalog\CategoryReference;
use Aavirbhava\AiShoppingAssistant\Model\Catalog\ContentHashService;
use Aavirbhava\AiShoppingAssistant\Model\Catalog\ProductDocument;
use Aavirbhava\AiShoppingAssistant\Model\Catalog\ProductDocumentSchema;
use Aavirbhava\AiShoppingAssistant\Model\Catalog\ProductNormalizationResult;
use Aavirbhava\AiShoppingAssistant\Model\Catalog\ProductSnapshotBatch;
use Aavirbhava\AiShoppingAssistant\Model\Catalog\SearchableAttribute;
use Aavirbhava\AiShoppingAssistant\Model\Config\Exception\ConfigurationException;
use Aavirbhava\AiShoppingAssistant\Model\Indexing\Document\IndexedDocumentPayloadBuilder;
use Aavirbhava\AiShoppingAssistant\Model\Indexing\Embedding\EmbeddingEnrichmentService;
use Aavirbhava\AiShoppingAssistant\Model\Indexing\Embedding\FrozenEmbeddingConfig;
use Aavirbhava\AiShoppingAssistant\Model\Indexing\Exception\EmbeddingEnrichmentException;
use Aavirbhava\AiShoppingAssistant\Model\Indexing\Exception\IncrementalIndexTargetInvalidException;
use Aavirbhava\AiShoppingAssistant\Model\Indexing\Exception\IndexCompatibilityMismatchException;
use Aavirbhava\AiShoppingAssistant\Model\Indexing\Exception\IndexScopeMismatchException;
use Aavirbhava\AiShoppingAssistant\Model\Indexing\Exception\InvalidProductIndexEntityIdsException;
use Aavirbhava\AiShoppingAssistant\Model\Indexing\Exception\OpenSearchBackendUnavailableException;
use Aavirbhava\AiShoppingAssistant\Model\Indexing\Exception\ProductIndexBatchNormalizationException;
use Aavirbhava\AiShoppingAssistant\Model\Indexing\IncrementalProductIndexer;
use Aavirbhava\AiShoppingAssistant\Model\Indexing\Naming\IndexNamingService;
use Aavirbhava\AiShoppingAssistant\Model\Indexing\RebuildRunContext;
use Aavirbhava\AiShoppingAssistant\Model\Store\StoreScope;
use Aavirbhava\AiShoppingAssistant\Test\Unit\Fake\FakeAssistantSearchClient;
use Aavirbhava\AiShoppingAssistant\Test\Unit\Fake\FakeEmbeddingGenerationService;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(IncrementalProductIndexer::class)]
final class IncrementalProductIndexerTest extends TestCase
{
    private const PREFIX = 'aavirbhava_ai';
    private const RUN_ID = '9f6f0c80-5d3b-4b2a-8e7c-1a2b3c4d5e6f';
    private const FINGERPRINT = 'bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb';
    private const BASE_URL_HASH = 'cccccccccccccccccccccccccccccccccccccccccccccccccccccccccccccccc';

    private FakeAssistantSearchClient $client;
    private FakeEmbeddingGenerationService $embeddingGeneration;

    /**
     * @var array<int, StoreScope>
     */
    private array $scopes = [];

    /**
     * @var array<int, bool>
     */
    private array $enabled = [];

    /**
     * @var array<int, string>
     */
    private array $scenario = [];

    /**
     * @var array<int, list<int>>
     */
    private array $snapshotIds = [];

    /**
     * @var array<int, list<int>>
     */
    private array $missingIds = [];

    /**
     * @var array<int, ProductDocument>
     */
    private array $documents = [];

    private bool $readIndexingThrows = false;

    private bool $configMatches = true;

    private ?ProductSnapshotBatchInterface $snapshotBatchOverride = null;

    private ?ProductNormalizationResultInterface $normalizationResultOverride = null;

    protected function setUp(): void
    {
        $this->client = new FakeAssistantSearchClient();
        $this->embeddingGeneration = new FakeEmbeddingGenerationService();
        $this->scopes = [
            1 => new StoreScope(1, 1, 'default'),
        ];
        $this->enabled = [1 => true];
        $this->scenario = [1 => 'eligible'];
        $this->snapshotIds = [1 => [42]];
        $this->missingIds = [1 => []];
        $this->documents = [1 => $this->document(1, 42, str_repeat('a', 64), str_repeat('b', 64))];
        $this->prepareTarget($this->scopes[1]);
    }

    public function testRejectsInvalidProductId(): void
    {
        $this->expectException(InvalidProductIndexEntityIdsException::class);
        $this->buildIndexer()->process(0);
    }

    public function testDisabledStoreDoesNotTouchIndexOrEmbedding(): void
    {
        $this->enabled[1] = false;

        $this->buildIndexer()->process(42);

        self::assertSame([], $this->client->writtenDocuments);
        self::assertSame([], $this->client->deletedDocuments);
        self::assertSame([], $this->embeddingGeneration->calls);
    }

    public function testDisabledStoreDoesNotReadIndexingConfig(): void
    {
        $this->enabled[1] = false;
        $this->readIndexingThrows = true;

        $this->buildIndexer()->process(42);

        self::assertSame([], $this->client->writtenDocuments);
        self::assertSame([], $this->client->deletedDocuments);
        self::assertSame([], $this->embeddingGeneration->calls);
    }

    public function testWritesEachEnabledStoreThroughItsPhysicalTarget(): void
    {
        $this->scopes[2] = new StoreScope(2, 2, 'second');
        $this->enabled[2] = true;
        $this->scenario[2] = 'eligible';
        $this->snapshotIds[2] = [42];
        $this->missingIds[2] = [];
        $this->documents[2] = $this->document(2, 42, str_repeat('d', 64), str_repeat('e', 64), [2]);
        $target1 = $this->target($this->scopes[1]);
        $target2 = $this->prepareTarget($this->scopes[2]);

        $this->buildIndexer()->process(42);

        self::assertSame(
            [
                ['index' => $target1, 'id' => '1_42'],
                ['index' => $target2, 'id' => '2_42'],
            ],
            $this->client->writtenDocuments
        );
    }

    public function testMissingProductDeletesStoreDocument(): void
    {
        $this->scenario[1] = 'missing';
        $this->snapshotIds[1] = [];
        $this->missingIds[1] = [42];
        $target = $this->target($this->scopes[1]);

        $this->buildIndexer()->process(42);

        self::assertSame([['index' => $target, 'id' => '1_42']], $this->client->deletedDocuments);
        self::assertSame([], $this->embeddingGeneration->calls);
    }

    public function testIneligibleProductDeletesStoreDocument(): void
    {
        $this->scenario[1] = 'ineligible';
        $target = $this->target($this->scopes[1]);

        $this->buildIndexer()->process(42);

        self::assertSame([['index' => $target, 'id' => '1_42']], $this->client->deletedDocuments);
        self::assertSame([], $this->embeddingGeneration->calls);
    }

    public function testDeleteNotFoundIsIdempotent(): void
    {
        $this->scenario[1] = 'missing';
        $this->snapshotIds[1] = [];
        $this->missingIds[1] = [42];
        $target = $this->target($this->scopes[1]);

        $this->buildIndexer()->process(42);
        $this->buildIndexer()->process(42);

        self::assertCount(2, $this->client->deletedDocuments);
        self::assertArrayNotHasKey('1_42', $this->client->documentSources[$target] ?? []);
    }

    public function testCompleteHashUnchangedIsNoOp(): void
    {
        $target = $this->target($this->scopes[1]);
        $this->client->documentSources[$target]['1_42'] = $this->sourceFor($this->documents[1], [0.1, 0.2, 0.3, 0.4]);

        $this->buildIndexer()->process(42);

        self::assertSame([], $this->client->writtenDocuments);
        self::assertSame([], $this->embeddingGeneration->calls);
    }

    public function testEmbeddingContentUnchangedReusesExistingVectorWithoutEmbedding(): void
    {
        $target = $this->target($this->scopes[1]);
        $this->client->documentSources[$target]['1_42'] = $this->sourceFor(
            $this->document(1, 42, str_repeat('a', 64), str_repeat('0', 64)),
            [0.1, 0.2, 0.3, 0.4]
        );

        $this->buildIndexer()->process(42);

        self::assertSame([], $this->embeddingGeneration->calls);
        self::assertCount(1, $this->client->writtenDocuments);
        self::assertSame([0.1, 0.2, 0.3, 0.4], $this->client->documentSources[$target]['1_42']['embedding']);
    }

    public function testChangedEmbeddingContentGeneratesOneEmbeddingRequest(): void
    {
        $target = $this->target($this->scopes[1]);
        $this->client->documentSources[$target]['1_42'] = $this->sourceFor(
            $this->document(1, 42, str_repeat('9', 64), str_repeat('0', 64)),
            [0.1, 0.2, 0.3, 0.4]
        );

        $this->buildIndexer()->process(42);

        self::assertCount(1, $this->embeddingGeneration->calls);
        self::assertSame(['Product 42 store 1'], $this->embeddingGeneration->calls[0]['texts']);
    }

    public function testAbsentStateGeneratesEmbedding(): void
    {
        $this->buildIndexer()->process(42);

        self::assertCount(1, $this->embeddingGeneration->calls);
        self::assertCount(1, $this->client->writtenDocuments);
    }

    /**
     * @dataProvider invalidVectorProvider
     */
    public function testInvalidStoredVectorIsNeverReused(mixed $vector): void
    {
        $target = $this->target($this->scopes[1]);
        $this->client->documentSources[$target]['1_42'] = $this->sourceFor(
            $this->document(1, 42, str_repeat('a', 64), str_repeat('0', 64)),
            $vector
        );

        $this->buildIndexer()->process(42);

        self::assertCount(1, $this->embeddingGeneration->calls);
        self::assertCount(1, $this->client->writtenDocuments);
    }

    /**
     * @return array<string, array{mixed}>
     */
    public static function invalidVectorProvider(): array
    {
        return [
            'malformed' => ['not-a-vector'],
            'wrong dimension' => [[0.1, 0.2]],
            'non finite' => [[0.1, INF, 0.3, 0.4]],
        ];
    }

    /**
     * @dataProvider invalidBatchProvider
     *
     * @param list<int> $snapshotIds
     * @param list<int> $missingIds
     */
    public function testMalformedCatalogueReconciliationFailsClosed(array $snapshotIds, array $missingIds): void
    {
        $this->snapshotIds[1] = $snapshotIds;
        $this->missingIds[1] = $missingIds;

        $this->expectException(ProductIndexBatchNormalizationException::class);

        try {
            $this->buildIndexer()->process(42);
        } finally {
            self::assertSame([], $this->client->writtenDocuments);
            self::assertSame([], $this->client->deletedDocuments);
            self::assertSame([], $this->embeddingGeneration->calls);
        }
    }

    /**
     * @return array<string, array{list<int>, list<int>}>
     */
    public static function invalidBatchProvider(): array
    {
        return [
            'empty response' => [[], []],
            'snapshot and missing' => [[42], [42]],
            'multiple snapshots' => [[42, 42], []],
            'wrong missing id' => [[], [99]],
            'wrong snapshot id' => [[99], []],
            'duplicate missing id' => [[], [42, 42]],
        ];
    }

    public function testNormalizedEntityMismatchFailsClosed(): void
    {
        $this->documents[1] = $this->document(1, 99, str_repeat('a', 64), str_repeat('b', 64));

        $this->expectException(IndexScopeMismatchException::class);

        try {
            $this->buildIndexer()->process(42);
        } finally {
            self::assertSame([], $this->client->writtenDocuments);
            self::assertSame([], $this->embeddingGeneration->calls);
        }
    }

    public function testNormalizedDocumentIdMismatchFailsClosed(): void
    {
        $this->documents[1] = $this->document(1, 42, str_repeat('a', 64), str_repeat('b', 64), [1], '1_99');

        $this->expectException(IndexScopeMismatchException::class);

        try {
            $this->buildIndexer()->process(42);
        } finally {
            self::assertSame([], $this->client->writtenDocuments);
            self::assertSame([], $this->embeddingGeneration->calls);
        }
    }

    public function testNormalizedSchemaMismatchFailsClosed(): void
    {
        $this->documents[1] = $this->document(
            1,
            42,
            str_repeat('a', 64),
            str_repeat('b', 64),
            [1],
            null,
            ProductDocumentSchema::VERSION + 1
        );

        $this->expectException(IndexCompatibilityMismatchException::class);

        try {
            $this->buildIndexer()->process(42);
        } finally {
            self::assertSame([], $this->client->writtenDocuments);
            self::assertSame([], $this->embeddingGeneration->calls);
        }
    }

    public function testEligibleNormalizationWithNullDocumentFailsClosed(): void
    {
        $result = $this->createMock(ProductNormalizationResultInterface::class);
        $result->expects(self::once())->method('eligible')->willReturn(true);
        $result->expects(self::once())->method('document')->willReturn(null);
        $this->normalizationResultOverride = $result;

        $this->expectException(ProductIndexBatchNormalizationException::class);

        try {
            $this->buildIndexer()->process(42);
        } finally {
            self::assertSame([], $this->client->writtenDocuments);
            self::assertSame([], $this->client->deletedDocuments);
            self::assertSame([], $this->embeddingGeneration->calls);
        }
    }

    public function testIneligibleNormalizationWithDocumentFailsClosed(): void
    {
        $result = $this->createMock(ProductNormalizationResultInterface::class);
        $result->expects(self::once())->method('eligible')->willReturn(false);
        $result->expects(self::once())->method('document')->willReturn($this->documents[1]);
        $this->normalizationResultOverride = $result;

        $this->expectException(ProductIndexBatchNormalizationException::class);

        try {
            $this->buildIndexer()->process(42);
        } finally {
            self::assertSame([], $this->client->writtenDocuments);
            self::assertSame([], $this->client->deletedDocuments);
            self::assertSame([], $this->embeddingGeneration->calls);
        }
    }

    public function testMalformedRuntimeSnapshotTypeFailsClosed(): void
    {
        $batch = $this->createMock(ProductSnapshotBatchInterface::class);
        $batch->method('snapshots')->willReturn([new \stdClass()]);
        $batch->method('missingProductIds')->willReturn([]);
        $this->snapshotBatchOverride = $batch;

        $this->expectException(ProductIndexBatchNormalizationException::class);

        try {
            $this->buildIndexer()->process(42);
        } finally {
            self::assertSame([], $this->client->writtenDocuments);
            self::assertSame([], $this->client->deletedDocuments);
            self::assertSame([], $this->embeddingGeneration->calls);
        }
    }

    public function testFingerprintIncompatibilityFailsClosed(): void
    {
        $index = $this->prepareTarget($this->scopes[1]);
        $this->client->metaByIndex[$index]['embedding_fingerprint'] = str_repeat('f', 64);

        $this->expectException(IncrementalIndexTargetInvalidException::class);
        $this->buildIndexer()->process(42);
    }

    public function testSchemaIncompatibilityFailsClosed(): void
    {
        $index = $this->prepareTarget($this->scopes[1]);
        $this->client->metaByIndex[$index]['schema_version'] = ProductDocumentSchema::VERSION + 1;

        $this->expectException(IncrementalIndexTargetInvalidException::class);
        $this->buildIndexer()->process(42);
    }

    public function testMissingAliasTargetFailsClosed(): void
    {
        $this->client->aliases[$this->alias($this->scopes[1])] = [];

        $this->expectException(IncrementalIndexTargetInvalidException::class);
        $this->buildIndexer()->process(42);
    }

    public function testMultipleAliasTargetsFailClosed(): void
    {
        $alias = $this->alias($this->scopes[1]);
        $this->client->aliases[$alias][] = 'foreign_index';

        $this->expectException(IncrementalIndexTargetInvalidException::class);
        $this->buildIndexer()->process(42);
    }

    public function testForeignAliasTargetFailsClosed(): void
    {
        $this->client->aliases[$this->alias($this->scopes[1])] = ['magento_catalog_product'];

        $this->expectException(IncrementalIndexTargetInvalidException::class);
        $this->buildIndexer()->process(42);
    }

    public function testStoreMetadataMismatchFailsClosed(): void
    {
        $index = $this->prepareTarget($this->scopes[1]);
        $this->client->metaByIndex[$index]['store_id'] = 99;

        $this->expectException(IncrementalIndexTargetInvalidException::class);
        $this->buildIndexer()->process(42);
    }

    public function testWebsiteMetadataMismatchFailsClosed(): void
    {
        $index = $this->prepareTarget($this->scopes[1]);
        $this->client->metaByIndex[$index]['website_id'] = 99;

        $this->expectException(IncrementalIndexTargetInvalidException::class);
        $this->buildIndexer()->process(42);
    }

    public function testDuplicateExecutionIsIdempotent(): void
    {
        $indexer = $this->buildIndexer();

        $indexer->process(42);
        $indexer->process(42);

        self::assertCount(1, $this->embeddingGeneration->calls);
        self::assertCount(1, $this->client->writtenDocuments);
    }

    public function testAliasChangeBeforeWriteMutatesOnlyOriginalTargetAndFails(): void
    {
        $original = $this->target($this->scopes[1]);
        $newTarget = null;
        $this->client->beforeWriteDocument = function () use (&$newTarget): void {
            $newTarget = $this->switchAliasToNewTarget($this->scopes[1]);
        };

        $this->expectException(IncrementalIndexTargetInvalidException::class);

        try {
            $this->buildIndexer()->process(42);
        } finally {
            self::assertSame([['index' => $original, 'id' => '1_42']], $this->client->writtenDocuments);
            self::assertNotNull($newTarget);
            self::assertArrayNotHasKey('1_42', $this->client->documentSources[$newTarget] ?? []);
        }
    }

    public function testAliasChangeAfterWriteFails(): void
    {
        $original = $this->target($this->scopes[1]);
        $newTarget = null;
        $this->client->afterWriteDocument = function () use (&$newTarget): void {
            $newTarget = $this->switchAliasToNewTarget($this->scopes[1]);
        };

        $this->expectException(IncrementalIndexTargetInvalidException::class);

        try {
            $this->buildIndexer()->process(42);
        } finally {
            self::assertSame([['index' => $original, 'id' => '1_42']], $this->client->writtenDocuments);
            self::assertNotNull($newTarget);
            self::assertArrayNotHasKey('1_42', $this->client->documentSources[$newTarget] ?? []);
        }
    }

    public function testAliasChangeBeforeDeleteMutatesOnlyOriginalTargetAndFails(): void
    {
        $this->scenario[1] = 'missing';
        $this->snapshotIds[1] = [];
        $this->missingIds[1] = [42];
        $original = $this->target($this->scopes[1]);
        $newTarget = null;
        $this->client->beforeDeleteDocument = function () use (&$newTarget): void {
            $newTarget = $this->switchAliasToNewTarget($this->scopes[1]);
        };

        $this->expectException(IncrementalIndexTargetInvalidException::class);

        try {
            $this->buildIndexer()->process(42);
        } finally {
            self::assertSame([['index' => $original, 'id' => '1_42']], $this->client->deletedDocuments);
            self::assertNotNull($newTarget);
        }
    }

    public function testAliasChangeAfterDeleteFails(): void
    {
        $this->scenario[1] = 'missing';
        $this->snapshotIds[1] = [];
        $this->missingIds[1] = [42];
        $original = $this->target($this->scopes[1]);
        $newTarget = null;
        $this->client->afterDeleteDocument = function () use (&$newTarget): void {
            $newTarget = $this->switchAliasToNewTarget($this->scopes[1]);
        };

        $this->expectException(IncrementalIndexTargetInvalidException::class);

        try {
            $this->buildIndexer()->process(42);
        } finally {
            self::assertSame([['index' => $original, 'id' => '1_42']], $this->client->deletedDocuments);
            self::assertNotNull($newTarget);
        }
    }

    public function testAliasChangeDuringFreshEmbeddingPreventsWrite(): void
    {
        $newTarget = null;
        $this->embeddingGeneration->beforeEmbed = function () use (&$newTarget): void {
            $newTarget = $this->switchAliasToNewTarget($this->scopes[1]);
        };

        $this->expectException(IncrementalIndexTargetInvalidException::class);

        try {
            $this->buildIndexer()->process(42);
        } finally {
            self::assertNotNull($newTarget);
            self::assertSame([], $this->client->writtenDocuments);
            self::assertArrayNotHasKey('1_42', $this->client->documentSources[$newTarget] ?? []);
        }
    }

    public function testAliasChangeDuringVectorReusePreventsWrite(): void
    {
        $target = $this->target($this->scopes[1]);
        $this->client->documentSources[$target]['1_42'] = $this->sourceFor(
            $this->document(1, 42, str_repeat('a', 64), str_repeat('0', 64)),
            [0.1, 0.2, 0.3, 0.4]
        );
        $newTarget = null;
        $this->client->afterDocumentState = function () use (&$newTarget): void {
            $newTarget = $this->switchAliasToNewTarget($this->scopes[1]);
        };

        $this->expectException(IncrementalIndexTargetInvalidException::class);

        try {
            $this->buildIndexer()->process(42);
        } finally {
            self::assertNotNull($newTarget);
            self::assertSame([], $this->client->writtenDocuments);
            self::assertSame([], $this->embeddingGeneration->calls);
        }
    }

    public function testAliasChangeDuringNoOpPreventsSuccess(): void
    {
        $target = $this->target($this->scopes[1]);
        $this->client->documentSources[$target]['1_42'] = $this->sourceFor($this->documents[1], [0.1, 0.2, 0.3, 0.4]);
        $this->client->afterDocumentState = function (): void {
            $this->switchAliasToNewTarget($this->scopes[1]);
        };

        $this->expectException(IncrementalIndexTargetInvalidException::class);

        try {
            $this->buildIndexer()->process(42);
        } finally {
            self::assertSame([], $this->client->writtenDocuments);
            self::assertSame([], $this->embeddingGeneration->calls);
        }
    }

    public function testConfigChangeDuringNoOpPreventsSuccess(): void
    {
        $target = $this->target($this->scopes[1]);
        $this->client->documentSources[$target]['1_42'] = $this->sourceFor($this->documents[1], [0.1, 0.2, 0.3, 0.4]);
        $this->client->afterDocumentState = function (): void {
            $this->configMatches = false;
        };

        $this->expectException(IncrementalIndexTargetInvalidException::class);

        try {
            $this->buildIndexer()->process(42);
        } finally {
            self::assertSame([], $this->client->writtenDocuments);
            self::assertSame([], $this->embeddingGeneration->calls);
        }
    }

    public function testConfigChangeBeforeVectorReuseWritePreventsMutation(): void
    {
        $target = $this->target($this->scopes[1]);
        $this->client->documentSources[$target]['1_42'] = $this->sourceFor(
            $this->document(1, 42, str_repeat('a', 64), str_repeat('0', 64)),
            [0.1, 0.2, 0.3, 0.4]
        );
        $this->client->afterDocumentState = function (): void {
            $this->configMatches = false;
        };

        $this->expectException(IncrementalIndexTargetInvalidException::class);

        try {
            $this->buildIndexer()->process(42);
        } finally {
            self::assertSame([], $this->client->writtenDocuments);
            self::assertSame([], $this->embeddingGeneration->calls);
        }
    }

    public function testProviderFailureIsSanitized(): void
    {
        $this->embeddingGeneration->failOn(1, new \RuntimeException('secret provider payload'));

        try {
            $this->buildIndexer()->process(42);
            self::fail('Expected embedding failure');
        } catch (EmbeddingEnrichmentException $exception) {
            self::assertStringNotContainsString('secret provider payload', $exception->getMessage());
            self::assertNull($exception->getPrevious());
        }
    }

    public function testOpenSearchFailurePropagatesSanitizedException(): void
    {
        $this->client->failOn('writeDocument', new OpenSearchBackendUnavailableException());

        try {
            $this->buildIndexer()->process(42);
            self::fail('Expected backend failure');
        } catch (OpenSearchBackendUnavailableException $exception) {
            self::assertSame('opensearch_backend_unavailable', $exception->errorCode());
            self::assertStringNotContainsString('secret', $exception->getMessage());
        }
    }

    public function testConstructionDoesNotCallEmbeddingOrOpenSearch(): void
    {
        $this->buildIndexer();

        self::assertSame([], $this->embeddingGeneration->calls);
        self::assertSame([], $this->client->writtenDocuments);
        self::assertSame([], $this->client->deletedDocuments);
    }

    private function buildIndexer(): IncrementalProductIndexer
    {
        $scopeProvider = $this->createMock(StoreScopeProviderInterface::class);
        $scopeProvider->method('activeStores')->willReturn(array_values($this->scopes));

        $configurationReader = $this->createMock(ConfigurationReaderInterface::class);
        $configurationReader->method('readGeneral')->willReturnCallback(
            function (int $storeId): GeneralConfigInterface {
                $general = $this->createMock(GeneralConfigInterface::class);
                $general->method('isEnabled')->willReturn($this->enabled[$storeId] ?? false);

                return $general;
            }
        );
        $configurationReader->method('readIndexing')->willReturnCallback(
            function (): IndexingConfigInterface {
                if ($this->readIndexingThrows) {
                    throw new ConfigurationException();
                }

                $indexing = $this->createMock(IndexingConfigInterface::class);
                $indexing->method('indexPrefix')->willReturn(self::PREFIX);

                return $indexing;
            }
        );

        $snapshotProvider = $this->createMock(ProductSnapshotProviderInterface::class);
        $snapshotProvider->method('load')->willReturnCallback(
            function (StoreScopeInterface $scope, IndexingConfigInterface $config, array $ids): ProductSnapshotBatchInterface {
                if ($this->snapshotBatchOverride !== null) {
                    return $this->snapshotBatchOverride;
                }

                $storeId = $scope->storeId();
                if (($this->scenario[$storeId] ?? 'eligible') === 'missing'
                    && !isset($this->snapshotIds[$storeId], $this->missingIds[$storeId])
                ) {
                    return new ProductSnapshotBatch([], [$ids[0]]);
                }

                $snapshots = [];
                foreach ($this->snapshotIds[$storeId] ?? [$ids[0]] as $snapshotId) {
                    $snapshot = $this->createMock(ProductSnapshotInterface::class);
                    $snapshot->method('entityId')->willReturn($snapshotId);
                    $snapshots[] = $snapshot;
                }

                return new ProductSnapshotBatch($snapshots, $this->missingIds[$storeId] ?? []);
            }
        );

        $normalizer = $this->createMock(ProductDocumentNormalizerInterface::class);
        $normalizer->method('normalize')->willReturnCallback(
            function (ProductSnapshotInterface $snapshot, $context): ProductNormalizationResultInterface {
                if ($this->normalizationResultOverride !== null) {
                    return $this->normalizationResultOverride;
                }

                $storeId = $context->storeId();
                if (($this->scenario[$storeId] ?? 'eligible') === 'ineligible') {
                    return new ProductNormalizationResult(
                        false,
                        ProductEligibilityResultInterface::REASON_DISABLED,
                        null
                    );
                }

                return new ProductNormalizationResult(
                    true,
                    ProductEligibilityResultInterface::REASON_ELIGIBLE,
                    $this->documents[$storeId]
                );
            }
        );

        $configSnapshot = $this->createMock(EmbeddingConfigSnapshotServiceInterface::class);
        $configSnapshot->method('capture')->willReturnCallback(
            fn (int $storeId): FrozenEmbeddingConfig => $this->frozen($storeId)
        );
        $configSnapshot->method('matches')->willReturnCallback(fn (): bool => $this->configMatches);

        $contentHash = new ContentHashService();

        return new IncrementalProductIndexer(
            $scopeProvider,
            $configurationReader,
            $snapshotProvider,
            $normalizer,
            new IndexNamingService(),
            $this->client,
            $configSnapshot,
            new EmbeddingEnrichmentService($this->embeddingGeneration, $contentHash, $configSnapshot),
            new IndexedDocumentPayloadBuilder(),
            $contentHash
        );
    }

    private function prepareTarget(StoreScope $scope, string $runId = self::RUN_ID): string
    {
        $naming = new IndexNamingService();
        $context = new RebuildRunContext($runId, ProductDocumentSchema::VERSION, [$scope], 1.0);
        $index = $naming->physicalIndex(self::PREFIX, $scope, $context);
        $alias = $naming->readAlias(self::PREFIX, $scope);

        $this->client->indexes[$index] = true;
        $this->client->aliases[$alias] = [$index];
        $this->client->metaByIndex[$index] = [
            'assistant_index' => true,
            'schema_version' => ProductDocumentSchema::VERSION,
            'mapping_version' => ProductIndexMappingInterface::MAPPING_VERSION,
            'store_id' => $scope->storeId(),
            'website_id' => $scope->websiteId(),
            'run_id' => $runId,
            'physical_index' => $index,
            'embedding_fingerprint' => self::FINGERPRINT,
            'embedding_dimensions' => FakeEmbeddingGenerationService::DIMENSION,
            'embedding_base_url_hash' => self::BASE_URL_HASH,
        ];
        $this->client->documentSources[$index] = $this->client->documentSources[$index] ?? [];

        return $index;
    }

    private function switchAliasToNewTarget(StoreScope $scope): string
    {
        return $this->prepareTarget($scope, '11111111-2222-4333-8444-555555555555');
    }

    private function target(StoreScope $scope): string
    {
        return $this->client->aliases[$this->alias($scope)][0];
    }

    private function alias(StoreScope $scope): string
    {
        return (new IndexNamingService())->readAlias(self::PREFIX, $scope);
    }

    private function frozen(int $storeId): FrozenEmbeddingConfig
    {
        return new FrozenEmbeddingConfig(
            $storeId,
            'openai',
            'text-embedding-3-small',
            'https://api.example.com',
            FakeEmbeddingGenerationService::DIMENSION,
            self::FINGERPRINT,
            self::BASE_URL_HASH
        );
    }

    /**
     * @param list<int> $websiteIds
     */
    private function document(
        int $storeId,
        int $entityId,
        string $embeddingHash,
        string $completeHash,
        array $websiteIds = [1],
        ?string $documentId = null,
        int $schemaVersion = ProductDocumentSchema::VERSION
    ): ProductDocument {
        return new ProductDocument(
            $schemaVersion,
            $documentId ?? $storeId . '_' . $entityId,
            $entityId,
            'SKU-' . $entityId,
            $storeId,
            $websiteIds,
            'simple',
            'Product ' . $entityId,
            '',
            '',
            true,
            4,
            [new CategoryReference(7, 'Gear', 'Gear')],
            [new SearchableAttribute('color', 'Color', ['blue'])],
            'Product ' . $entityId . ' store ' . $storeId,
            $embeddingHash,
            $completeHash,
            '2026-01-01T00:00:00+00:00'
        );
    }

    /**
     * @param mixed $vector
     *
     * @return array<string, mixed>
     */
    private function sourceFor(ProductDocument $document, mixed $vector): array
    {
        return [
            ProductIndexMappingInterface::FIELD_DOCUMENT_ID => $document->documentId(),
            ProductIndexMappingInterface::FIELD_EMBEDDING_CONTENT_HASH => $document->embeddingContentHash(),
            ProductIndexMappingInterface::FIELD_COMPLETE_DOCUMENT_HASH => $document->completeDocumentHash(),
            ProductIndexMappingInterface::FIELD_EMBEDDING_FINGERPRINT => self::FINGERPRINT,
            ProductIndexMappingInterface::FIELD_EMBEDDING => $vector,
        ];
    }
}
