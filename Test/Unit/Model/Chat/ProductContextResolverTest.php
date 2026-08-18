<?php

declare(strict_types=1);

namespace Aavirbhava\AiShoppingAssistant\Test\Unit\Model\Chat;

use Aavirbhava\AiShoppingAssistant\Api\Config\ConfigurationReaderInterface;
use Aavirbhava\AiShoppingAssistant\Api\Config\RetrievalConfigInterface;
use Aavirbhava\AiShoppingAssistant\Api\Ranking\RankingPipelineInterface;
use Aavirbhava\AiShoppingAssistant\Api\Retrieval\HybridRetrievalServiceInterface;
use Aavirbhava\AiShoppingAssistant\Model\Chat\ProductContextResolver;
use Aavirbhava\AiShoppingAssistant\Model\Ranking\SearchContext;
use Aavirbhava\AiShoppingAssistant\Model\Retrieval\SearchCandidate;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(ProductContextResolver::class)]
final class ProductContextResolverTest extends TestCase
{
    private const STORE_ID = 1;

    private function candidate(int $entityId): SearchCandidate
    {
        return new SearchCandidate($entityId, "SKU-$entityId", self::STORE_ID, 'Name', '', [], [], true, 4, 0.0, 0.0);
    }

    public function testRetrievesThenRanksAndReturnsTheRankedList(): void
    {
        $retrieved = [$this->candidate(1), $this->candidate(2)];
        $ranked = [$this->candidate(2), $this->candidate(1)];

        $retrievalService = $this->createMock(HybridRetrievalServiceInterface::class);
        $retrievalService->expects(self::once())
            ->method('retrieve')
            ->with(self::STORE_ID, 'blue shoe')
            ->willReturn($retrieved);

        $rankingPipeline = $this->createMock(RankingPipelineInterface::class);
        $capturedContext = null;
        $capturedCandidates = null;
        $rankingPipeline->expects(self::once())
            ->method('rank')
            ->willReturnCallback(
                function (SearchContext $context, array $candidates) use (&$capturedContext, &$capturedCandidates, $ranked) {
                    $capturedContext = $context;
                    $capturedCandidates = $candidates;
                    return $ranked;
                }
            );

        $resolver = new ProductContextResolver($this->configReader(false), $retrievalService, $rankingPipeline);

        $result = $resolver->resolve(self::STORE_ID, 'blue shoe');

        self::assertSame($ranked, $result);
        self::assertSame($retrieved, $capturedCandidates);
        self::assertSame(self::STORE_ID, $capturedContext->storeId);
        self::assertSame('blue shoe', $capturedContext->queryText);
        self::assertFalse($capturedContext->rerankerRequested);
    }

    public function testPassesTheConfiguredRerankerFlagIntoTheContext(): void
    {
        $retrievalService = $this->createMock(HybridRetrievalServiceInterface::class);
        $retrievalService->method('retrieve')->willReturn([]);

        $rankingPipeline = $this->createMock(RankingPipelineInterface::class);
        $capturedContext = null;
        $rankingPipeline->method('rank')->willReturnCallback(
            function (SearchContext $context) use (&$capturedContext) {
                $capturedContext = $context;
                return [];
            }
        );

        $resolver = new ProductContextResolver($this->configReader(true), $retrievalService, $rankingPipeline);
        $resolver->resolve(self::STORE_ID, 'blue shoe');

        self::assertTrue($capturedContext->rerankerRequested);
    }

    private function configReader(bool $rerankerEnabled): ConfigurationReaderInterface
    {
        $retrievalConfig = $this->createMock(RetrievalConfigInterface::class);
        $retrievalConfig->method('isRerankerEnabled')->willReturn($rerankerEnabled);

        $reader = $this->createMock(ConfigurationReaderInterface::class);
        $reader->method('readRetrieval')->with(self::STORE_ID)->willReturn($retrievalConfig);

        return $reader;
    }
}
