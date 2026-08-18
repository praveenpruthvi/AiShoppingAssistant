<?php

declare(strict_types=1);

namespace Aavirbhava\AiShoppingAssistant\Model\Chat;

use Aavirbhava\AiShoppingAssistant\Api\Config\ConfigurationReaderInterface;
use Aavirbhava\AiShoppingAssistant\Api\Ranking\RankingPipelineInterface;
use Aavirbhava\AiShoppingAssistant\Api\Retrieval\HybridRetrievalServiceInterface;
use Aavirbhava\AiShoppingAssistant\Model\Ranking\SearchContext;
use Aavirbhava\AiShoppingAssistant\Model\Retrieval\SearchCandidate;

/**
 * Composes retrieval and ranking into the product context ChatEntryPipeline
 * threads into a chat call: retrieve candidates, build the ranking
 * SearchContext (reading whether reranking is configured on, purely so a
 * later reranking task can consume that intent — this class never calls a
 * reranker), then rank.
 *
 * Failures from either step still propagate uncaught out of this class
 * itself — this class has no opinion on degradation, matching how
 * ChatGenerationService also just throws. ChatEntryPipeline::handle()
 * (Task 12) is what actually catches a ProductIndexingException/
 * ProviderException from the resolve() call below and returns a safe
 * fallback response; RankingPipeline::rank() throws nothing at request
 * time (its only exceptions are constructor-time di.xml wiring checks),
 * so in practice only retrieve() is the source of a caught failure here.
 */
final class ProductContextResolver
{
    public function __construct(
        private readonly ConfigurationReaderInterface $configurationReader,
        private readonly HybridRetrievalServiceInterface $retrievalService,
        private readonly RankingPipelineInterface $rankingPipeline
    ) {
    }

    /**
     * @return list<SearchCandidate>
     */
    public function resolve(int $storeId, string $queryText): array
    {
        $candidates = $this->retrievalService->retrieve($storeId, $queryText);

        $rerankerRequested = $this->configurationReader->readRetrieval($storeId)->isRerankerEnabled();
        $context = new SearchContext($storeId, $queryText, $rerankerRequested);

        return $this->rankingPipeline->rank($context, $candidates);
    }
}
