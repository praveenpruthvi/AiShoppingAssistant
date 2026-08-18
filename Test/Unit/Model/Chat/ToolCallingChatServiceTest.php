<?php

declare(strict_types=1);

namespace Aavirbhava\AiShoppingAssistant\Test\Unit\Model\Chat;

use Aavirbhava\AiShoppingAssistant\Api\Chat\ChatGenerationServiceInterface;
use Aavirbhava\AiShoppingAssistant\Api\Chat\ToolCallingDebugCollectorInterface;
use Aavirbhava\AiShoppingAssistant\Api\Config\ConfigurationReaderInterface;
use Aavirbhava\AiShoppingAssistant\Api\Config\GuardrailConfigInterface;
use Aavirbhava\AiShoppingAssistant\Api\Tool\CommerceToolInterface;
use Aavirbhava\AiShoppingAssistant\Model\Chat\ToolCallingChatService;
use Aavirbhava\AiShoppingAssistant\Model\Dto\ChatMessage;
use Aavirbhava\AiShoppingAssistant\Model\Dto\ChatResponse;
use Aavirbhava\AiShoppingAssistant\Model\Dto\TokenUsage;
use Aavirbhava\AiShoppingAssistant\Model\Dto\ToolCall;
use Aavirbhava\AiShoppingAssistant\Model\Revalidation\RevalidatedProduct;
use Aavirbhava\AiShoppingAssistant\Model\Tool\CommerceToolRegistry;
use Aavirbhava\AiShoppingAssistant\Model\Tool\Exception\ToolAuthorizationException;
use Aavirbhava\AiShoppingAssistant\Model\Tool\ToolContext;
use Aavirbhava\AiShoppingAssistant\Model\Tool\ToolResult;
use Magento\Framework\Phrase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Covers the tool-call round-trip itself: offering only authorized tools,
 * executing a requested tool and feeding its result back before calling
 * again, failing closed (without executing anything) on an unrecognized or
 * unauthorized tool name, tolerating a tool that throws mid-execute, the
 * round-cap forcing a final tools-less call, and RevalidatedProducts
 * accumulating across every round and every tool call within a round.
 */
#[CoversClass(ToolCallingChatService::class)]
final class ToolCallingChatServiceTest extends TestCase
{
    private const STORE_ID = 5;

    public function testNoToolCallsReturnsTheFirstResponseImmediately(): void
    {
        $chatService = $this->createMock(ChatGenerationServiceInterface::class);
        $chatService->expects(self::once())->method('chat')->willReturn($this->textResponse('Here you go.'));

        $service = $this->service(chatService: $chatService);

        $result = $service->converse(self::STORE_ID, null, null, [new ChatMessage('user', 'hi')], null);

        self::assertSame('Here you go.', $result->response->text);
        self::assertSame([], $result->verifiedProducts);
        self::assertSame([], $result->toolRoundTripMessages);
    }

    public function testCartIdIsThreadedIntoTheToolContext(): void
    {
        $capturedContext = null;
        $tool = $this->createMock(CommerceToolInterface::class);
        $tool->method('name')->willReturn('get_cart');
        $tool->method('description')->willReturn('desc');
        $tool->method('inputSchema')->willReturn(['type' => 'object']);
        $tool->method('authorize')->willReturnCallback(function ($context) use (&$capturedContext): void {
            $capturedContext = $context;
        });

        $chatService = $this->createMock(ChatGenerationServiceInterface::class);
        $chatService->method('chat')->willReturn($this->textResponse('ok'));

        $service = $this->service(
            chatService: $chatService,
            registry: new CommerceToolRegistry(['get_cart' => $tool])
        );

        $service->converse(self::STORE_ID, null, 'masked-cart-abc', [new ChatMessage('user', 'hi')], null);

        self::assertNotNull($capturedContext);
        self::assertSame('masked-cart-abc', $capturedContext->cartId);
    }

    public function testOnlyAuthorizedToolsAreOffered(): void
    {
        $allowed = $this->tool('search_products');
        $blocked = $this->tool('check_price', authorized: false);

        $captured = null;
        $chatService = $this->createMock(ChatGenerationServiceInterface::class);
        $chatService->method('chat')->willReturnCallback(
            function (int $storeId, array $messages, array $tools) use (&$captured): ChatResponse {
                $captured = $tools;

                return $this->textResponse('ok');
            }
        );

        $service = $this->service(
            chatService: $chatService,
            registry: new CommerceToolRegistry(['search_products' => $allowed, 'check_price' => $blocked])
        );

        $service->converse(self::STORE_ID, null, null, [new ChatMessage('user', 'hi')], null);

        self::assertCount(1, $captured);
        self::assertSame('search_products', $captured[0]['name']);
    }

    public function testRequestedToolIsExecutedAndItsResultFedBackBeforeTheFinalCall(): void
    {
        $verified = new RevalidatedProduct(1, 'SKU-1', 'Blue Shoe', 49.99, null, 'https://store.test/blue-shoe', '2026-08-16T00:00:00+00:00');

        $tool = $this->createMock(CommerceToolInterface::class);
        $tool->method('name')->willReturn('get_product_details');
        $tool->method('description')->willReturn('desc');
        $tool->method('inputSchema')->willReturn(['type' => 'object']);
        $tool->expects(self::once())->method('execute')->willReturn(new ToolResult(['found' => true], [$verified]));

        $toolCall = new ToolCall('call_1', 'get_product_details', ['sku' => 'SKU-1']);

        $conversations = [];
        $chatService = $this->createMock(ChatGenerationServiceInterface::class);
        $chatService->method('chat')->willReturnCallback(
            function (int $storeId, array $messages) use (&$conversations, $toolCall): ChatResponse {
                $conversations[] = $messages;

                return count($conversations) === 1
                    ? new ChatResponse('', [$toolCall], new TokenUsage(1, 1), 'openai', 'gpt-4o-mini', 5)
                    : $this->textResponse('Here it is.');
            }
        );

        $service = $this->service(
            chatService: $chatService,
            registry: new CommerceToolRegistry(['get_product_details' => $tool])
        );

        $result = $service->converse(self::STORE_ID, null, null, [new ChatMessage('user', 'Tell me about SKU-1.')], null);

        self::assertSame('Here it is.', $result->response->text);
        self::assertSame([$verified], $result->verifiedProducts);

        // Second call's conversation must include the assistant tool-call
        // message and the tool-result message appended after round one.
        self::assertCount(3, $conversations[1]);
        self::assertSame('assistant', $conversations[1][1]->role);
        self::assertSame([$toolCall], $conversations[1][1]->toolCalls);
        self::assertSame('tool', $conversations[1][2]->role);
        self::assertSame('call_1', $conversations[1][2]->toolCallId);
        self::assertSame(['found' => true], json_decode($conversations[1][2]->content, true));

        // toolRoundTripMessages (Task 8, persisted by ChatEntryPipeline)
        // carries exactly the assistant tool-call + tool-result messages
        // — not the final text-only response, which the caller already
        // has via $result->response.
        self::assertCount(2, $result->toolRoundTripMessages);
        self::assertSame($conversations[1][1], $result->toolRoundTripMessages[0]);
        self::assertSame($conversations[1][2], $result->toolRoundTripMessages[1]);
    }

    public function testUnrecognizedToolNameFailsClosedWithoutExecutingAnything(): void
    {
        $tool = $this->createMock(CommerceToolInterface::class);
        $tool->method('name')->willReturn('get_product_details');
        $tool->method('description')->willReturn('desc');
        $tool->method('inputSchema')->willReturn(['type' => 'object']);
        $tool->expects(self::never())->method('execute');

        $unknownCall = new ToolCall('call_1', 'delete_all_products', []);

        $calls = 0;
        $chatService = $this->createMock(ChatGenerationServiceInterface::class);
        $chatService->method('chat')->willReturnCallback(
            function () use (&$calls, $unknownCall): ChatResponse {
                $calls++;

                return $calls === 1
                    ? new ChatResponse('', [$unknownCall], new TokenUsage(1, 1), 'openai', 'gpt-4o-mini', 5)
                    : $this->textResponse('ok');
            }
        );

        $service = $this->service(
            chatService: $chatService,
            registry: new CommerceToolRegistry(['get_product_details' => $tool])
        );

        $result = $service->converse(self::STORE_ID, null, null, [new ChatMessage('user', 'hi')], null);

        self::assertSame('ok', $result->response->text);
        self::assertSame([], $result->verifiedProducts);
    }

    public function testUnauthorizedToolCallFailsClosedWithoutExecuting(): void
    {
        $tool = $this->tool('get_product_details', authorized: false);
        $tool->expects(self::never())->method('execute');

        $toolCall = new ToolCall('call_1', 'get_product_details', ['sku' => 'SKU-1']);

        $calls = 0;
        $chatService = $this->createMock(ChatGenerationServiceInterface::class);
        $chatService->method('chat')->willReturnCallback(
            function () use (&$calls, $toolCall): ChatResponse {
                $calls++;

                return $calls === 1
                    ? new ChatResponse('', [$toolCall], new TokenUsage(1, 1), 'openai', 'gpt-4o-mini', 5)
                    : $this->textResponse('ok');
            }
        );

        $service = $this->service(
            chatService: $chatService,
            registry: new CommerceToolRegistry(['get_product_details' => $tool])
        );

        $result = $service->converse(self::STORE_ID, null, null, [new ChatMessage('user', 'hi')], null);

        self::assertSame('ok', $result->response->text);
    }

    public function testToolExecutionExceptionDoesNotCrashTheTurn(): void
    {
        $tool = $this->createMock(CommerceToolInterface::class);
        $tool->method('name')->willReturn('get_product_details');
        $tool->method('description')->willReturn('desc');
        $tool->method('inputSchema')->willReturn(['type' => 'object']);
        $tool->method('execute')->willThrowException(new \RuntimeException('boom'));

        $toolCall = new ToolCall('call_1', 'get_product_details', ['sku' => 'SKU-1']);

        $conversations = [];
        $calls = 0;
        $chatService = $this->createMock(ChatGenerationServiceInterface::class);
        $chatService->method('chat')->willReturnCallback(
            function (int $storeId, array $messages) use (&$calls, &$conversations, $toolCall): ChatResponse {
                $calls++;
                $conversations[] = $messages;

                return $calls === 1
                    ? new ChatResponse('', [$toolCall], new TokenUsage(1, 1), 'openai', 'gpt-4o-mini', 5)
                    : $this->textResponse('ok');
            }
        );

        $service = $this->service(
            chatService: $chatService,
            registry: new CommerceToolRegistry(['get_product_details' => $tool])
        );

        $result = $service->converse(self::STORE_ID, null, null, [new ChatMessage('user', 'hi')], null);

        self::assertSame('ok', $result->response->text);
        self::assertSame(['error' => 'tool_execution_failed'], json_decode($conversations[1][2]->content, true));
    }

    public function testRoundCapForcesAFinalToolLessCallWhenTheModelKeepsRequestingTools(): void
    {
        $tool = $this->tool('get_product_details');
        $toolCall = new ToolCall('call_1', 'get_product_details', ['sku' => 'SKU-1']);

        $calls = 0;
        $lastTools = null;
        $chatService = $this->createMock(ChatGenerationServiceInterface::class);
        $chatService->method('chat')->willReturnCallback(
            function (int $storeId, array $messages, array $tools) use (&$calls, &$lastTools, $toolCall): ChatResponse {
                $calls++;
                $lastTools = $tools;

                return new ChatResponse('', [$toolCall], new TokenUsage(1, 1), 'openai', 'gpt-4o-mini', 5);
            }
        );

        $service = $this->service(
            chatService: $chatService,
            registry: new CommerceToolRegistry(['get_product_details' => $tool]),
            maxToolCalls: 2
        );

        $service->converse(self::STORE_ID, null, null, [new ChatMessage('user', 'hi')], null);

        // 2 rounds (each offering tools) + 1 final tools-less call.
        self::assertSame(3, $calls);
        self::assertSame([], $lastTools);
    }

    public function testVerifiedProductsAccumulateAcrossMultipleToolCallsInOneRound(): void
    {
        $verifiedA = new RevalidatedProduct(1, 'SKU-1', 'Blue Shoe', 49.99, null, 'https://store.test/blue-shoe', '2026-08-16T00:00:00+00:00');
        $verifiedB = new RevalidatedProduct(2, 'SKU-2', 'Red Hat', 19.99, null, 'https://store.test/red-hat', '2026-08-16T00:00:00+00:00');

        $tool = $this->createMock(CommerceToolInterface::class);
        $tool->method('name')->willReturn('get_product_details');
        $tool->method('description')->willReturn('desc');
        $tool->method('inputSchema')->willReturn(['type' => 'object']);
        $tool->method('execute')->willReturnOnConsecutiveCalls(
            new ToolResult(['found' => true], [$verifiedA]),
            new ToolResult(['found' => true], [$verifiedB])
        );

        $callA = new ToolCall('call_1', 'get_product_details', ['sku' => 'SKU-1']);
        $callB = new ToolCall('call_2', 'get_product_details', ['sku' => 'SKU-2']);

        $calls = 0;
        $chatService = $this->createMock(ChatGenerationServiceInterface::class);
        $chatService->method('chat')->willReturnCallback(
            function () use (&$calls, $callA, $callB): ChatResponse {
                $calls++;

                return $calls === 1
                    ? new ChatResponse('', [$callA, $callB], new TokenUsage(1, 1), 'openai', 'gpt-4o-mini', 5)
                    : $this->textResponse('ok');
            }
        );

        $service = $this->service(
            chatService: $chatService,
            registry: new CommerceToolRegistry(['get_product_details' => $tool])
        );

        $result = $service->converse(self::STORE_ID, null, null, [new ChatMessage('user', 'hi')], null);

        self::assertSame([$verifiedA, $verifiedB], $result->verifiedProducts);
    }

    public function testCollectorRecordsEveryRoundsRawResponse(): void
    {
        $toolCall = new ToolCall('call_1', 'get_product_details', ['sku' => 'SKU-1']);
        $tool = $this->tool('get_product_details');

        $calls = 0;
        $chatService = $this->createMock(ChatGenerationServiceInterface::class);
        $chatService->method('chat')->willReturnCallback(function () use (&$calls, $toolCall): ChatResponse {
            $calls++;

            return $calls === 1
                ? new ChatResponse('', [$toolCall], new TokenUsage(1, 1), 'openai', 'gpt-4o-mini', 5)
                : $this->textResponse('Here it is.');
        });

        $service = $this->service(chatService: $chatService, registry: new CommerceToolRegistry(['get_product_details' => $tool]));

        $collector = $this->createMock(ToolCallingDebugCollectorInterface::class);
        $recordedRounds = [];
        $collector->expects(self::exactly(2))
            ->method('recordRound')
            ->willReturnCallback(function (int $round, ChatResponse $response) use (&$recordedRounds): void {
                $recordedRounds[] = [$round, $response->text];
            });

        $service->converse(self::STORE_ID, null, null, [new ChatMessage('user', 'hi')], null, $collector);

        self::assertSame([[0, ''], [1, 'Here it is.']], $recordedRounds);
    }

    public function testCollectorRecordsEveryToolExecutionsRawResult(): void
    {
        $verified = new RevalidatedProduct(1, 'SKU-1', 'Blue Shoe', 49.99, null, 'https://store.test/blue-shoe', '2026-08-16T00:00:00+00:00');
        $toolResult = new ToolResult(['found' => true], [$verified]);

        $tool = $this->createMock(CommerceToolInterface::class);
        $tool->method('name')->willReturn('get_product_details');
        $tool->method('description')->willReturn('desc');
        $tool->method('inputSchema')->willReturn(['type' => 'object']);
        $tool->method('execute')->willReturn($toolResult);

        $toolCall = new ToolCall('call_1', 'get_product_details', ['sku' => 'SKU-1']);

        $calls = 0;
        $chatService = $this->createMock(ChatGenerationServiceInterface::class);
        $chatService->method('chat')->willReturnCallback(function () use (&$calls, $toolCall): ChatResponse {
            $calls++;

            return $calls === 1
                ? new ChatResponse('', [$toolCall], new TokenUsage(1, 1), 'openai', 'gpt-4o-mini', 5)
                : $this->textResponse('ok');
        });

        $service = $this->service(chatService: $chatService, registry: new CommerceToolRegistry(['get_product_details' => $tool]));

        $collector = $this->createMock(ToolCallingDebugCollectorInterface::class);
        $collector->expects(self::once())->method('recordToolExecution')->with($toolCall, $toolResult);

        $service->converse(self::STORE_ID, null, null, [new ChatMessage('user', 'hi')], null, $collector);
    }

    private function textResponse(string $text): ChatResponse
    {
        return new ChatResponse($text, [], new TokenUsage(1, 1), 'openai', 'gpt-4o-mini', 5);
    }

    private function tool(string $name, bool $authorized = true): CommerceToolInterface
    {
        $tool = $this->createMock(CommerceToolInterface::class);
        $tool->method('name')->willReturn($name);
        $tool->method('description')->willReturn('desc');
        $tool->method('inputSchema')->willReturn(['type' => 'object']);

        if (!$authorized) {
            $tool->method('authorize')->willThrowException(new ToolAuthorizationException(new Phrase('disabled')));
        }

        return $tool;
    }

    private function service(
        ChatGenerationServiceInterface $chatService,
        ?CommerceToolRegistry $registry = null,
        int $maxToolCalls = 4
    ): ToolCallingChatService {
        $guardrails = $this->createMock(GuardrailConfigInterface::class);
        $guardrails->method('maxToolCalls')->willReturn($maxToolCalls);

        $configurationReader = $this->createMock(ConfigurationReaderInterface::class);
        $configurationReader->method('readGuardrails')->with(self::STORE_ID)->willReturn($guardrails);

        return new ToolCallingChatService($chatService, $registry ?? new CommerceToolRegistry(), $configurationReader);
    }
}
