<?php

declare(strict_types=1);

namespace Aavirbhava\AiShoppingAssistant\Test\Unit\Model\Tool;

use Aavirbhava\AiShoppingAssistant\Api\Config\CapabilitiesConfigInterface;
use Aavirbhava\AiShoppingAssistant\Api\Config\ConfigurationReaderInterface;
use Aavirbhava\AiShoppingAssistant\Api\Config\RetrievalConfigInterface;
use Aavirbhava\AiShoppingAssistant\Api\Ranking\RankingPipelineInterface;
use Aavirbhava\AiShoppingAssistant\Api\Retrieval\HybridRetrievalServiceInterface;
use Aavirbhava\AiShoppingAssistant\Api\Revalidation\LiveRevalidationServiceInterface;
use Aavirbhava\AiShoppingAssistant\Model\Chat\ProductContextResolver;
use Aavirbhava\AiShoppingAssistant\Model\Ranking\SearchContext;
use Aavirbhava\AiShoppingAssistant\Model\Retrieval\SearchCandidate;
use Aavirbhava\AiShoppingAssistant\Model\Revalidation\RevalidatedProduct;
use Aavirbhava\AiShoppingAssistant\Model\Tool\Exception\ToolAuthorizationException;
use Aavirbhava\AiShoppingAssistant\Model\Tool\ProductFormatter;
use Aavirbhava\AiShoppingAssistant\Model\Tool\SearchProductsTool;
use Aavirbhava\AiShoppingAssistant\Model\Tool\ToolContext;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(SearchProductsTool::class)]
final class SearchProductsToolTest extends TestCase
{
    private const STORE_ID = 5;

    public function testNameAndSchema(): void
    {
        $tool = $this->tool();

        self::assertSame('search_products', $tool->name());
        self::assertSame(['query'], $tool->inputSchema()['required']);
    }

    public function testAuthorizeThrowsWhenProductDiscoveryIsDisabled(): void
    {
        $tool = $this->tool(productDiscoveryEnabled: false);

        $this->expectException(ToolAuthorizationException::class);
        $tool->authorize(new ToolContext(self::STORE_ID, null));
    }

    public function testAuthorizePassesWhenProductDiscoveryIsEnabled(): void
    {
        $tool = $this->tool();

        $tool->authorize(new ToolContext(self::STORE_ID, null));
        $this->expectNotToPerformAssertions();
    }

    public function testExecuteRejectsAMissingQuery(): void
    {
        $tool = $this->tool();

        $result = $tool->execute(new ToolContext(self::STORE_ID, null), []);

        self::assertArrayHasKey('error', $result->data);
        self::assertSame([], $result->verifiedProducts);
    }

    public function testExecuteResolvesAndRevalidatesCandidates(): void
    {
        $candidate = new SearchCandidate(1, 'SKU-1', self::STORE_ID, 'Blue Shoe', '', [], [], true, 4, 0.0, 0.0);
        $verified = new RevalidatedProduct(1, 'SKU-1', 'Blue Shoe', 49.99, null, 'https://store.test/blue-shoe', '2026-08-16T00:00:00+00:00');

        $retrievalService = $this->createMock(HybridRetrievalServiceInterface::class);
        $retrievalService->method('retrieve')->with(self::STORE_ID, 'waterproof phones')->willReturn([$candidate]);

        $revalidationService = $this->createMock(LiveRevalidationServiceInterface::class);
        $revalidationService->method('revalidate')->with(self::STORE_ID, null, ['SKU-1'])->willReturn([$verified]);

        $tool = $this->tool(retrievalService: $retrievalService, revalidationService: $revalidationService);

        $result = $tool->execute(new ToolContext(self::STORE_ID, null), ['query' => 'waterproof phones']);

        self::assertSame([$verified], $result->verifiedProducts);
        self::assertCount(1, $result->data['products']);
        self::assertSame('SKU-1', $result->data['products'][0]['sku']);
    }

    private function tool(
        bool $productDiscoveryEnabled = true,
        ?HybridRetrievalServiceInterface $retrievalService = null,
        ?LiveRevalidationServiceInterface $revalidationService = null
    ): SearchProductsTool {
        $capabilities = $this->createMock(CapabilitiesConfigInterface::class);
        $capabilities->method('isProductDiscoveryEnabled')->willReturn($productDiscoveryEnabled);

        $retrieval = $this->createMock(RetrievalConfigInterface::class);
        $retrieval->method('isRerankerEnabled')->willReturn(false);

        $configurationReader = $this->createMock(ConfigurationReaderInterface::class);
        $configurationReader->method('readCapabilities')->with(self::STORE_ID)->willReturn($capabilities);
        $configurationReader->method('readRetrieval')->with(self::STORE_ID)->willReturn($retrieval);

        $retrievalService ??= $this->noCandidatesRetrievalService();
        $revalidationService ??= $this->noVerifiedProductsRevalidationService();

        $rankingPipeline = $this->createMock(RankingPipelineInterface::class);
        $rankingPipeline->method('rank')->willReturnCallback(
            static fn (SearchContext $context, array $candidates): array => $candidates
        );

        return new SearchProductsTool(
            $configurationReader,
            new ProductContextResolver($configurationReader, $retrievalService, $rankingPipeline),
            $revalidationService,
            new ProductFormatter()
        );
    }

    private function noCandidatesRetrievalService(): HybridRetrievalServiceInterface
    {
        $retrievalService = $this->createMock(HybridRetrievalServiceInterface::class);
        $retrievalService->method('retrieve')->willReturn([]);

        return $retrievalService;
    }

    private function noVerifiedProductsRevalidationService(): LiveRevalidationServiceInterface
    {
        $revalidationService = $this->createMock(LiveRevalidationServiceInterface::class);
        $revalidationService->method('revalidate')->willReturn([]);

        return $revalidationService;
    }
}
