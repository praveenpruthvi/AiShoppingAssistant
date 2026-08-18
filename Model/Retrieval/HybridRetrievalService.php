<?php

declare(strict_types=1);

namespace Aavirbhava\AiShoppingAssistant\Model\Retrieval;

use Aavirbhava\AiShoppingAssistant\Api\Config\ConfigurationReaderInterface;
use Aavirbhava\AiShoppingAssistant\Api\Embedding\EmbeddingGenerationServiceInterface;
use Aavirbhava\AiShoppingAssistant\Api\Indexing\AssistantSearchClientInterface;
use Aavirbhava\AiShoppingAssistant\Api\Indexing\IndexNamingServiceInterface;
use Aavirbhava\AiShoppingAssistant\Api\Indexing\ProductIndexMappingInterface as Field;
use Aavirbhava\AiShoppingAssistant\Api\Retrieval\HybridRetrievalServiceInterface;
use Aavirbhava\AiShoppingAssistant\Api\Store\StoreScopeProviderInterface;
use Aavirbhava\AiShoppingAssistant\Model\Embedding\EmbeddingInputType;
use InvalidArgumentException;

/**
 * Store-scoped hybrid (BM25 + vector) retrieval against the assistant index.
 *
 * Mirrors ChatGenerationService/EmbeddingGenerationService: activates and
 * scopes to a store view, reads store-scoped config, and never writes
 * anything. The query vector is produced through
 * EmbeddingGenerationServiceInterface (never a direct provider call), using
 * EmbeddingInputType::query() so asymmetric embedding models (e.g. Voyage)
 * embed the query differently from indexed documents, exactly as they were
 * embedded during indexing.
 */
final class HybridRetrievalService implements HybridRetrievalServiceInterface
{
    public const QUERY_EMBEDDING_TIMEOUT_SECONDS = 20;

    public function __construct(
        private readonly StoreScopeProviderInterface $storeScopeProvider,
        private readonly ConfigurationReaderInterface $configurationReader,
        private readonly IndexNamingServiceInterface $indexNamingService,
        private readonly AssistantSearchClientInterface $searchClient,
        private readonly EmbeddingGenerationServiceInterface $embeddingGenerationService,
        private readonly SearchQueryBuilder $queryBuilder,
        private readonly SearchHitParser $hitParser
    ) {
    }

    public function retrieve(int $storeId, string $queryText): array
    {
        $trimmed = trim($queryText);
        if ($trimmed === '') {
            throw new InvalidArgumentException('A retrieval query must not be empty.');
        }

        $scope = $this->storeScopeProvider->requireActive($storeId);
        $retrievalConfig = $this->configurationReader->readRetrieval($storeId);
        $prefix = $this->configurationReader->readIndexing($storeId)->indexPrefix();
        $alias = $this->indexNamingService->readAlias($prefix, $scope);

        $keywordHits = $this->searchClient->search(
            $alias,
            $this->queryBuilder->keyword($storeId, $trimmed, $retrievalConfig->keywordCandidates())
        );

        $queryVector = $this->embedQuery($storeId, $trimmed);

        $vectorHits = $this->searchClient->search(
            $alias,
            $this->queryBuilder->vector($storeId, $queryVector, $retrievalConfig->vectorCandidates())
        );

        $merged = $this->merge($keywordHits, $vectorHits);

        return array_slice($merged, 0, $retrievalConfig->mergedCandidates());
    }

    /**
     * @return list<float>
     */
    private function embedQuery(int $storeId, string $queryText): array
    {
        $result = $this->embeddingGenerationService->embed($storeId, EmbeddingInputType::query(), [$queryText]);

        return $result->vectors()[0]->values();
    }

    /**
     * Merges keyword and vector hits by entity id, keeping the raw score from
     * each query (0.0 when a candidate was only found by the other query),
     * then orders the union by a simple normalized-score-sum for the sole
     * purpose of capping at mergedCandidates — this is not the final ranking,
     * which RankingPipelineInterface computes over the full candidate list.
     *
     * @param list<array{_id: string, _score: float, _source: array<string, mixed>}> $keywordHits
     * @param list<array{_id: string, _score: float, _source: array<string, mixed>}> $vectorHits
     *
     * @return list<SearchCandidate>
     */
    private function merge(array $keywordHits, array $vectorHits): array
    {
        $sources = [];
        $bm25Scores = [];
        $vectorScores = [];

        foreach ($keywordHits as $hit) {
            $entityId = $hit['_source'][Field::FIELD_ENTITY_ID] ?? null;
            if (!is_int($entityId)) {
                continue;
            }
            $sources[$entityId] ??= $hit['_source'];
            $bm25Scores[$entityId] = $hit['_score'];
        }

        foreach ($vectorHits as $hit) {
            $entityId = $hit['_source'][Field::FIELD_ENTITY_ID] ?? null;
            if (!is_int($entityId)) {
                continue;
            }
            $sources[$entityId] ??= $hit['_source'];
            $vectorScores[$entityId] = $hit['_score'];
        }

        $maxBm25 = $bm25Scores === [] ? 0.0 : max($bm25Scores);
        $maxVector = $vectorScores === [] ? 0.0 : max($vectorScores);

        $candidates = [];
        foreach ($sources as $entityId => $source) {
            $bm25Score = $bm25Scores[$entityId] ?? 0.0;
            $vectorScore = $vectorScores[$entityId] ?? 0.0;

            $candidates[] = [
                'candidate' => $this->hitParser->parse($source, $bm25Score, $vectorScore),
                'mergeRank' => ($maxBm25 > 0.0 ? $bm25Score / $maxBm25 : 0.0)
                    + ($maxVector > 0.0 ? $vectorScore / $maxVector : 0.0),
            ];
        }

        usort($candidates, static fn (array $a, array $b): int => $b['mergeRank'] <=> $a['mergeRank']);

        return array_map(static fn (array $entry): SearchCandidate => $entry['candidate'], $candidates);
    }
}
