<?php

declare(strict_types=1);

namespace Aavirbhava\AiShoppingAssistant\Test\Unit\Model\Chat;

use Aavirbhava\AiShoppingAssistant\Api\Chat\CommerceScopeClassifierInterface;
use Aavirbhava\AiShoppingAssistant\Api\Chat\ConversationHistoryStoreInterface;
use Aavirbhava\AiShoppingAssistant\Api\Chat\ToolCallingChatServiceInterface;
use Aavirbhava\AiShoppingAssistant\Api\Config\ConfigurationReaderInterface;
use Aavirbhava\AiShoppingAssistant\Api\Config\GeneralConfigInterface;
use Aavirbhava\AiShoppingAssistant\Api\Config\GuardrailConfigInterface;
use Aavirbhava\AiShoppingAssistant\Api\Config\RetrievalConfigInterface;
use Aavirbhava\AiShoppingAssistant\Api\Ranking\RankingPipelineInterface;
use Aavirbhava\AiShoppingAssistant\Api\Retrieval\HybridRetrievalServiceInterface;
use Aavirbhava\AiShoppingAssistant\Api\Revalidation\LiveRevalidationServiceInterface;
use Aavirbhava\AiShoppingAssistant\Api\Store\StoreScopeInterface;
use Aavirbhava\AiShoppingAssistant\Api\Store\StoreScopeProviderInterface;
use Aavirbhava\AiShoppingAssistant\Model\Chat\ChatEntryPipeline;
use Aavirbhava\AiShoppingAssistant\Model\Chat\ChatInputValidator;
use Aavirbhava\AiShoppingAssistant\Model\Chat\Debug\ChatDebugLogger;
use Aavirbhava\AiShoppingAssistant\Model\Chat\Exception\ChatInputException;
use Aavirbhava\AiShoppingAssistant\Model\Chat\OutputValidator;
use Aavirbhava\AiShoppingAssistant\Model\Chat\ProductContextFormatter;
use Aavirbhava\AiShoppingAssistant\Model\Chat\ChatResponseSerializer;
use Aavirbhava\AiShoppingAssistant\Model\Chat\ProductContextResolver;
use Aavirbhava\AiShoppingAssistant\Model\Chat\PriceConstraintDetector;
use Aavirbhava\AiShoppingAssistant\Model\Chat\PriceConstraintReconciler;
use Aavirbhava\AiShoppingAssistant\Model\Chat\PriorTurnProductCarryOver;
use Aavirbhava\AiShoppingAssistant\Model\Chat\ProductMentionCompletenessChecker;
use Aavirbhava\AiShoppingAssistant\Model\Chat\ResponseContractFormatter;
use Aavirbhava\AiShoppingAssistant\Model\Chat\Response\LlmResponseParser;
use Aavirbhava\AiShoppingAssistant\Model\Chat\ScopeClassification;
use Aavirbhava\AiShoppingAssistant\Model\Chat\StoredConversationMessage;
use Aavirbhava\AiShoppingAssistant\Model\Chat\ToolCallingResult;
use Aavirbhava\AiShoppingAssistant\Model\Dto\ChatMessage;
use Aavirbhava\AiShoppingAssistant\Model\Dto\ChatResponse;
use Aavirbhava\AiShoppingAssistant\Model\Dto\TokenUsage;
use Aavirbhava\AiShoppingAssistant\Model\Dto\ToolCall;
use Aavirbhava\AiShoppingAssistant\Model\Embedding\Exception\EmbeddingConfigurationException;
use Aavirbhava\AiShoppingAssistant\Model\Indexing\Exception\SearchQueryFailedException;
use Aavirbhava\AiShoppingAssistant\Model\Provider\Exception\ProviderInvalidResponseException;
use Aavirbhava\AiShoppingAssistant\Model\Provider\Exception\ProviderUnavailableException;
use Aavirbhava\AiShoppingAssistant\Model\Ranking\SearchContext;
use Aavirbhava\AiShoppingAssistant\Model\Retrieval\SearchCandidate;
use Aavirbhava\AiShoppingAssistant\Model\Revalidation\RevalidatedProduct;
use Aavirbhava\AiShoppingAssistant\Model\Store\Exception\StoreScopeException;
use Magento\Framework\Phrase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Proves the full pipeline routing contract: out-of-scope/disabled/invalid
 * input never reach retrieval, revalidation, or ToolCallingChatServiceInterface
 * (and therefore never reach ChatGenerationServiceInterface/OpenAiProvider);
 * an in-scope message reaches all of them; a fabricated SKU or malformed
 * provider response never reaches the final AssistantResponse and instead
 * produces the same SafeResponse shape as an out-of-scope short-circuit;
 * RevalidatedProducts a tool call verified mid-conversation are merged into
 * the set OutputValidator checks the final response against; (Task 8) prior
 * conversation history is loaded and threaded in, cartId is passed through,
 * and only a validated (never a short-circuited) turn is persisted.
 *
 * Uses the real OutputValidator/LlmResponseParser (pure, deterministic,
 * cheap) rather than mocking them, matching this file's existing precedent
 * of using a real ChatInputValidator.
 */
#[CoversClass(ChatEntryPipeline::class)]
final class ChatEntryPipelineTest extends TestCase
{
    private const STORE_ID = 5;
    private const OUT_OF_SCOPE_MESSAGE = 'I can help you search, compare, and learn about products on this store.';

    public function testOutOfScopeMessageNeverReachesRetrievalRevalidationOrToolCallingChatService(): void
    {
        $classifier = $this->createMock(CommerceScopeClassifierInterface::class);
        $classifier->method('classify')->willReturn(ScopeClassification::outOfScope('off_topic_request'));

        $retrievalService = $this->createMock(HybridRetrievalServiceInterface::class);
        $retrievalService->expects(self::never())->method('retrieve');

        $revalidationService = $this->createMock(LiveRevalidationServiceInterface::class);
        $revalidationService->expects(self::never())->method('revalidate');

        $toolCallingChatService = $this->createMock(ToolCallingChatServiceInterface::class);
        $toolCallingChatService->expects(self::never())->method('converse');

        $pipeline = $this->pipeline(
            classifier: $classifier,
            retrievalService: $retrievalService,
            revalidationService: $revalidationService,
            toolCallingChatService: $toolCallingChatService
        );

        $result = $pipeline->handle(self::STORE_ID, "What's the weather like today?");

        self::assertTrue($result->wasShortCircuited());
        self::assertSame('off_topic_request', $result->safeResponse()->reasonCode);
        self::assertSame(self::OUT_OF_SCOPE_MESSAGE, $result->safeResponse()->message);
    }

    public function testValidStructuredResponseWithNoCandidatesProducesTheContract(): void
    {
        $classifier = $this->createMock(CommerceScopeClassifierInterface::class);
        $classifier->method('classify')->willReturn(ScopeClassification::inScope());

        $toolCallingChatService = $this->createMock(ToolCallingChatServiceInterface::class);
        $toolCallingChatService->expects(self::once())
            ->method('converse')
            ->willReturn($this->toolCallingResult('Here are some options.', []));

        $pipeline = $this->pipeline(classifier: $classifier, toolCallingChatService: $toolCallingChatService);

        $result = $pipeline->handle(self::STORE_ID, '  Show me waterproof phones.  ');

        self::assertFalse($result->wasShortCircuited());
        self::assertSame('Here are some options.', $result->response()->message);
        self::assertSame([], $result->response()->products);
        self::assertFalse($result->isAwaitingConfirmation());
    }

    public function testConfirmationRequiredToolResultInTheRoundTripMarksTheResultAsAwaitingConfirmation(): void
    {
        $classifier = $this->createMock(CommerceScopeClassifierInterface::class);
        $classifier->method('classify')->willReturn(ScopeClassification::inScope());

        $toolResultMessage = new ChatMessage(
            'tool',
            json_encode([
                'status' => 'confirmation_required',
                'action' => 'add_to_cart',
                'sku' => 'SKU-1',
                'qty' => 1,
                'confirmation_token' => 'opaque-token',
                'message' => 'Ask the customer to confirm.',
            ], JSON_THROW_ON_ERROR),
            'call-1'
        );

        $toolCallingResult = new ToolCallingResult(
            $this->structuredChatResponse('Would you like me to add that to your cart?', []),
            [],
            [$toolResultMessage]
        );

        $toolCallingChatService = $this->createMock(ToolCallingChatServiceInterface::class);
        $toolCallingChatService->method('converse')->willReturn($toolCallingResult);

        $pipeline = $this->pipeline(classifier: $classifier, toolCallingChatService: $toolCallingChatService);

        $result = $pipeline->handle(self::STORE_ID, 'Add a red hat to my cart.');

        self::assertFalse($result->wasShortCircuited());
        self::assertTrue($result->isAwaitingConfirmation());
    }

    public function testInScopeMessagePassesTheResponseSchemaAndTheUserMessage(): void
    {
        $classifier = $this->createMock(CommerceScopeClassifierInterface::class);
        $classifier->method('classify')->willReturn(ScopeClassification::inScope());

        $captured = null;
        $toolCallingChatService = $this->createMock(ToolCallingChatServiceInterface::class);
        $toolCallingChatService->method('converse')->willReturnCallback(
            function (int $storeId, ?int $customerGroupId, ?string $cartId, array $messages, ?array $schema) use (&$captured): ToolCallingResult {
                $captured = [$storeId, $cartId, $messages, $schema];

                return $this->toolCallingResult('ok', []);
            }
        );

        $pipeline = $this->pipeline(classifier: $classifier, toolCallingChatService: $toolCallingChatService);

        $pipeline->handle(self::STORE_ID, 'Show me waterproof phones.');

        self::assertNotNull($captured);
        self::assertSame(self::STORE_ID, $captured[0]);
        self::assertNull($captured[1]);
        self::assertCount(2, $captured[2]);
        self::assertSame('system', $captured[2][0]->role);
        self::assertInstanceOf(ChatMessage::class, $captured[2][1]);
        self::assertSame('user', $captured[2][1]->role);
        self::assertSame('Show me waterproof phones.', $captured[2][1]->content);
        self::assertNotNull($captured[3]);
        self::assertSame('object', $captured[3]['type']);
    }

    public function testCartIdIsPassedThroughToToolCallingChatService(): void
    {
        $classifier = $this->createMock(CommerceScopeClassifierInterface::class);
        $classifier->method('classify')->willReturn(ScopeClassification::inScope());

        $toolCallingChatService = $this->createMock(ToolCallingChatServiceInterface::class);
        $toolCallingChatService->expects(self::once())
            ->method('converse')
            ->with(self::STORE_ID, null, 'masked-cart-abc', self::anything(), self::anything())
            ->willReturn($this->toolCallingResult('ok', []));

        $pipeline = $this->pipeline(classifier: $classifier, toolCallingChatService: $toolCallingChatService);

        $pipeline->handle(self::STORE_ID, 'Show me waterproof phones.', null, 'masked-cart-abc');
    }

    public function testMalformedResponseIsRetriedOnceAndSucceedsOnTheSecondAttempt(): void
    {
        $classifier = $this->createMock(CommerceScopeClassifierInterface::class);
        $classifier->method('classify')->willReturn(ScopeClassification::inScope());

        $malformedResponse = new ChatResponse(
            'Here are some options in plain prose.',
            [],
            new TokenUsage(1, 1),
            'openai_compatible',
            'local-model',
            5
        );

        $callCount = 0;
        $capturedSecondCallMessages = null;
        $toolCallingChatService = $this->createMock(ToolCallingChatServiceInterface::class);
        $toolCallingChatService->method('converse')->willReturnCallback(
            function (
                int $storeId,
                ?int $customerGroupId,
                ?string $cartId,
                array $messages
            ) use (&$callCount, &$capturedSecondCallMessages, $malformedResponse): ToolCallingResult {
                $callCount++;

                if ($callCount === 1) {
                    return new ToolCallingResult($malformedResponse, []);
                }

                $capturedSecondCallMessages = $messages;

                return $this->toolCallingResult('Here are some options.', []);
            }
        );

        $pipeline = $this->pipeline(classifier: $classifier, toolCallingChatService: $toolCallingChatService);

        $result = $pipeline->handle(self::STORE_ID, 'Show me waterproof phones.');

        self::assertSame(2, $callCount);
        self::assertFalse($result->wasShortCircuited());
        self::assertSame('Here are some options.', $result->response()->message);
        self::assertNotNull($capturedSecondCallMessages);
        $lastMessage = $capturedSecondCallMessages[array_key_last($capturedSecondCallMessages)];
        self::assertSame('user', $lastMessage->role);
        self::assertStringContainsString('not valid', $lastMessage->content);
    }

    public function testMalformedResponseOnEveryAttemptExhaustsRetriesAndShortCircuits(): void
    {
        $classifier = $this->createMock(CommerceScopeClassifierInterface::class);
        $classifier->method('classify')->willReturn(ScopeClassification::inScope());

        $malformedResponse = new ChatResponse(
            'Still plain prose.',
            [],
            new TokenUsage(1, 1),
            'openai_compatible',
            'local-model',
            5
        );

        $toolCallingChatService = $this->createMock(ToolCallingChatServiceInterface::class);
        $toolCallingChatService->expects(self::exactly(2))
            ->method('converse')
            ->willReturn(new ToolCallingResult($malformedResponse, []));

        $pipeline = $this->pipeline(classifier: $classifier, toolCallingChatService: $toolCallingChatService);

        $result = $pipeline->handle(self::STORE_ID, 'Show me waterproof phones.');

        self::assertTrue($result->wasShortCircuited());
        self::assertSame('malformed_response', $result->safeResponse()->reasonCode);
    }

    public function testInvalidProviderResponseIsRetriedOnceAndSucceedsOnTheSecondAttempt(): void
    {
        // Task 23: live-reproduced that the model sometimes exhausts its
        // tool-call budget and is then force-answered with no tools
        // offered, at which point it occasionally returns a genuinely
        // empty/unparseable completion — a ProviderInvalidResponseException
        // thrown from inside converse(), not something OutputValidator
        // ever sees. Previously this failed the whole turn outright on
        // the very first occurrence; it now gets the same one-retry
        // treatment as a malformed response.
        $classifier = $this->createMock(CommerceScopeClassifierInterface::class);
        $classifier->method('classify')->willReturn(ScopeClassification::inScope());

        $callCount = 0;
        $capturedSecondCallMessages = null;
        $toolCallingChatService = $this->createMock(ToolCallingChatServiceInterface::class);
        $toolCallingChatService->method('converse')->willReturnCallback(
            function (
                int $storeId,
                ?int $customerGroupId,
                ?string $cartId,
                array $messages
            ) use (&$callCount, &$capturedSecondCallMessages): ToolCallingResult {
                $callCount++;

                if ($callCount === 1) {
                    throw new ProviderInvalidResponseException(new Phrase('The chat provider returned an empty response.'));
                }

                $capturedSecondCallMessages = $messages;

                return $this->toolCallingResult('Here are some options.', []);
            }
        );

        $pipeline = $this->pipeline(classifier: $classifier, toolCallingChatService: $toolCallingChatService);

        $result = $pipeline->handle(self::STORE_ID, 'Show me waterproof phones.');

        self::assertSame(2, $callCount);
        self::assertFalse($result->wasShortCircuited());
        self::assertSame('Here are some options.', $result->response()->message);
        self::assertNotNull($capturedSecondCallMessages);
        $lastMessage = $capturedSecondCallMessages[array_key_last($capturedSecondCallMessages)];
        self::assertSame('user', $lastMessage->role);
        self::assertStringContainsString('product_skus', $lastMessage->content);
    }

    public function testInvalidProviderResponseOnEveryAttemptExhaustsRetriesAndShortCircuits(): void
    {
        $classifier = $this->createMock(CommerceScopeClassifierInterface::class);
        $classifier->method('classify')->willReturn(ScopeClassification::inScope());

        $toolCallingChatService = $this->createMock(ToolCallingChatServiceInterface::class);
        $toolCallingChatService->expects(self::exactly(2))
            ->method('converse')
            ->willThrowException(new ProviderInvalidResponseException(new Phrase('The chat provider returned an empty response.')));

        $pipeline = $this->pipeline(classifier: $classifier, toolCallingChatService: $toolCallingChatService);

        $result = $pipeline->handle(self::STORE_ID, 'Show me waterproof phones.');

        self::assertTrue($result->wasShortCircuited());
        self::assertSame(ChatEntryPipeline::REASON_ASSISTANT_UNAVAILABLE, $result->safeResponse()->reasonCode);
    }

    public function testGenuineProviderUnavailabilityIsNeverRetriedUnlikeAnInvalidResponse(): void
    {
        // A real availability failure (already retried/circuit-broken
        // inside FallbackChatGenerationService before it ever reaches
        // here) must still short-circuit on the very first occurrence —
        // only the narrower ProviderInvalidResponseException gets the
        // compliance-repair retry added in Task 23.
        $classifier = $this->createMock(CommerceScopeClassifierInterface::class);
        $classifier->method('classify')->willReturn(ScopeClassification::inScope());

        $toolCallingChatService = $this->createMock(ToolCallingChatServiceInterface::class);
        $toolCallingChatService->expects(self::once())
            ->method('converse')
            ->willThrowException(new ProviderUnavailableException(new Phrase('The chat provider is temporarily unavailable.')));

        $pipeline = $this->pipeline(classifier: $classifier, toolCallingChatService: $toolCallingChatService);

        $result = $pipeline->handle(self::STORE_ID, 'Show me waterproof phones.');

        self::assertTrue($result->wasShortCircuited());
        self::assertSame(ChatEntryPipeline::REASON_ASSISTANT_UNAVAILABLE, $result->safeResponse()->reasonCode);
    }

    public function testIncompleteProductsAreRetriedOnceAndTheRetryAddsTheMissingSku(): void
    {
        // Task 23: live-reproduced "here are 2 jackets" rendering only 1
        // card — the model named a second, real, verified product in its
        // own message text but never selected its SKU into product_skus.
        // ChatEntryPipeline now gives it one more chance, naming exactly
        // which SKU(s) were missing.
        $classifier = $this->createMock(CommerceScopeClassifierInterface::class);
        $classifier->method('classify')->willReturn(ScopeClassification::inScope());

        $product1 = new RevalidatedProduct(1, 'SKU-1', 'Jade Yoga Jacket', 32.0, null, 'https://store.test/sku-1', '2026-08-16T00:00:00+00:00');
        $product2 = new RevalidatedProduct(2, 'SKU-2', 'Montana Wind Jacket', 45.0, null, 'https://store.test/sku-2', '2026-08-16T00:00:00+00:00');

        $revalidationService = $this->createMock(LiveRevalidationServiceInterface::class);
        $revalidationService->method('revalidate')->willReturn([$product1, $product2]);

        $callCount = 0;
        $capturedSecondCallMessages = null;
        $toolCallingChatService = $this->createMock(ToolCallingChatServiceInterface::class);
        $toolCallingChatService->method('converse')->willReturnCallback(
            function (
                int $storeId,
                ?int $customerGroupId,
                ?string $cartId,
                array $messages
            ) use (&$callCount, &$capturedSecondCallMessages, $product1, $product2): ToolCallingResult {
                $callCount++;

                if ($callCount === 1) {
                    return new ToolCallingResult(
                        $this->structuredChatResponse(
                            'Here are two jackets: the Jade Yoga Jacket and the Montana Wind Jacket.',
                            [['sku' => 'SKU-1', 'reason' => 'Great for yoga.']]
                        ),
                        [$product1, $product2]
                    );
                }

                $capturedSecondCallMessages = $messages;

                return new ToolCallingResult(
                    $this->structuredChatResponse(
                        'Here are two jackets: the Jade Yoga Jacket and the Montana Wind Jacket.',
                        [
                            ['sku' => 'SKU-1', 'reason' => 'Great for yoga.'],
                            ['sku' => 'SKU-2', 'reason' => 'Great for wind.'],
                        ]
                    ),
                    [$product1, $product2]
                );
            }
        );

        $pipeline = $this->pipeline(
            classifier: $classifier,
            toolCallingChatService: $toolCallingChatService,
            revalidationService: $revalidationService
        );

        $result = $pipeline->handle(self::STORE_ID, 'Show me jackets.');

        self::assertSame(2, $callCount);
        self::assertFalse($result->wasShortCircuited());
        self::assertCount(2, $result->response()->products);
        self::assertNotNull($capturedSecondCallMessages);
        $lastMessage = $capturedSecondCallMessages[array_key_last($capturedSecondCallMessages)];
        self::assertSame('user', $lastMessage->role);
        self::assertStringContainsString('SKU-2', $lastMessage->content);
        self::assertStringContainsString('Montana Wind Jacket', $lastMessage->content);
    }

    public function testACompletenessGapFirstSeenOnTheLastComplianceAttemptStillGetsItsOwnRetry(): void
    {
        // Task 29: the real, live-reproduced bug — a malformed response
        // on attempt 1 consumes the whole malformed-retry budget, so a
        // *completeness* gap that only surfaces on attempt 2 (the last
        // compliance attempt) previously had zero chance of ever being
        // corrected: the loop broke immediately because attemptsRemain
        // was already false, regardless of the gap. A bonus, completeness-
        // only 3rd attempt now guarantees it still gets one real try.
        $classifier = $this->createMock(CommerceScopeClassifierInterface::class);
        $classifier->method('classify')->willReturn(ScopeClassification::inScope());

        $product = new RevalidatedProduct(1, 'SKU-1', 'Jade Yoga Jacket', 32.0, null, 'https://store.test/sku-1', '2026-08-16T00:00:00+00:00');

        $revalidationService = $this->createMock(LiveRevalidationServiceInterface::class);
        $revalidationService->method('revalidate')->willReturn([$product]);

        $malformedResponse = new ChatResponse('Plain prose, not JSON.', [], new TokenUsage(1, 1), 'openai_compatible', 'local-model', 5);

        $callCount = 0;
        $capturedThirdCallMessages = null;
        $toolCallingChatService = $this->createMock(ToolCallingChatServiceInterface::class);
        $toolCallingChatService->method('converse')->willReturnCallback(
            function (
                int $storeId,
                ?int $customerGroupId,
                ?string $cartId,
                array $messages
            ) use (&$callCount, &$capturedThirdCallMessages, $product, $malformedResponse): ToolCallingResult {
                $callCount++;

                if ($callCount === 1) {
                    return new ToolCallingResult($malformedResponse, []);
                }

                if ($callCount === 2) {
                    // Valid JSON, but product_skus is totally empty even
                    // though the message names a real, verified product.
                    return new ToolCallingResult(
                        $this->structuredChatResponse('Here is a great jacket: the Jade Yoga Jacket.', []),
                        [$product]
                    );
                }

                $capturedThirdCallMessages = $messages;

                return new ToolCallingResult(
                    $this->structuredChatResponse(
                        'Here is a great jacket: the Jade Yoga Jacket.',
                        [['sku' => 'SKU-1', 'reason' => 'Great for yoga.']]
                    ),
                    [$product]
                );
            }
        );

        $pipeline = $this->pipeline(
            classifier: $classifier,
            toolCallingChatService: $toolCallingChatService,
            revalidationService: $revalidationService
        );

        $result = $pipeline->handle(self::STORE_ID, 'Show me a jacket.');

        self::assertSame(3, $callCount);
        self::assertFalse($result->wasShortCircuited());
        self::assertCount(1, $result->response()->products);
        self::assertSame('SKU-1', $result->response()->products[0]->product->sku);
        self::assertNotNull($capturedThirdCallMessages);
        $lastMessage = $capturedThirdCallMessages[array_key_last($capturedThirdCallMessages)];
        self::assertStringContainsString('SKU-1', $lastMessage->content);
    }

    public function testTheBonusCompletenessAttemptIsNeverConsumedByAMalformedRetry(): void
    {
        // The bonus 3rd attempt exists only for completeness — a
        // response that is malformed on every attempt must still fail
        // closed after exactly 2 tries, not 3.
        $classifier = $this->createMock(CommerceScopeClassifierInterface::class);
        $classifier->method('classify')->willReturn(ScopeClassification::inScope());

        $malformedResponse = new ChatResponse('Still plain prose.', [], new TokenUsage(1, 1), 'openai_compatible', 'local-model', 5);

        $toolCallingChatService = $this->createMock(ToolCallingChatServiceInterface::class);
        $toolCallingChatService->expects(self::exactly(2))
            ->method('converse')
            ->willReturn(new ToolCallingResult($malformedResponse, []));

        $pipeline = $this->pipeline(classifier: $classifier, toolCallingChatService: $toolCallingChatService);

        $result = $pipeline->handle(self::STORE_ID, 'Show me waterproof phones.');

        self::assertTrue($result->wasShortCircuited());
        self::assertSame('malformed_response', $result->safeResponse()->reasonCode);
    }

    public function testStillIncompleteAfterTheBonusAttemptStillUsesTheValidResponseRatherThanFallingBack(): void
    {
        // If the bonus completeness attempt also comes back incomplete,
        // the loop must stop at exactly 3 calls (never retry a 4th
        // time) and still use the best valid response available rather
        // than falling back to the generic message.
        $classifier = $this->createMock(CommerceScopeClassifierInterface::class);
        $classifier->method('classify')->willReturn(ScopeClassification::inScope());

        $product = new RevalidatedProduct(1, 'SKU-1', 'Jade Yoga Jacket', 32.0, null, 'https://store.test/sku-1', '2026-08-16T00:00:00+00:00');

        $revalidationService = $this->createMock(LiveRevalidationServiceInterface::class);
        $revalidationService->method('revalidate')->willReturn([$product]);

        $malformedResponse = new ChatResponse('Plain prose, not JSON.', [], new TokenUsage(1, 1), 'openai_compatible', 'local-model', 5);
        $emptyProductsResponse = new ToolCallingResult(
            $this->structuredChatResponse('Here is a great jacket: the Jade Yoga Jacket.', []),
            [$product]
        );

        $callCount = 0;
        $toolCallingChatService = $this->createMock(ToolCallingChatServiceInterface::class);
        $toolCallingChatService->method('converse')->willReturnCallback(
            function () use (&$callCount, $malformedResponse, $emptyProductsResponse): ToolCallingResult {
                $callCount++;

                return $callCount === 1 ? new ToolCallingResult($malformedResponse, []) : $emptyProductsResponse;
            }
        );

        $pipeline = $this->pipeline(
            classifier: $classifier,
            toolCallingChatService: $toolCallingChatService,
            revalidationService: $revalidationService
        );

        $result = $pipeline->handle(self::STORE_ID, 'Show me a jacket.');

        self::assertSame(3, $callCount);
        self::assertFalse($result->wasShortCircuited());
        self::assertSame([], $result->response()->products);
        self::assertSame('Here is a great jacket: the Jade Yoga Jacket.', $result->response()->message);
    }

    public function testStillIncompleteAfterRetryStillUsesTheValidResponseRatherThanFallingBack(): void
    {
        // A response with 1 real card is strictly better than the generic
        // fallback message with none — incompleteness is a quality gap,
        // not a safety problem, so it's never treated as fail-closed the
        // way a fabricated SKU is.
        $classifier = $this->createMock(CommerceScopeClassifierInterface::class);
        $classifier->method('classify')->willReturn(ScopeClassification::inScope());

        $product1 = new RevalidatedProduct(1, 'SKU-1', 'Jade Yoga Jacket', 32.0, null, 'https://store.test/sku-1', '2026-08-16T00:00:00+00:00');
        $product2 = new RevalidatedProduct(2, 'SKU-2', 'Montana Wind Jacket', 45.0, null, 'https://store.test/sku-2', '2026-08-16T00:00:00+00:00');

        $revalidationService = $this->createMock(LiveRevalidationServiceInterface::class);
        $revalidationService->method('revalidate')->willReturn([$product1, $product2]);

        $toolCallingChatService = $this->createMock(ToolCallingChatServiceInterface::class);
        $toolCallingChatService->expects(self::exactly(2))->method('converse')->willReturn(
            new ToolCallingResult(
                $this->structuredChatResponse(
                    'Here are two jackets: the Jade Yoga Jacket and the Montana Wind Jacket.',
                    [['sku' => 'SKU-1', 'reason' => 'Great for yoga.']]
                ),
                [$product1, $product2]
            )
        );

        $pipeline = $this->pipeline(
            classifier: $classifier,
            toolCallingChatService: $toolCallingChatService,
            revalidationService: $revalidationService
        );

        $result = $pipeline->handle(self::STORE_ID, 'Show me jackets.');

        self::assertFalse($result->wasShortCircuited());
        self::assertCount(1, $result->response()->products);
        self::assertSame('SKU-1', $result->response()->products[0]->product->sku);
    }

    public function testARegressedSecondAttemptFallsBackToTheFirstAttemptsValidResponse(): void
    {
        // A completeness retry can only ever try to make things better —
        // if the model's corrected attempt instead comes back malformed
        // or otherwise invalid, the first attempt's valid-if-incomplete
        // response is still strictly better than the generic fallback and
        // is what the customer sees.
        $classifier = $this->createMock(CommerceScopeClassifierInterface::class);
        $classifier->method('classify')->willReturn(ScopeClassification::inScope());

        $product1 = new RevalidatedProduct(1, 'SKU-1', 'Jade Yoga Jacket', 32.0, null, 'https://store.test/sku-1', '2026-08-16T00:00:00+00:00');
        $product2 = new RevalidatedProduct(2, 'SKU-2', 'Montana Wind Jacket', 45.0, null, 'https://store.test/sku-2', '2026-08-16T00:00:00+00:00');

        $revalidationService = $this->createMock(LiveRevalidationServiceInterface::class);
        $revalidationService->method('revalidate')->willReturn([$product1, $product2]);

        $callCount = 0;
        $toolCallingChatService = $this->createMock(ToolCallingChatServiceInterface::class);
        $toolCallingChatService->method('converse')->willReturnCallback(
            function () use (&$callCount, $product1, $product2): ToolCallingResult {
                $callCount++;

                if ($callCount === 1) {
                    return new ToolCallingResult(
                        $this->structuredChatResponse(
                            'Here are two jackets: the Jade Yoga Jacket and the Montana Wind Jacket.',
                            [['sku' => 'SKU-1', 'reason' => 'Great for yoga.']]
                        ),
                        [$product1, $product2]
                    );
                }

                return new ToolCallingResult(
                    new ChatResponse('not valid json at all', [], new TokenUsage(1, 1), 'openai_compatible', 'local-model', 5),
                    [$product1, $product2]
                );
            }
        );

        $pipeline = $this->pipeline(
            classifier: $classifier,
            toolCallingChatService: $toolCallingChatService,
            revalidationService: $revalidationService
        );

        $result = $pipeline->handle(self::STORE_ID, 'Show me jackets.');

        self::assertSame(2, $callCount);
        self::assertFalse($result->wasShortCircuited());
        self::assertCount(1, $result->response()->products);
        self::assertSame('SKU-1', $result->response()->products[0]->product->sku);
    }

    public function testAPriceConstraintInTheQueryAddsARealQualifyingCandidateTheModelSilentlyDropped(): void
    {
        // The exact real-world bug this reconciliation exists to fix: the
        // model correctly has access to both real, verified candidates
        // (revalidate() returns both) but only selects one into
        // product_skus, and never names the other by name in its own
        // message text either — so ProductMentionCompletenessChecker's
        // retry (a different mechanism) would never catch this. Only
        // one converse() call should ever happen; the correction is
        // deterministic, not another model round-trip.
        $classifier = $this->createMock(CommerceScopeClassifierInterface::class);
        $classifier->method('classify')->willReturn(ScopeClassification::inScope());

        $product1 = new RevalidatedProduct(1, 'SKU-1', 'Jade Yoga Jacket', 32.0, null, 'https://store.test/sku-1', '2026-08-16T00:00:00+00:00');
        $product2 = new RevalidatedProduct(2, 'SKU-2', 'Montana Wind Jacket', 45.0, null, 'https://store.test/sku-2', '2026-08-16T00:00:00+00:00');

        $revalidationService = $this->createMock(LiveRevalidationServiceInterface::class);
        $revalidationService->method('revalidate')->willReturn([$product1, $product2]);

        $toolCallingChatService = $this->createMock(ToolCallingChatServiceInterface::class);
        $toolCallingChatService->expects(self::once())
            ->method('converse')
            ->willReturn($this->toolCallingResult(
                'Here is a great option for you.',
                [['sku' => 'SKU-1', 'reason' => 'A good pick.']]
            ));

        $pipeline = $this->pipeline(
            classifier: $classifier,
            toolCallingChatService: $toolCallingChatService,
            revalidationService: $revalidationService
        );

        $result = $pipeline->handle(self::STORE_ID, 'Show me jackets under $60.');

        self::assertFalse($result->wasShortCircuited());
        $skus = array_map(static fn ($p) => $p->product->sku, $result->response()->products);
        self::assertSame(['SKU-1', 'SKU-2'], $skus);
    }

    public function testAPriceConstraintInTheQueryRemovesASelectedProductThatDoesNotActuallyQualify(): void
    {
        $classifier = $this->createMock(CommerceScopeClassifierInterface::class);
        $classifier->method('classify')->willReturn(ScopeClassification::inScope());

        $product1 = new RevalidatedProduct(1, 'SKU-1', 'Jade Yoga Jacket', 32.0, null, 'https://store.test/sku-1', '2026-08-16T00:00:00+00:00');
        $product2 = new RevalidatedProduct(2, 'SKU-3', 'Orion Fitted Jacket', 90.0, null, 'https://store.test/sku-3', '2026-08-16T00:00:00+00:00');

        $revalidationService = $this->createMock(LiveRevalidationServiceInterface::class);
        $revalidationService->method('revalidate')->willReturn([$product1, $product2]);

        $toolCallingChatService = $this->createMock(ToolCallingChatServiceInterface::class);
        $toolCallingChatService->expects(self::once())
            ->method('converse')
            ->willReturn($this->toolCallingResult(
                'Here are some options.',
                [
                    ['sku' => 'SKU-1', 'reason' => 'A good pick.'],
                    ['sku' => 'SKU-3', 'reason' => 'Another option.'],
                ]
            ));

        $pipeline = $this->pipeline(
            classifier: $classifier,
            toolCallingChatService: $toolCallingChatService,
            revalidationService: $revalidationService
        );

        $result = $pipeline->handle(self::STORE_ID, 'Show me jackets under $60.');

        self::assertFalse($result->wasShortCircuited());
        $skus = array_map(static fn ($p) => $p->product->sku, $result->response()->products);
        self::assertSame(['SKU-1'], $skus);
    }

    public function testNoPriceConstraintInTheQueryLeavesTheModelsSelectionUntouched(): void
    {
        $classifier = $this->createMock(CommerceScopeClassifierInterface::class);
        $classifier->method('classify')->willReturn(ScopeClassification::inScope());

        $product1 = new RevalidatedProduct(1, 'SKU-1', 'Jade Yoga Jacket', 32.0, null, 'https://store.test/sku-1', '2026-08-16T00:00:00+00:00');
        $product2 = new RevalidatedProduct(2, 'SKU-3', 'Orion Fitted Jacket', 90.0, null, 'https://store.test/sku-3', '2026-08-16T00:00:00+00:00');

        $revalidationService = $this->createMock(LiveRevalidationServiceInterface::class);
        $revalidationService->method('revalidate')->willReturn([$product1, $product2]);

        $toolCallingChatService = $this->createMock(ToolCallingChatServiceInterface::class);
        $toolCallingChatService->expects(self::once())
            ->method('converse')
            ->willReturn($this->toolCallingResult('Here is a great option for you.', [['sku' => 'SKU-1', 'reason' => 'A good pick.']]));

        $pipeline = $this->pipeline(
            classifier: $classifier,
            toolCallingChatService: $toolCallingChatService,
            revalidationService: $revalidationService
        );

        $result = $pipeline->handle(self::STORE_ID, 'Show me jackets.');

        self::assertFalse($result->wasShortCircuited());
        $skus = array_map(static fn ($p) => $p->product->sku, $result->response()->products);
        self::assertSame(['SKU-1'], $skus);
    }

    public function testAWeakFollowUpQueryStillSucceedsByCarryingForwardThePriorTurnsRealProducts(): void
    {
        // Live-reproduced bug: a short follow-up ("the cheaper one") is,
        // on its own, a weak retrieval query with no product-type
        // signal — this turn's own retrieval finds nothing relevant
        // (noCandidatesRetrievalService), yet the customer is clearly
        // still talking about the jogging pants from the immediately
        // preceding turn. The model selects that prior SKU by name; it
        // must not be rejected as fabricated_sku just because this
        // turn's own retrieval came up empty.
        $classifier = $this->createMock(CommerceScopeClassifierInterface::class);
        $classifier->method('classify')->willReturn(ScopeClassification::inScope());

        $priorProduct = new RevalidatedProduct(1, 'SKU-PRIOR', 'Geo Insulated Jogging Pant', 51.0, 40.8, 'https://store.test/sku-prior', '2026-08-16T00:00:00+00:00');

        $historyStore = $this->createMock(ConversationHistoryStoreInterface::class);
        $historyStore->method('recentMessages')->willReturn([]);
        $historyStore->method('recentMessagesWithResponsePayloads')->willReturn([
            new StoredConversationMessage('user', 'show me jogging pants'),
            new StoredConversationMessage('assistant', 'Here is one option.', [
                'products' => [['sku' => 'SKU-PRIOR', 'name' => 'Geo Insulated Jogging Pant']],
                'follow_up_questions' => [],
                'actions' => [],
            ]),
        ]);

        $revalidationService = $this->createMock(LiveRevalidationServiceInterface::class);
        $revalidationService->method('revalidate')->willReturnCallback(
            function (int $storeId, ?int $customerGroupId, array $skus) use ($priorProduct): array {
                return in_array('SKU-PRIOR', $skus, true) ? [$priorProduct] : [];
            }
        );

        $toolCallingChatService = $this->createMock(ToolCallingChatServiceInterface::class);
        $toolCallingChatService->expects(self::once())
            ->method('converse')
            ->willReturn($this->toolCallingResult(
                'The cheaper option is the Geo Insulated Jogging Pant.',
                [['sku' => 'SKU-PRIOR', 'reason' => 'The cheaper of the two.']]
            ));

        $pipeline = $this->pipeline(
            classifier: $classifier,
            retrievalService: $this->noCandidatesRetrievalService(),
            toolCallingChatService: $toolCallingChatService,
            revalidationService: $revalidationService,
            historyStore: $historyStore
        );

        $result = $pipeline->handle(self::STORE_ID, 'the cheaper one', null, null, 'conv-1');

        self::assertFalse($result->wasShortCircuited());
        self::assertCount(1, $result->response()->products);
        self::assertSame('SKU-PRIOR', $result->response()->products[0]->product->sku);
    }

    public function testACarriedOverSkuThatNoLongerRevalidatesIsNotAvailableToSelect(): void
    {
        // The carried-over SKU is re-checked live every time, never
        // trusted from what was persisted — a product genuinely shown
        // two turns ago may have sold out or been disabled since.
        $classifier = $this->createMock(CommerceScopeClassifierInterface::class);
        $classifier->method('classify')->willReturn(ScopeClassification::inScope());

        $historyStore = $this->createMock(ConversationHistoryStoreInterface::class);
        $historyStore->method('recentMessages')->willReturn([]);
        $historyStore->method('recentMessagesWithResponsePayloads')->willReturn([
            new StoredConversationMessage('assistant', 'Here is one option.', [
                'products' => [['sku' => 'SKU-SOLD-OUT', 'name' => 'No Longer Available']],
                'follow_up_questions' => [],
                'actions' => [],
            ]),
        ]);

        // revalidate() always returns [] here — simulates the carried-
        // over SKU failing live revalidation (e.g. now out of stock).
        $revalidationService = $this->noVerifiedProductsRevalidationService();

        $toolCallingChatService = $this->createMock(ToolCallingChatServiceInterface::class);
        $toolCallingChatService->method('converse')
            ->willReturn($this->toolCallingResult('ok', [['sku' => 'SKU-SOLD-OUT', 'reason' => 'x']]));

        $pipeline = $this->pipeline(
            classifier: $classifier,
            retrievalService: $this->noCandidatesRetrievalService(),
            toolCallingChatService: $toolCallingChatService,
            revalidationService: $revalidationService,
            historyStore: $historyStore
        );

        $result = $pipeline->handle(self::STORE_ID, 'i need it in medium', null, null, 'conv-1');

        self::assertTrue($result->wasShortCircuited());
        self::assertSame(OutputValidator::REASON_FABRICATED_SKU, $result->safeResponse()->reasonCode);
    }

    public function testNoConversationIdMeansNoCarryOverIsAttempted(): void
    {
        $classifier = $this->createMock(CommerceScopeClassifierInterface::class);
        $classifier->method('classify')->willReturn(ScopeClassification::inScope());

        $historyStore = $this->createMock(ConversationHistoryStoreInterface::class);
        $historyStore->expects(self::never())->method('recentMessagesWithResponsePayloads');

        $toolCallingChatService = $this->createMock(ToolCallingChatServiceInterface::class);
        $toolCallingChatService->method('converse')->willReturn($this->toolCallingResult('ok', []));

        $pipeline = $this->pipeline(
            classifier: $classifier,
            retrievalService: $this->noCandidatesRetrievalService(),
            toolCallingChatService: $toolCallingChatService,
            historyStore: $historyStore
        );

        // No conversationId (5th arg) passed at all.
        $pipeline->handle(self::STORE_ID, 'something');
    }

    public function testFabricatedSkuIsNeverRetried(): void
    {
        $classifier = $this->createMock(CommerceScopeClassifierInterface::class);
        $classifier->method('classify')->willReturn(ScopeClassification::inScope());

        $toolCallingChatService = $this->createMock(ToolCallingChatServiceInterface::class);
        $toolCallingChatService->expects(self::once())
            ->method('converse')
            ->willReturn($this->toolCallingResult('ok', [['sku' => 'SKU-FABRICATED', 'reason' => 'made up']]));

        $pipeline = $this->pipeline(classifier: $classifier, toolCallingChatService: $toolCallingChatService);

        $result = $pipeline->handle(self::STORE_ID, 'Show me waterproof phones.');

        self::assertTrue($result->wasShortCircuited());
        self::assertSame('fabricated_sku', $result->safeResponse()->reasonCode);
    }

    public function testInScopeMessageWithCandidatesPrependsProductContextBeforeTheUserMessage(): void
    {
        $classifier = $this->createMock(CommerceScopeClassifierInterface::class);
        $classifier->method('classify')->willReturn(ScopeClassification::inScope());

        $candidate = new SearchCandidate(1, 'SKU-1', self::STORE_ID, 'Blue Shoe', '', [], [], true, 4, 0.0, 0.0);

        $retrievalService = $this->createMock(HybridRetrievalServiceInterface::class);
        $retrievalService->expects(self::once())
            ->method('retrieve')
            ->with(self::STORE_ID, 'Show me waterproof phones.')
            ->willReturn([$candidate]);

        $rankingPipeline = $this->passthroughRankingPipeline();

        $captured = null;
        $toolCallingChatService = $this->createMock(ToolCallingChatServiceInterface::class);
        $toolCallingChatService->method('converse')->willReturnCallback(
            function (int $storeId, ?int $customerGroupId, ?string $cartId, array $messages) use (&$captured): ToolCallingResult {
                $captured = $messages;

                return $this->toolCallingResult('ok', []);
            }
        );

        $pipeline = $this->pipeline(
            classifier: $classifier,
            retrievalService: $retrievalService,
            rankingPipeline: $rankingPipeline,
            toolCallingChatService: $toolCallingChatService
        );

        $pipeline->handle(self::STORE_ID, 'Show me waterproof phones.');

        self::assertCount(3, $captured);
        self::assertSame('system', $captured[0]->role);
        self::assertSame('system', $captured[1]->role);
        self::assertStringContainsString('SKU-1', $captured[1]->content);
        self::assertSame('user', $captured[2]->role);
        self::assertSame('Show me waterproof phones.', $captured[2]->content);
    }

    public function testPriorConversationHistoryIsLoadedAndThreadedBetweenContextAndTheNewUserMessage(): void
    {
        $classifier = $this->createMock(CommerceScopeClassifierInterface::class);
        $classifier->method('classify')->willReturn(ScopeClassification::inScope());

        $candidate = new SearchCandidate(1, 'SKU-1', self::STORE_ID, 'Blue Shoe', '', [], [], true, 4, 0.0, 0.0);
        $retrievalService = $this->createMock(HybridRetrievalServiceInterface::class);
        $retrievalService->method('retrieve')->willReturn([$candidate]);

        $priorUserMessage = new ChatMessage('user', 'Do you have waterproof cases?');
        $priorAssistantMessage = new ChatMessage('assistant', 'Yes, we have a few options.');

        $historyStore = $this->createMock(ConversationHistoryStoreInterface::class);
        $historyStore->expects(self::once())
            ->method('recentMessages')
            ->with('conv-1', self::STORE_ID, 40)
            ->willReturn([$priorUserMessage, $priorAssistantMessage]);

        $captured = null;
        $toolCallingChatService = $this->createMock(ToolCallingChatServiceInterface::class);
        $toolCallingChatService->method('converse')->willReturnCallback(
            function (int $storeId, ?int $customerGroupId, ?string $cartId, array $messages) use (&$captured): ToolCallingResult {
                $captured = $messages;

                return $this->toolCallingResult('ok', []);
            }
        );

        $pipeline = $this->pipeline(
            classifier: $classifier,
            retrievalService: $retrievalService,
            rankingPipeline: $this->passthroughRankingPipeline(),
            toolCallingChatService: $toolCallingChatService,
            historyStore: $historyStore
        );

        $pipeline->handle(self::STORE_ID, 'What colors do they come in?', null, null, 'conv-1');

        self::assertCount(5, $captured);
        self::assertSame('system', $captured[0]->role);
        self::assertSame('system', $captured[1]->role);
        self::assertSame($priorUserMessage, $captured[2]);
        self::assertSame($priorAssistantMessage, $captured[3]);
        self::assertSame('user', $captured[4]->role);
        self::assertSame('What colors do they come in?', $captured[4]->content);
    }

    public function testNullConversationIdNeverTouchesTheHistoryStore(): void
    {
        $classifier = $this->createMock(CommerceScopeClassifierInterface::class);
        $classifier->method('classify')->willReturn(ScopeClassification::inScope());

        $historyStore = $this->createMock(ConversationHistoryStoreInterface::class);
        $historyStore->expects(self::never())->method('recentMessages');
        $historyStore->expects(self::never())->method('appendTurn');

        $toolCallingChatService = $this->createMock(ToolCallingChatServiceInterface::class);
        $toolCallingChatService->method('converse')->willReturn($this->toolCallingResult('ok', []));

        $pipeline = $this->pipeline(
            classifier: $classifier,
            toolCallingChatService: $toolCallingChatService,
            historyStore: $historyStore
        );

        $pipeline->handle(self::STORE_ID, 'Show me waterproof phones.');
    }

    public function testValidatedResponsePersistsTheUserMessageToolRoundTripAndFinalAssistantMessage(): void
    {
        $classifier = $this->createMock(CommerceScopeClassifierInterface::class);
        $classifier->method('classify')->willReturn(ScopeClassification::inScope());

        $toolCallMessage = new ChatMessage('assistant', '', null, [new ToolCall('call_1', 'check_price', ['skus' => ['SKU-1']])]);
        $toolResultMessage = new ChatMessage('tool', '{"prices":[]}', 'call_1');

        $toolCallingChatService = $this->createMock(ToolCallingChatServiceInterface::class);
        $toolCallingChatService->method('converse')->willReturn(
            new ToolCallingResult(
                $this->structuredChatResponse('Here is a great option.', []),
                [],
                [$toolCallMessage, $toolResultMessage]
            )
        );

        $historyStore = $this->createMock(ConversationHistoryStoreInterface::class);
        $captured = null;
        $historyStore->expects(self::once())
            ->method('appendTurn')
            ->with('conv-1', self::STORE_ID, self::anything(), 40)
            ->willReturnCallback(function (string $conversationId, int $storeId, array $messages) use (&$captured): void {
                $captured = $messages;
            });

        $pipeline = $this->pipeline(
            classifier: $classifier,
            toolCallingChatService: $toolCallingChatService,
            historyStore: $historyStore
        );

        $pipeline->handle(self::STORE_ID, 'Show me waterproof phones.', null, null, 'conv-1');

        self::assertCount(4, $captured);
        self::assertSame('user', $captured[0]->role);
        self::assertSame('Show me waterproof phones.', $captured[0]->content);
        self::assertSame($toolCallMessage, $captured[1]);
        self::assertSame($toolResultMessage, $captured[2]);
        self::assertSame('assistant', $captured[3]->role);
        self::assertSame('Here is a great option.', $captured[3]->content);
    }

    public function testPersistsTheResponseDisplayPayloadAlongsideTheFinalMessage(): void
    {
        $classifier = $this->createMock(CommerceScopeClassifierInterface::class);
        $classifier->method('classify')->willReturn(ScopeClassification::inScope());

        $verified = new RevalidatedProduct(1, 'SKU-1', 'Blue Shoe', 49.99, null, 'https://store.test/blue-shoe', '2026-08-16T00:00:00+00:00');
        $revalidationService = $this->createMock(LiveRevalidationServiceInterface::class);
        $revalidationService->method('revalidate')->willReturn([$verified]);

        $toolCallingChatService = $this->createMock(ToolCallingChatServiceInterface::class);
        $toolCallingChatService->method('converse')->willReturn(
            $this->toolCallingResult('Here is a great option.', [['sku' => 'SKU-1', 'reason' => 'Matches your search.']])
        );

        $historyStore = $this->createMock(ConversationHistoryStoreInterface::class);
        $capturedPayload = 'not captured';
        $historyStore->expects(self::once())
            ->method('appendTurn')
            ->willReturnCallback(
                function (string $conversationId, int $storeId, array $messages, int $maxMessages, ?array $payload) use (&$capturedPayload): void {
                    $capturedPayload = $payload;
                }
            );

        $pipeline = $this->pipeline(
            classifier: $classifier,
            toolCallingChatService: $toolCallingChatService,
            revalidationService: $revalidationService,
            historyStore: $historyStore
        );

        $pipeline->handle(self::STORE_ID, 'Show me waterproof phones.', null, null, 'conv-1');

        self::assertIsArray($capturedPayload);
        self::assertSame('SKU-1', $capturedPayload['products'][0]['sku']);
        self::assertSame('Matches your search.', $capturedPayload['products'][0]['reason']);
        self::assertSame([], $capturedPayload['follow_up_questions']);
        self::assertSame([], $capturedPayload['actions']);
    }

    public function testShortCircuitedTurnsAreNeverPersisted(): void
    {
        // Covers the fabricated-SKU rejection path specifically — the
        // response OutputValidator rejects must never be taught back to a
        // future turn as if it were legitimate history.
        $revalidationService = $this->createMock(LiveRevalidationServiceInterface::class);
        $revalidationService->method('revalidate')->willReturn([]);

        $classifier = $this->createMock(CommerceScopeClassifierInterface::class);
        $classifier->method('classify')->willReturn(ScopeClassification::inScope());

        $toolCallingChatService = $this->createMock(ToolCallingChatServiceInterface::class);
        $toolCallingChatService->method('converse')->willReturn(
            $this->toolCallingResult('Here is a great option.', [['sku' => 'SKU-FABRICATED', 'reason' => 'made up']])
        );

        $historyStore = $this->createMock(ConversationHistoryStoreInterface::class);
        $historyStore->expects(self::never())->method('appendTurn');

        $pipeline = $this->pipeline(
            classifier: $classifier,
            revalidationService: $revalidationService,
            toolCallingChatService: $toolCallingChatService,
            historyStore: $historyStore
        );

        $result = $pipeline->handle(self::STORE_ID, 'Show me waterproof phones.', null, null, 'conv-1');

        self::assertTrue($result->wasShortCircuited());
    }

    public function testRevalidatesRankedCandidateSkusBeforeCallingToolCallingChatService(): void
    {
        $classifier = $this->createMock(CommerceScopeClassifierInterface::class);
        $classifier->method('classify')->willReturn(ScopeClassification::inScope());

        $candidate = new SearchCandidate(1, 'SKU-1', self::STORE_ID, 'Blue Shoe', '', [], [], true, 4, 0.0, 0.0);
        $retrievalService = $this->createMock(HybridRetrievalServiceInterface::class);
        $retrievalService->method('retrieve')->willReturn([$candidate]);

        $callOrder = [];
        $revalidationService = $this->createMock(LiveRevalidationServiceInterface::class);
        $revalidationService->expects(self::once())
            ->method('revalidate')
            ->with(self::STORE_ID, null, ['SKU-1'])
            ->willReturnCallback(function () use (&$callOrder): array {
                $callOrder[] = 'revalidate';
                return [];
            });

        $toolCallingChatService = $this->createMock(ToolCallingChatServiceInterface::class);
        $toolCallingChatService->method('converse')->willReturnCallback(function () use (&$callOrder): ToolCallingResult {
            $callOrder[] = 'converse';
            return $this->toolCallingResult('ok', []);
        });

        $pipeline = $this->pipeline(
            classifier: $classifier,
            retrievalService: $retrievalService,
            rankingPipeline: $this->passthroughRankingPipeline(),
            toolCallingChatService: $toolCallingChatService,
            revalidationService: $revalidationService
        );

        $pipeline->handle(self::STORE_ID, 'Show me waterproof phones.');

        self::assertSame(['revalidate', 'converse'], $callOrder);
    }

    public function testCustomerGroupIdIsPassedThroughToRevalidationAndToolCallingChatService(): void
    {
        $classifier = $this->createMock(CommerceScopeClassifierInterface::class);
        $classifier->method('classify')->willReturn(ScopeClassification::inScope());

        $revalidationService = $this->createMock(LiveRevalidationServiceInterface::class);
        $revalidationService->expects(self::once())
            ->method('revalidate')
            ->with(self::STORE_ID, 42, [])
            ->willReturn([]);

        $toolCallingChatService = $this->createMock(ToolCallingChatServiceInterface::class);
        $toolCallingChatService->expects(self::once())
            ->method('converse')
            ->with(self::STORE_ID, 42, self::anything(), self::anything(), self::anything())
            ->willReturn($this->toolCallingResult('ok', []));

        $pipeline = $this->pipeline(
            classifier: $classifier,
            revalidationService: $revalidationService,
            toolCallingChatService: $toolCallingChatService
        );

        $pipeline->handle(self::STORE_ID, 'Show me waterproof phones.', 42);
    }

    public function testValidLlmResponseReferencingAVerifiedSkuProducesTheProduct(): void
    {
        $classifier = $this->createMock(CommerceScopeClassifierInterface::class);
        $classifier->method('classify')->willReturn(ScopeClassification::inScope());

        $verified = new RevalidatedProduct(1, 'SKU-1', 'Blue Shoe', 49.99, null, 'https://store.test/blue-shoe', '2026-08-16T00:00:00+00:00');
        $revalidationService = $this->createMock(LiveRevalidationServiceInterface::class);
        $revalidationService->method('revalidate')->willReturn([$verified]);

        $toolCallingChatService = $this->createMock(ToolCallingChatServiceInterface::class);
        $toolCallingChatService->method('converse')->willReturn(
            $this->toolCallingResult('Here is a great option.', [['sku' => 'SKU-1', 'reason' => 'Matches waterproof requirement.']])
        );

        $pipeline = $this->pipeline(
            classifier: $classifier,
            revalidationService: $revalidationService,
            toolCallingChatService: $toolCallingChatService
        );

        $result = $pipeline->handle(self::STORE_ID, 'Show me waterproof phones.');

        self::assertFalse($result->wasShortCircuited());
        self::assertCount(1, $result->response()->products);
        self::assertSame('SKU-1', $result->response()->products[0]->product->sku);
        self::assertSame('Matches waterproof requirement.', $result->response()->products[0]->reason);
        self::assertSame('organic', $result->response()->products[0]->recommendationType);
        self::assertSame(49.99, $result->response()->products[0]->product->price);
    }

    public function testToolVerifiedProductNotInRetrievalSetIsAcceptedByOutputValidator(): void
    {
        // The key new behavior this task adds: a SKU a tool looked up mid-
        // conversation (never part of the original retrieval candidates)
        // must still be accepted when the final answer references it —
        // otherwise get_product_details/check_price/etc. would be useless
        // for anything outside what retrieval happened to surface.
        $classifier = $this->createMock(CommerceScopeClassifierInterface::class);
        $classifier->method('classify')->willReturn(ScopeClassification::inScope());

        // Retrieval/up-front revalidation surfaces nothing.
        $revalidationService = $this->createMock(LiveRevalidationServiceInterface::class);
        $revalidationService->method('revalidate')->willReturn([]);

        $toolVerified = new RevalidatedProduct(2, 'SKU-TOOL', 'Red Hat', 19.99, null, 'https://store.test/red-hat', '2026-08-16T00:00:00+00:00');
        $toolCallingChatService = $this->createMock(ToolCallingChatServiceInterface::class);
        $toolCallingChatService->method('converse')->willReturn(
            new ToolCallingResult(
                $this->structuredChatResponse('Here it is.', [['sku' => 'SKU-TOOL', 'reason' => 'Exactly what you asked about.']]),
                [$toolVerified]
            )
        );

        $pipeline = $this->pipeline(
            classifier: $classifier,
            revalidationService: $revalidationService,
            toolCallingChatService: $toolCallingChatService
        );

        $result = $pipeline->handle(self::STORE_ID, 'Tell me about SKU-TOOL.');

        self::assertFalse($result->wasShortCircuited());
        self::assertCount(1, $result->response()->products);
        self::assertSame('SKU-TOOL', $result->response()->products[0]->product->sku);
    }

    public function testFabricatedSkuInLlmResponseTriggersSafeFallbackNotTheContract(): void
    {
        // Revalidation found nothing (or found something else) — the LLM
        // mentions a SKU that was never verified by retrieval or a tool.
        $revalidationService = $this->createMock(LiveRevalidationServiceInterface::class);
        $revalidationService->method('revalidate')->willReturn([]);

        $classifier = $this->createMock(CommerceScopeClassifierInterface::class);
        $classifier->method('classify')->willReturn(ScopeClassification::inScope());

        $toolCallingChatService = $this->createMock(ToolCallingChatServiceInterface::class);
        $toolCallingChatService->method('converse')->willReturn(
            $this->toolCallingResult('Here is a great option.', [['sku' => 'SKU-FABRICATED', 'reason' => 'made up']])
        );

        $pipeline = $this->pipeline(
            classifier: $classifier,
            revalidationService: $revalidationService,
            toolCallingChatService: $toolCallingChatService
        );

        $result = $pipeline->handle(self::STORE_ID, 'Show me waterproof phones.');

        self::assertTrue($result->wasShortCircuited());
        self::assertSame(OutputValidator::REASON_FABRICATED_SKU, $result->safeResponse()->reasonCode);
        self::assertSame(self::OUT_OF_SCOPE_MESSAGE, $result->safeResponse()->message);
    }

    public function testMalformedLlmResponseTriggersSafeFallback(): void
    {
        $classifier = $this->createMock(CommerceScopeClassifierInterface::class);
        $classifier->method('classify')->willReturn(ScopeClassification::inScope());

        $toolCallingChatService = $this->createMock(ToolCallingChatServiceInterface::class);
        $toolCallingChatService->method('converse')->willReturn(
            new ToolCallingResult(
                new ChatResponse('not valid json at all', [], new TokenUsage(0, 0), 'openai', 'gpt-4o-mini', 1),
                []
            )
        );

        $pipeline = $this->pipeline(classifier: $classifier, toolCallingChatService: $toolCallingChatService);

        $result = $pipeline->handle(self::STORE_ID, 'Show me waterproof phones.');

        self::assertTrue($result->wasShortCircuited());
        self::assertSame(OutputValidator::REASON_MALFORMED_RESPONSE, $result->safeResponse()->reasonCode);
    }

    public function testToolCallingChatServiceFailureFallsBackToSafeResponseInsteadOfPropagating(): void
    {
        // ToolCallingChatService's own chat() calls go through
        // ChatGenerationServiceInterface, which in production resolves to
        // FallbackChatGenerationService (retry + circuit breaker +
        // fallback provider already exhausted internally before it ever
        // throws). This test proves the pipeline itself never lets that
        // final exception escape uncaught, whatever produced it.
        $classifier = $this->createMock(CommerceScopeClassifierInterface::class);
        $classifier->method('classify')->willReturn(ScopeClassification::inScope());

        $toolCallingChatService = $this->createMock(ToolCallingChatServiceInterface::class);
        $toolCallingChatService->method('converse')->willThrowException(
            new ProviderUnavailableException(new Phrase('The chat provider is temporarily unavailable.'))
        );

        $pipeline = $this->pipeline(classifier: $classifier, toolCallingChatService: $toolCallingChatService);

        $result = $pipeline->handle(self::STORE_ID, 'Show me waterproof phones.');

        self::assertTrue($result->wasShortCircuited());
        self::assertSame(ChatEntryPipeline::REASON_ASSISTANT_UNAVAILABLE, $result->safeResponse()->reasonCode);
        self::assertSame(self::OUT_OF_SCOPE_MESSAGE, $result->safeResponse()->message);
    }

    public function testRetrievalBackendFailureFallsBackToSafeResponseInsteadOfPropagating(): void
    {
        $classifier = $this->createMock(CommerceScopeClassifierInterface::class);
        $classifier->method('classify')->willReturn(ScopeClassification::inScope());

        $retrievalService = $this->createMock(HybridRetrievalServiceInterface::class);
        $retrievalService->method('retrieve')->willThrowException(new SearchQueryFailedException());

        $toolCallingChatService = $this->createMock(ToolCallingChatServiceInterface::class);
        $toolCallingChatService->expects(self::never())->method('converse');

        $pipeline = $this->pipeline(classifier: $classifier, retrievalService: $retrievalService, toolCallingChatService: $toolCallingChatService);

        $result = $pipeline->handle(self::STORE_ID, 'Show me waterproof phones.');

        self::assertTrue($result->wasShortCircuited());
        self::assertSame(ChatEntryPipeline::REASON_RETRIEVAL_UNAVAILABLE, $result->safeResponse()->reasonCode);
        self::assertSame(self::OUT_OF_SCOPE_MESSAGE, $result->safeResponse()->message);
    }

    public function testEmbeddingProviderFailureDuringRetrievalFallsBackToSafeResponseInsteadOfPropagating(): void
    {
        // The query-embedding step inside HybridRetrievalService calls
        // EmbeddingGenerationServiceInterface, which throws the same
        // ProviderException hierarchy chat generation does (both
        // EmbeddingConfigurationException/EmbeddingResponseException
        // extend it) — proving the catch here covers that path too, not
        // just the OpenSearch-client-side ProductIndexingException one.
        $classifier = $this->createMock(CommerceScopeClassifierInterface::class);
        $classifier->method('classify')->willReturn(ScopeClassification::inScope());

        $retrievalService = $this->createMock(HybridRetrievalServiceInterface::class);
        $retrievalService->method('retrieve')->willThrowException(
            new EmbeddingConfigurationException(new Phrase('Embedding provider is not configured.'))
        );

        $pipeline = $this->pipeline(classifier: $classifier, retrievalService: $retrievalService);

        $result = $pipeline->handle(self::STORE_ID, 'Show me waterproof phones.');

        self::assertTrue($result->wasShortCircuited());
        self::assertSame(ChatEntryPipeline::REASON_RETRIEVAL_UNAVAILABLE, $result->safeResponse()->reasonCode);
    }

    public function testRetrievalFailureIsLoggedWithTheSanitizedErrorCodeButNeverPropagatesTheRawException(): void
    {
        $classifier = $this->createMock(CommerceScopeClassifierInterface::class);
        $classifier->method('classify')->willReturn(ScopeClassification::inScope());

        $retrievalService = $this->createMock(HybridRetrievalServiceInterface::class);
        $retrievalService->method('retrieve')->willThrowException(new SearchQueryFailedException());

        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())
            ->method('error')
            ->with(
                self::stringContains('retrieval'),
                self::callback(static function (array $context): bool {
                    return $context['store_id'] === self::STORE_ID
                        && $context['error_code'] === 'search_query_failed';
                })
            );

        $pipeline = $this->pipeline(classifier: $classifier, retrievalService: $retrievalService, logger: $logger);

        $pipeline->handle(self::STORE_ID, 'Show me waterproof phones.');
    }

    public function testAssistantDisabledShortCircuitsBeforeAnyDownstreamStep(): void
    {
        $classifier = $this->createMock(CommerceScopeClassifierInterface::class);
        $classifier->expects(self::never())->method('classify');

        $retrievalService = $this->createMock(HybridRetrievalServiceInterface::class);
        $retrievalService->expects(self::never())->method('retrieve');

        $revalidationService = $this->createMock(LiveRevalidationServiceInterface::class);
        $revalidationService->expects(self::never())->method('revalidate');

        $toolCallingChatService = $this->createMock(ToolCallingChatServiceInterface::class);
        $toolCallingChatService->expects(self::never())->method('converse');

        $pipeline = $this->pipeline(
            classifier: $classifier,
            retrievalService: $retrievalService,
            revalidationService: $revalidationService,
            toolCallingChatService: $toolCallingChatService,
            assistantEnabled: false
        );

        $result = $pipeline->handle(self::STORE_ID, 'Show me waterproof phones.');

        self::assertTrue($result->wasShortCircuited());
        self::assertSame(ChatEntryPipeline::REASON_ASSISTANT_DISABLED, $result->safeResponse()->reasonCode);
    }

    public function testInvalidInputFailsBeforeAnyDownstreamStep(): void
    {
        $classifier = $this->createMock(CommerceScopeClassifierInterface::class);
        $classifier->expects(self::never())->method('classify');

        $revalidationService = $this->createMock(LiveRevalidationServiceInterface::class);
        $revalidationService->expects(self::never())->method('revalidate');

        $toolCallingChatService = $this->createMock(ToolCallingChatServiceInterface::class);
        $toolCallingChatService->expects(self::never())->method('converse');

        $pipeline = $this->pipeline(
            classifier: $classifier,
            revalidationService: $revalidationService,
            toolCallingChatService: $toolCallingChatService
        );

        $this->expectException(ChatInputException::class);
        $pipeline->handle(self::STORE_ID, '   ');
    }

    public function testInactiveStoreFailsClosedBeforeAnyConfigRead(): void
    {
        $storeScope = $this->createMock(StoreScopeProviderInterface::class);
        $storeScope->method('requireActive')
            ->with(self::STORE_ID)
            ->willThrowException(new StoreScopeException(new Phrase('Store is not active.')));

        $reader = $this->createMock(ConfigurationReaderInterface::class);
        $reader->expects(self::never())->method('readGuardrails');
        $reader->expects(self::never())->method('readGeneral');

        $pipeline = $this->pipeline(storeScope: $storeScope, reader: $reader);

        $this->expectException(StoreScopeException::class);
        $pipeline->handle(self::STORE_ID, 'Show me waterproof phones.');
    }

    /**
     * @param list<array{sku: string, reason: string}> $productSkus
     */
    private function structuredChatResponse(string $message, array $productSkus): ChatResponse
    {
        $text = json_encode([
            'message' => $message,
            'product_skus' => $productSkus,
            'follow_up_questions' => [],
            'actions' => [],
        ], JSON_THROW_ON_ERROR);

        return new ChatResponse($text, [], new TokenUsage(1, 1), 'openai', 'gpt-4o-mini', 5);
    }

    /**
     * @param list<array{sku: string, reason: string}> $productSkus
     */
    private function toolCallingResult(string $message, array $productSkus): ToolCallingResult
    {
        return new ToolCallingResult($this->structuredChatResponse($message, $productSkus), []);
    }

    private function pipeline(
        ?StoreScopeProviderInterface $storeScope = null,
        ?ConfigurationReaderInterface $reader = null,
        ?CommerceScopeClassifierInterface $classifier = null,
        ?HybridRetrievalServiceInterface $retrievalService = null,
        ?RankingPipelineInterface $rankingPipeline = null,
        ?ToolCallingChatServiceInterface $toolCallingChatService = null,
        ?LiveRevalidationServiceInterface $revalidationService = null,
        ?ConversationHistoryStoreInterface $historyStore = null,
        ?LoggerInterface $logger = null,
        bool $assistantEnabled = true
    ): ChatEntryPipeline {
        $reader ??= $this->reader($assistantEnabled);
        $retrievalService ??= $this->noCandidatesRetrievalService();
        $rankingPipeline ??= $this->passthroughRankingPipeline();
        $revalidationService ??= $this->noVerifiedProductsRevalidationService();
        $historyStore ??= $this->noHistoryStore();
        $logger ??= $this->createMock(LoggerInterface::class);

        return new ChatEntryPipeline(
            $storeScope ?? $this->activeStoreScope(),
            $reader,
            new ChatInputValidator(),
            $classifier ?? $this->createMock(CommerceScopeClassifierInterface::class),
            new ProductContextResolver($reader, $retrievalService, $rankingPipeline),
            new ProductContextFormatter(),
            new ResponseContractFormatter(),
            $toolCallingChatService ?? $this->createMock(ToolCallingChatServiceInterface::class),
            $revalidationService,
            new OutputValidator(new LlmResponseParser()),
            $historyStore,
            new ChatResponseSerializer(),
            new ProductMentionCompletenessChecker(),
            new PriceConstraintDetector(),
            new PriceConstraintReconciler(),
            new PriorTurnProductCarryOver($historyStore),
            new ChatDebugLogger($this->createMock(LoggerInterface::class)),
            $logger
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

    private function reader(bool $assistantEnabled): ConfigurationReaderInterface
    {
        $guardrails = $this->createMock(GuardrailConfigInterface::class);
        $guardrails->method('maxInputCharacters')->willReturn(1000);
        $guardrails->method('outOfScopeMessage')->willReturn(self::OUT_OF_SCOPE_MESSAGE);

        $general = $this->createMock(GeneralConfigInterface::class);
        $general->method('isEnabled')->willReturn($assistantEnabled);
        $general->method('maxConversationMessages')->willReturn(40);

        $retrieval = $this->createMock(RetrievalConfigInterface::class);
        $retrieval->method('isRerankerEnabled')->willReturn(false);

        $reader = $this->createMock(ConfigurationReaderInterface::class);
        $reader->method('readGuardrails')->with(self::STORE_ID)->willReturn($guardrails);
        $reader->method('readGeneral')->with(self::STORE_ID)->willReturn($general);
        $reader->method('readRetrieval')->with(self::STORE_ID)->willReturn($retrieval);

        return $reader;
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

    private function passthroughRankingPipeline(): RankingPipelineInterface
    {
        $rankingPipeline = $this->createMock(RankingPipelineInterface::class);
        $rankingPipeline->method('rank')->willReturnCallback(
            static fn (SearchContext $context, array $candidates): array => $candidates
        );

        return $rankingPipeline;
    }

    private function noHistoryStore(): ConversationHistoryStoreInterface
    {
        $historyStore = $this->createMock(ConversationHistoryStoreInterface::class);
        $historyStore->method('recentMessages')->willReturn([]);

        return $historyStore;
    }
}
