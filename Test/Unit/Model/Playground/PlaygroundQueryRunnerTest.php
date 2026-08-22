<?php

declare(strict_types=1);

namespace Aavirbhava\AiShoppingAssistant\Test\Unit\Model\Playground;

use Aavirbhava\AiShoppingAssistant\Api\Chat\ChatGenerationServiceInterface;
use Aavirbhava\AiShoppingAssistant\Api\Chat\CommerceScopeClassifierInterface;
use Aavirbhava\AiShoppingAssistant\Api\Chat\OutputValidatorInterface;
use Aavirbhava\AiShoppingAssistant\Api\Config\ConfigurationReaderInterface;
use Aavirbhava\AiShoppingAssistant\Api\Config\GeneralConfigInterface;
use Aavirbhava\AiShoppingAssistant\Api\Config\GuardrailConfigInterface;
use Aavirbhava\AiShoppingAssistant\Api\Config\RetrievalConfigInterface;
use Aavirbhava\AiShoppingAssistant\Api\Ranking\RankingPipelineInterface;
use Aavirbhava\AiShoppingAssistant\Api\Retrieval\HybridRetrievalServiceInterface;
use Aavirbhava\AiShoppingAssistant\Api\Revalidation\LiveRevalidationServiceInterface;
use Aavirbhava\AiShoppingAssistant\Api\Store\StoreScopeInterface;
use Aavirbhava\AiShoppingAssistant\Api\Store\StoreScopeProviderInterface;
use Aavirbhava\AiShoppingAssistant\Api\Tool\CommerceToolInterface;
use Aavirbhava\AiShoppingAssistant\Model\Chat\OutputValidator;
use Aavirbhava\AiShoppingAssistant\Model\Chat\ProductContextFormatter;
use Aavirbhava\AiShoppingAssistant\Model\Chat\Response\LlmResponseParser;
use Aavirbhava\AiShoppingAssistant\Model\Chat\Response\OutputValidationResult;
use Aavirbhava\AiShoppingAssistant\Model\Chat\ScopeClassification;
use Aavirbhava\AiShoppingAssistant\Model\Dto\ChatResponse;
use Aavirbhava\AiShoppingAssistant\Model\Dto\TokenUsage;
use Aavirbhava\AiShoppingAssistant\Model\Dto\ToolCall;
use Aavirbhava\AiShoppingAssistant\Model\Playground\PlaygroundQueryRunner;
use Aavirbhava\AiShoppingAssistant\Model\Provider\Exception\ProviderUnavailableException;
use Aavirbhava\AiShoppingAssistant\Model\Ranking\SearchContext;
use Aavirbhava\AiShoppingAssistant\Model\Retrieval\SearchCandidate;
use Aavirbhava\AiShoppingAssistant\Model\Revalidation\AvailabilityStatus;
use Aavirbhava\AiShoppingAssistant\Model\Revalidation\RevalidatedProduct;
use Aavirbhava\AiShoppingAssistant\Model\Store\Exception\StoreScopeException;
use Aavirbhava\AiShoppingAssistant\Model\Tool\CommerceToolRegistry;
use Aavirbhava\AiShoppingAssistant\Model\Tool\ToolContext;
use Aavirbhava\AiShoppingAssistant\Model\Tool\ToolResult;
use Magento\Framework\Phrase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Covers PlaygroundQueryRunner's own orchestration/capture logic — every
 * downstream service it calls (retrieval, ranking, revalidation, output
 * validation) already has its own dedicated test suite; this file proves
 * the runner wires them correctly and, critically, the cart-safety
 * guarantee: a Playground run must never be able to mutate a real cart.
 */
#[CoversClass(PlaygroundQueryRunner::class)]
final class PlaygroundQueryRunnerTest extends TestCase
{
    private const STORE_ID = 5;

    public function testRunsRetrievalRankingAndRevalidationWithoutCallingTheLlmByDefault(): void
    {
        $candidate = new SearchCandidate(1, 'SKU-1', self::STORE_ID, 'Blue Shoe', '', [], [], true, 4, 3.0, 2.0);

        $retrievalService = $this->createMock(HybridRetrievalServiceInterface::class);
        $retrievalService->method('retrieve')->with(self::STORE_ID, 'waterproof phones')->willReturn([$candidate]);

        $chatService = $this->createMock(ChatGenerationServiceInterface::class);
        $chatService->expects(self::never())->method('chat');

        $availability = new AvailabilityStatus('SKU-1', true, true, 'Blue Shoe');
        $verified = new RevalidatedProduct(1, 'SKU-1', 'Blue Shoe', 49.99, null, 'https://store.test/blue-shoe', '2026-08-16T00:00:00+00:00');
        $revalidationService = $this->createMock(LiveRevalidationServiceInterface::class);
        $revalidationService->method('checkAvailability')->with(self::STORE_ID, null, ['SKU-1'])->willReturn([$availability]);
        $revalidationService->method('revalidate')->with(self::STORE_ID, null, ['SKU-1'])->willReturn([$verified]);

        $runner = $this->runner(retrievalService: $retrievalService, revalidationService: $revalidationService, chatService: $chatService);

        $result = $runner->run(self::STORE_ID, 'waterproof phones', false);

        self::assertSame('waterproof phones', $result->queryText);
        self::assertTrue($result->inScope);
        self::assertSame([$candidate], $result->retrievedCandidates);
        self::assertCount(1, $result->rankedCandidates);
        self::assertSame([$availability], $result->revalidationOutcomes);
        self::assertSame([$verified], $result->verifiedProducts);
        self::assertStringContainsString('SKU-1', (string) $result->productContextText);
        self::assertFalse($result->llmWasCalled);
        self::assertNull($result->finalResponse);
        self::assertNull($result->safeResponse);
        self::assertSame([], $result->llmRounds);
        self::assertSame([], $result->toolExecutions);
    }

    public function testOutOfScopeQueryStillRunsDownstreamStagesForDiagnosticPurposes(): void
    {
        $classifier = $this->createMock(CommerceScopeClassifierInterface::class);
        $classifier->method('classify')->willReturn(ScopeClassification::outOfScope('off_topic_request'));

        $retrievalService = $this->createMock(HybridRetrievalServiceInterface::class);
        $retrievalService->expects(self::once())->method('retrieve')->willReturn([]);

        $runner = $this->runner(classifier: $classifier, retrievalService: $retrievalService);

        $result = $runner->run(self::STORE_ID, "what's the weather", false);

        self::assertFalse($result->inScope);
        self::assertSame('off_topic_request', $result->scopeReasonCode);
    }

    public function testInactiveStoreFailsClosedBeforeAnyDownstreamCall(): void
    {
        $storeScope = $this->createMock(StoreScopeProviderInterface::class);
        $storeScope->method('requireActive')->willThrowException(new StoreScopeException(new Phrase('inactive')));

        $retrievalService = $this->createMock(HybridRetrievalServiceInterface::class);
        $retrievalService->expects(self::never())->method('retrieve');

        $runner = $this->runner(storeScope: $storeScope, retrievalService: $retrievalService);

        $this->expectException(StoreScopeException::class);
        $runner->run(self::STORE_ID, 'query', false);
    }

    public function testMutatingCartToolsAreNeverOfferedToTheModelEvenWhenRegistered(): void
    {
        $addToCart = $this->createMock(CommerceToolInterface::class);
        $addToCart->method('name')->willReturn('add_to_cart');
        $addToCart->method('description')->willReturn('desc');
        $addToCart->method('inputSchema')->willReturn(['type' => 'object']);
        $addToCart->method('authorize')->willReturnCallback(static function (): void {});
        $addToCart->expects(self::never())->method('execute');

        $removeFromCart = $this->createMock(CommerceToolInterface::class);
        $removeFromCart->method('name')->willReturn('remove_from_cart');
        $removeFromCart->method('description')->willReturn('desc');
        $removeFromCart->method('inputSchema')->willReturn(['type' => 'object']);
        $removeFromCart->method('authorize')->willReturnCallback(static function (): void {});
        $removeFromCart->expects(self::never())->method('execute');

        $searchProducts = $this->createMock(CommerceToolInterface::class);
        $searchProducts->method('name')->willReturn('search_products');
        $searchProducts->method('description')->willReturn('desc');
        $searchProducts->method('inputSchema')->willReturn(['type' => 'object']);
        $searchProducts->method('authorize')->willReturnCallback(static function (): void {});

        $registry = new CommerceToolRegistry([
            'add_to_cart' => $addToCart,
            'remove_from_cart' => $removeFromCart,
            'search_products' => $searchProducts,
        ]);

        $capturedTools = null;
        $chatService = $this->createMock(ChatGenerationServiceInterface::class);
        $chatService->method('chat')->willReturnCallback(
            function (int $storeId, array $messages, array $tools) use (&$capturedTools): ChatResponse {
                $capturedTools = $tools;

                return new ChatResponse('ok text', [], new TokenUsage(1, 1), 'openai', 'gpt-4o-mini', 1);
            }
        );

        $outputValidator = $this->createMock(OutputValidatorInterface::class);
        $outputValidator->method('validate')->willReturn(OutputValidationResult::invalid('malformed_response'));

        $runner = $this->runner(toolRegistry: $registry, chatService: $chatService, outputValidator: $outputValidator);

        $runner->run(self::STORE_ID, 'add a red hat to my cart', true);

        self::assertNotNull($capturedTools);
        $offeredNames = array_column($capturedTools, 'name');
        self::assertNotContains('add_to_cart', $offeredNames);
        self::assertNotContains('remove_from_cart', $offeredNames);
        self::assertContains('search_products', $offeredNames);
    }

    public function testCartIdIsAlwaysNullForTheLlmCallEvenIfATooIsInvoked(): void
    {
        $capturedContext = null;
        $tool = $this->createMock(CommerceToolInterface::class);
        $tool->method('name')->willReturn('get_product_details');
        $tool->method('description')->willReturn('desc');
        $tool->method('inputSchema')->willReturn(['type' => 'object']);
        $tool->method('authorize')->willReturnCallback(function (ToolContext $context) use (&$capturedContext): void {
            $capturedContext = $context;
        });
        $tool->method('execute')->willReturn(new ToolResult(['found' => false]));

        $toolCall = new ToolCall('call_1', 'get_product_details', ['sku' => 'SKU-1']);
        $calls = 0;
        $chatService = $this->createMock(ChatGenerationServiceInterface::class);
        $chatService->method('chat')->willReturnCallback(function () use (&$calls, $toolCall): ChatResponse {
            $calls++;

            return $calls === 1
                ? new ChatResponse('', [$toolCall], new TokenUsage(1, 1), 'openai', 'gpt-4o-mini', 1)
                : new ChatResponse('ok', [], new TokenUsage(1, 1), 'openai', 'gpt-4o-mini', 1);
        });

        $registry = new CommerceToolRegistry(['get_product_details' => $tool]);

        $runner = $this->runner(toolRegistry: $registry, chatService: $chatService);

        $runner->run(self::STORE_ID, 'tell me about SKU-1', true);

        self::assertNotNull($capturedContext);
        self::assertNull($capturedContext->cartId);
    }

    public function testValidLlmResponsePopulatesFinalResponseAndTokenTotals(): void
    {
        $verified = new RevalidatedProduct(1, 'SKU-1', 'Blue Shoe', 49.99, null, 'https://store.test/blue-shoe', '2026-08-16T00:00:00+00:00');
        $revalidationService = $this->createMock(LiveRevalidationServiceInterface::class);
        $revalidationService->method('revalidate')->willReturn([$verified]);
        $revalidationService->method('checkAvailability')->willReturn([]);

        $body = json_encode([
            'message' => 'Here you go.',
            'product_skus' => [],
            'follow_up_questions' => [],
            'actions' => [],
        ]);
        $chatService = $this->createMock(ChatGenerationServiceInterface::class);
        $chatService->method('chat')->willReturn(
            new ChatResponse($body, [], new TokenUsage(10, 5, 2), 'openai', 'gpt-4o-mini', 123)
        );

        $runner = $this->runner(
            revalidationService: $revalidationService,
            chatService: $chatService,
            outputValidator: new OutputValidator(new LlmResponseParser())
        );

        $result = $runner->run(self::STORE_ID, 'query', true);

        self::assertTrue($result->llmWasCalled);
        self::assertNotNull($result->finalResponse);
        self::assertSame('Here you go.', $result->finalResponse->message);
        self::assertNull($result->safeResponse);
        self::assertSame('openai', $result->llmProvider);
        self::assertSame('gpt-4o-mini', $result->llmModel);
        self::assertSame(10, $result->totalInputTokens);
        self::assertSame(5, $result->totalOutputTokens);
        self::assertSame(2, $result->totalCachedTokens);
        self::assertSame(123, $result->totalLatencyMilliseconds);
        self::assertCount(1, $result->llmRounds);
    }

    public function testRejectedLlmResponsePopulatesSafeResponseNotFinalResponse(): void
    {
        $chatService = $this->createMock(ChatGenerationServiceInterface::class);
        $chatService->method('chat')->willReturn(
            new ChatResponse('not valid json', [], new TokenUsage(1, 1), 'openai', 'gpt-4o-mini', 1)
        );

        $runner = $this->runner(
            chatService: $chatService,
            outputValidator: new OutputValidator(new LlmResponseParser())
        );

        $result = $runner->run(self::STORE_ID, 'query', true);

        self::assertNull($result->finalResponse);
        self::assertNotNull($result->safeResponse);
        self::assertSame(OutputValidator::REASON_MALFORMED_RESPONSE, $result->safeResponse->reasonCode);
    }

    public function testProviderFailurePopulatesLlmErrorRatherThanPropagating(): void
    {
        $chatService = $this->createMock(ChatGenerationServiceInterface::class);
        $chatService->method('chat')->willThrowException(
            new ProviderUnavailableException(new Phrase('The chat provider is temporarily unavailable.'))
        );

        $runner = $this->runner(chatService: $chatService);

        $result = $runner->run(self::STORE_ID, 'query', true);

        self::assertNull($result->finalResponse);
        self::assertNull($result->safeResponse);
        self::assertNotNull($result->llmError);
    }

    private function runner(
        ?StoreScopeProviderInterface $storeScope = null,
        ?ConfigurationReaderInterface $configurationReader = null,
        ?CommerceScopeClassifierInterface $classifier = null,
        ?HybridRetrievalServiceInterface $retrievalService = null,
        ?RankingPipelineInterface $rankingPipeline = null,
        ?LiveRevalidationServiceInterface $revalidationService = null,
        ?ChatGenerationServiceInterface $chatService = null,
        ?CommerceToolRegistry $toolRegistry = null,
        ?OutputValidatorInterface $outputValidator = null
    ): PlaygroundQueryRunner {
        $configurationReader ??= $this->configurationReader();
        $storeScope ??= $this->activeStoreScope();

        if ($classifier === null) {
            $classifier = $this->createMock(CommerceScopeClassifierInterface::class);
            $classifier->method('classify')->willReturn(ScopeClassification::inScope());
        }

        $retrievalService ??= $this->noCandidatesRetrievalService();
        $rankingPipeline ??= $this->passthroughRankingPipeline();
        $revalidationService ??= $this->noVerifiedProductsRevalidationService();
        $chatService ??= $this->createMock(ChatGenerationServiceInterface::class);
        $toolRegistry ??= new CommerceToolRegistry();

        if ($outputValidator === null) {
            $outputValidator = $this->createMock(OutputValidatorInterface::class);
            $outputValidator->method('validate')->willReturn(OutputValidationResult::invalid('malformed_response'));
        }

        return new PlaygroundQueryRunner(
            $storeScope,
            $configurationReader,
            $classifier,
            $retrievalService,
            $rankingPipeline,
            $revalidationService,
            new ProductContextFormatter($configurationReader),
            $chatService,
            $toolRegistry,
            $outputValidator
        );
    }

    private function configurationReader(): ConfigurationReaderInterface
    {
        $retrieval = $this->createMock(RetrievalConfigInterface::class);
        $retrieval->method('isRerankerEnabled')->willReturn(false);

        $guardrails = $this->createMock(GuardrailConfigInterface::class);
        $guardrails->method('maxToolCalls')->willReturn(4);

        $general = $this->createMock(GeneralConfigInterface::class);
        $general->method('isTokenOptimizationEnabled')->willReturn(false);

        $reader = $this->createMock(ConfigurationReaderInterface::class);
        $reader->method('readRetrieval')->with(self::STORE_ID)->willReturn($retrieval);
        $reader->method('readGuardrails')->with(self::STORE_ID)->willReturn($guardrails);
        $reader->method('readGeneral')->with(self::STORE_ID)->willReturn($general);

        return $reader;
    }

    private function activeStoreScope(): StoreScopeProviderInterface
    {
        $storeScope = $this->createMock(StoreScopeProviderInterface::class);
        $storeScope->method('requireActive')->with(self::STORE_ID)->willReturn($this->createMock(StoreScopeInterface::class));

        return $storeScope;
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
        $revalidationService->method('checkAvailability')->willReturn([]);

        return $revalidationService;
    }

    private function passthroughRankingPipeline(): RankingPipelineInterface
    {
        $rankingPipeline = $this->createMock(RankingPipelineInterface::class);
        $rankingPipeline->method('rank')->willReturnCallback(
            static fn (SearchContext $context, array $candidates): array => $candidates
        );

        return $rankingPipeline;
    }
}
