<?php

declare(strict_types=1);

namespace Aavirbhava\AiShoppingAssistant\Test\Unit\Model\Retrieval;

use Aavirbhava\AiShoppingAssistant\Api\Config\ConfigurationReaderInterface;
use Aavirbhava\AiShoppingAssistant\Api\Config\IndexingConfigInterface;
use Aavirbhava\AiShoppingAssistant\Api\Config\RetrievalConfigInterface;
use Aavirbhava\AiShoppingAssistant\Api\Embedding\EmbeddingGenerationServiceInterface;
use Aavirbhava\AiShoppingAssistant\Api\Embedding\EmbeddingInputTypeInterface;
use Aavirbhava\AiShoppingAssistant\Api\Indexing\AssistantSearchClientInterface;
use Aavirbhava\AiShoppingAssistant\Api\Indexing\IndexNamingServiceInterface;
use Aavirbhava\AiShoppingAssistant\Api\Store\StoreScopeInterface;
use Aavirbhava\AiShoppingAssistant\Api\Store\StoreScopeProviderInterface;
use Aavirbhava\AiShoppingAssistant\Model\Embedding\EmbeddingResult;
use Aavirbhava\AiShoppingAssistant\Model\Embedding\EmbeddingUsage;
use Aavirbhava\AiShoppingAssistant\Model\Embedding\EmbeddingVector;
use Aavirbhava\AiShoppingAssistant\Model\Retrieval\HybridRetrievalService;
use Aavirbhava\AiShoppingAssistant\Model\Retrieval\SearchHitParser;
use Aavirbhava\AiShoppingAssistant\Model\Retrieval\SearchQueryBuilder;
use Aavirbhava\AiShoppingAssistant\Model\Store\Exception\StoreScopeException;
use InvalidArgumentException;
use Magento\Framework\Phrase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(HybridRetrievalService::class)]
final class HybridRetrievalServiceTest extends TestCase
{
    private const STORE_ID = 3;
    private const ALIAS = 'prefix_store_3_current';

    private function source(int $entityId, string $sku): array
    {
        return [
            'entity_id' => $entityId,
            'sku' => $sku,
            'store_id' => (string)self::STORE_ID,
            'name' => 'Product ' . $entityId,
            'short_description' => '',
            'categories' => [],
            'attributes' => [],
            'is_enabled' => true,
            'visibility' => 4,
        ];
    }

    public function testMergesKeywordAndVectorHitsByEntityId(): void
    {
        $searchClient = $this->createMock(AssistantSearchClientInterface::class);
        $searchClient->method('search')->willReturnOnConsecutiveCalls(
            [['_id' => '3_1', '_score' => 2.0, '_source' => $this->source(1, 'SKU-1')]],
            [['_id' => '3_2', '_score' => 0.9, '_source' => $this->source(2, 'SKU-2')]]
        );

        $service = $this->service($searchClient);

        $candidates = $service->retrieve(self::STORE_ID, 'blue shoe');

        self::assertCount(2, $candidates);
        $byId = [];
        foreach ($candidates as $candidate) {
            $byId[$candidate->entityId] = $candidate;
        }
        self::assertSame(2.0, $byId[1]->bm25Score);
        self::assertSame(0.0, $byId[1]->vectorScore);
        self::assertSame(0.0, $byId[2]->bm25Score);
        self::assertSame(0.9, $byId[2]->vectorScore);
    }

    public function testCandidateFoundByBothQueriesCarriesBothScores(): void
    {
        $searchClient = $this->createMock(AssistantSearchClientInterface::class);
        $searchClient->method('search')->willReturnOnConsecutiveCalls(
            [['_id' => '3_1', '_score' => 2.0, '_source' => $this->source(1, 'SKU-1')]],
            [['_id' => '3_1', '_score' => 0.7, '_source' => $this->source(1, 'SKU-1')]]
        );

        $service = $this->service($searchClient);

        $candidates = $service->retrieve(self::STORE_ID, 'blue shoe');

        self::assertCount(1, $candidates);
        self::assertSame(2.0, $candidates[0]->bm25Score);
        self::assertSame(0.7, $candidates[0]->vectorScore);
    }

    public function testCapsResultAtMergedCandidatesConfig(): void
    {
        $keywordHits = [];
        for ($i = 1; $i <= 5; $i++) {
            $keywordHits[] = ['_id' => "3_$i", '_score' => (float)$i, '_source' => $this->source($i, "SKU-$i")];
        }

        $searchClient = $this->createMock(AssistantSearchClientInterface::class);
        $searchClient->method('search')->willReturnOnConsecutiveCalls($keywordHits, []);

        $service = $this->service($searchClient, mergedCandidates: 2);

        $candidates = $service->retrieve(self::STORE_ID, 'blue shoe');

        self::assertCount(2, $candidates);
    }

    public function testEmbedsQueryTextUsingQueryInputTypeNotDocument(): void
    {
        $searchClient = $this->createMock(AssistantSearchClientInterface::class);
        $searchClient->method('search')->willReturn([]);

        $embeddingService = $this->createMock(EmbeddingGenerationServiceInterface::class);
        $capturedType = null;
        $embeddingService->method('embed')
            ->willReturnCallback(
                function (int $storeId, EmbeddingInputTypeInterface $inputType, array $texts) use (&$capturedType) {
                    $capturedType = $inputType;

                    return new EmbeddingResult([new EmbeddingVector([0.1, 0.2], 2)], ['0'], 'test-model', new EmbeddingUsage(0, 0));
                }
            );

        $service = $this->service($searchClient, embeddingService: $embeddingService);
        $service->retrieve(self::STORE_ID, 'blue shoe');

        self::assertNotNull($capturedType);
        self::assertTrue($capturedType->isQuery());
    }

    public function testUsesTheStoreScopedReadAlias(): void
    {
        $searchClient = $this->createMock(AssistantSearchClientInterface::class);
        $capturedIndexes = [];
        $searchClient->method('search')->willReturnCallback(
            function (string $index) use (&$capturedIndexes): array {
                $capturedIndexes[] = $index;
                return [];
            }
        );

        $service = $this->service($searchClient);
        $service->retrieve(self::STORE_ID, 'blue shoe');

        self::assertSame([self::ALIAS, self::ALIAS], $capturedIndexes);
    }

    public function testInactiveStoreFailsClosedBeforeAnySearch(): void
    {
        $storeScope = $this->createMock(StoreScopeProviderInterface::class);
        $storeScope->method('requireActive')->willThrowException(new StoreScopeException(new Phrase('inactive')));

        $searchClient = $this->createMock(AssistantSearchClientInterface::class);
        $searchClient->expects(self::never())->method('search');

        $service = $this->service($searchClient, storeScope: $storeScope);

        $this->expectException(StoreScopeException::class);
        $service->retrieve(self::STORE_ID, 'blue shoe');
    }

    public function testEmptyQueryIsRejectedBeforeAnySearch(): void
    {
        $searchClient = $this->createMock(AssistantSearchClientInterface::class);
        $searchClient->expects(self::never())->method('search');

        $service = $this->service($searchClient);

        $this->expectException(InvalidArgumentException::class);
        $service->retrieve(self::STORE_ID, '   ');
    }

    private function service(
        AssistantSearchClientInterface $searchClient,
        ?StoreScopeProviderInterface $storeScope = null,
        ?EmbeddingGenerationServiceInterface $embeddingService = null,
        int $mergedCandidates = 30
    ): HybridRetrievalService {
        $storeScope ??= $this->activeStoreScope();

        $embeddingService ??= $this->defaultEmbeddingService();

        $retrievalConfig = $this->createMock(RetrievalConfigInterface::class);
        $retrievalConfig->method('keywordCandidates')->willReturn(50);
        $retrievalConfig->method('vectorCandidates')->willReturn(50);
        $retrievalConfig->method('mergedCandidates')->willReturn($mergedCandidates);
        $retrievalConfig->method('finalProducts')->willReturn(8);
        $retrievalConfig->method('isRerankerEnabled')->willReturn(false);

        $indexingConfig = $this->createMock(IndexingConfigInterface::class);
        $indexingConfig->method('indexPrefix')->willReturn('prefix');

        $configReader = $this->createMock(ConfigurationReaderInterface::class);
        $configReader->method('readRetrieval')->with(self::STORE_ID)->willReturn($retrievalConfig);
        $configReader->method('readIndexing')->with(self::STORE_ID)->willReturn($indexingConfig);

        $naming = $this->createMock(IndexNamingServiceInterface::class);
        $naming->method('readAlias')->willReturn(self::ALIAS);

        return new HybridRetrievalService(
            $storeScope,
            $configReader,
            $naming,
            $searchClient,
            $embeddingService,
            new SearchQueryBuilder(),
            new SearchHitParser()
        );
    }

    private function activeStoreScope(): StoreScopeProviderInterface
    {
        $storeScope = $this->createMock(StoreScopeProviderInterface::class);
        $storeScope->method('requireActive')
            ->with(self::STORE_ID)
            ->willReturn($this->createMock(StoreScopeInterface::class));

        return $storeScope;
    }

    private function defaultEmbeddingService(): EmbeddingGenerationServiceInterface
    {
        $embeddingService = $this->createMock(EmbeddingGenerationServiceInterface::class);
        $embeddingService->method('embed')->willReturn(
            new EmbeddingResult([new EmbeddingVector([0.1, 0.2], 2)], ['0'], 'test-model', new EmbeddingUsage(0, 0))
        );

        return $embeddingService;
    }
}
