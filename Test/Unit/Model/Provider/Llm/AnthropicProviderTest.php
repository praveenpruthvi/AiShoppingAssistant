<?php

declare(strict_types=1);

namespace Aavirbhava\AiShoppingAssistant\Test\Unit\Model\Provider\Llm;

use Aavirbhava\AiShoppingAssistant\Model\Config\SecretValue;
use Aavirbhava\AiShoppingAssistant\Model\Dto\ChatMessage;
use Aavirbhava\AiShoppingAssistant\Model\Dto\ChatRequest;
use Aavirbhava\AiShoppingAssistant\Model\Dto\ToolCall;
use Aavirbhava\AiShoppingAssistant\Model\Provider\Exception\ProviderAuthenticationException;
use Aavirbhava\AiShoppingAssistant\Model\Provider\Exception\ProviderConfigurationException;
use Aavirbhava\AiShoppingAssistant\Model\Provider\Exception\ProviderInvalidResponseException;
use Aavirbhava\AiShoppingAssistant\Model\Provider\Exception\ProviderRateLimitException;
use Aavirbhava\AiShoppingAssistant\Model\Provider\Exception\ProviderTransportException;
use Aavirbhava\AiShoppingAssistant\Model\Provider\Exception\ProviderUnavailableException;
use Aavirbhava\AiShoppingAssistant\Model\Provider\Http\HttpUrlPolicy;
use Aavirbhava\AiShoppingAssistant\Model\Provider\Llm\AnthropicProvider;
use Aavirbhava\AiShoppingAssistant\Model\Provider\Llm\ChatHttpTransport;
use Aavirbhava\AiShoppingAssistant\Model\Provider\Llm\HttpStatusMapper;
use Laminas\Http\Response;
use Magento\Framework\HTTP\LaminasClient;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Built to spec against Anthropic's published Messages API reference —
 * no live API key was available to exercise a real call. These tests
 * verify the request/response mapping against realistic example payloads
 * shaped exactly like Anthropic's own documented examples, not a real
 * live call — see the module's status report for this task for the
 * explicit, disclosed scope of what is and isn't live-verified.
 */
#[CoversClass(AnthropicProvider::class)]
final class AnthropicProviderTest extends TestCase
{
    private const MODEL = 'claude-sonnet-test';

    private ?LaminasClient $client = null;

    public function testIdentifierAndCapabilities(): void
    {
        $provider = $this->provider('HTTP/1.1 200 OK' . "\r\n\r\n" . '{"content":[{"type":"text","text":"ok"}]}');

        self::assertSame('anthropic', $provider->identifier());
        self::assertTrue($provider->capabilities()->supportsChatGeneration());
        self::assertTrue($provider->capabilities()->supportsToolCalling());
        self::assertFalse($provider->capabilities()->supportsStructuredOutput());
        self::assertFalse($provider->capabilities()->isApiKeyOptional());
    }

    public function testSendsTheRealEndpointAndAuthHeaders(): void
    {
        $provider = $this->provider(
            'HTTP/1.1 200 OK' . "\r\n\r\n" . '{"content":[{"type":"text","text":"ok"}]}'
        );

        $provider->chat($this->request());

        $request = $this->client->getRequest();

        self::assertSame('https://api.anthropic.com/v1/messages', $request->getUriString());
        self::assertSame('claude-key', $request->getHeaders()->get('x-api-key')->getFieldValue());
        self::assertSame('2023-06-01', $request->getHeaders()->get('anthropic-version')->getFieldValue());
        self::assertFalse($request->getHeaders()->get('Authorization'));
    }

    public function testMaxTokensIsAlwaysSentSinceAnthropicRequiresIt(): void
    {
        $provider = $this->provider(
            'HTTP/1.1 200 OK' . "\r\n\r\n" . '{"content":[{"type":"text","text":"ok"}]}'
        );

        $provider->chat($this->request(maxOutputTokens: 777));

        $body = json_decode($this->client->getRequest()->getContent(), true);
        self::assertSame(777, $body['max_tokens']);
    }

    public function testSystemRoleMessagesAreExtractedIntoTheTopLevelSystemFieldNotTheMessagesArray(): void
    {
        $provider = $this->provider(
            'HTTP/1.1 200 OK' . "\r\n\r\n" . '{"content":[{"type":"text","text":"ok"}]}'
        );

        $request = new ChatRequest(
            storeId: 1,
            messages: [
                new ChatMessage('system', 'You are a shopping assistant.'),
                new ChatMessage('system', 'Never invent a price.'),
                new ChatMessage('user', 'Show me tents.'),
            ],
            model: self::MODEL,
            baseUrl: '',
            apiKey: new SecretValue('claude-key'),
            timeoutSeconds: 20
        );

        $provider->chat($request);

        $body = json_decode($this->client->getRequest()->getContent(), true);

        // Task 48: array-of-blocks, not a plain string — required to
        // attach cache_control (see testSystemPromptCarriesACacheControlBreakpoint()).
        self::assertSame("You are a shopping assistant.\n\nNever invent a price.", $body['system'][0]['text']);
        self::assertCount(1, $body['messages']);
        self::assertSame('user', $body['messages'][0]['role']);
        self::assertSame('Show me tents.', $body['messages'][0]['content']);
    }

    /**
     * Task 48: unconditional provider-native prompt caching, never
     * gated behind the Token Optimization toggle — identical content,
     * just billed differently when cached. The system prompt is one of
     * this module's two largest, most static per-request blocks (the
     * other being tool definitions, see
     * testLastToolDefinitionCarriesTheCacheControlBreakpointNotEveryTool()
     * below), so it gets a real cache_control breakpoint, following
     * Anthropic's documented shape: `system` becomes an array of
     * content blocks (a plain string cannot carry cache_control at
     * all) with `cache_control: {type: ephemeral}` on the block.
     */
    public function testSystemPromptCarriesACacheControlBreakpoint(): void
    {
        $provider = $this->provider(
            'HTTP/1.1 200 OK' . "\r\n\r\n" . '{"content":[{"type":"text","text":"ok"}]}'
        );

        $request = new ChatRequest(
            storeId: 1,
            messages: [
                new ChatMessage('system', 'You are a shopping assistant.'),
                new ChatMessage('user', 'Show me tents.'),
            ],
            model: self::MODEL,
            baseUrl: '',
            apiKey: new SecretValue('claude-key'),
            timeoutSeconds: 20
        );

        $provider->chat($request);

        $body = json_decode($this->client->getRequest()->getContent(), true);

        self::assertSame('text', $body['system'][0]['type']);
        self::assertSame('You are a shopping assistant.', $body['system'][0]['text']);
        self::assertSame(['type' => 'ephemeral'], $body['system'][0]['cache_control']);
    }

    public function testAssistantToolCallsBecomeToolUseContentBlocksWithDecodedInput(): void
    {
        $provider = $this->provider(
            'HTTP/1.1 200 OK' . "\r\n\r\n" . '{"content":[{"type":"text","text":"ok"}]}'
        );

        $request = new ChatRequest(
            storeId: 1,
            messages: [
                new ChatMessage('user', 'What is the price of SKU-1?'),
                new ChatMessage(
                    'assistant',
                    '',
                    null,
                    [new ToolCall('toolu_1', 'check_price', ['skus' => ['SKU-1']])]
                ),
                new ChatMessage('tool', '{"prices":[{"sku":"SKU-1","price":9.99}]}', 'toolu_1'),
            ],
            model: self::MODEL,
            baseUrl: '',
            apiKey: new SecretValue('claude-key'),
            timeoutSeconds: 20
        );

        $provider->chat($request);

        $body = json_decode($this->client->getRequest()->getContent(), true);

        self::assertSame('assistant', $body['messages'][1]['role']);
        self::assertSame('tool_use', $body['messages'][1]['content'][0]['type']);
        self::assertSame('toolu_1', $body['messages'][1]['content'][0]['id']);
        self::assertSame('check_price', $body['messages'][1]['content'][0]['name']);
        self::assertSame(['skus' => ['SKU-1']], $body['messages'][1]['content'][0]['input']);

        // A tool result becomes a `user` turn with a tool_result block —
        // Anthropic has no dedicated `tool` role at all.
        self::assertSame('user', $body['messages'][2]['role']);
        self::assertSame('tool_result', $body['messages'][2]['content'][0]['type']);
        self::assertSame('toolu_1', $body['messages'][2]['content'][0]['tool_use_id']);
        self::assertSame('{"prices":[{"sku":"SKU-1","price":9.99}]}', $body['messages'][2]['content'][0]['content']);
    }

    public function testToolsAreTranslatedToInputSchemaShapeNotOpenAisFunctionWrapper(): void
    {
        $provider = $this->provider(
            'HTTP/1.1 200 OK' . "\r\n\r\n" . '{"content":[{"type":"text","text":"ok"}]}'
        );

        $provider->chat($this->request(tools: [
            ['name' => 'search_products', 'description' => 'Search the catalog', 'parameters' => ['type' => 'object', 'properties' => ['q' => ['type' => 'string']]]],
        ]));

        $body = json_decode($this->client->getRequest()->getContent(), true);

        self::assertSame(['type' => 'auto'], $body['tool_choice']);
        self::assertSame('search_products', $body['tools'][0]['name']);
        self::assertSame('Search the catalog', $body['tools'][0]['description']);
        self::assertArrayHasKey('input_schema', $body['tools'][0]);
        self::assertArrayNotHasKey('type', $body['tools'][0]);
        self::assertArrayNotHasKey('function', $body['tools'][0]);
    }

    /**
     * Task 48: cache_control on the LAST tool definition caches every
     * tool up to and including it — Anthropic's own documented
     * placement rule, a breakpoint marks "cache everything before this
     * point too," not "cache only this one block." Placing it on every
     * tool would be both wrong (misrepresents the semantics) and
     * wasteful (Anthropic caps at 4 breakpoints per request).
     */
    public function testLastToolDefinitionCarriesTheCacheControlBreakpointNotEveryTool(): void
    {
        $provider = $this->provider(
            'HTTP/1.1 200 OK' . "\r\n\r\n" . '{"content":[{"type":"text","text":"ok"}]}'
        );

        $provider->chat($this->request(tools: [
            ['name' => 'search_products', 'description' => 'Search the catalog', 'parameters' => ['type' => 'object', 'properties' => []]],
            ['name' => 'get_product_details', 'description' => 'Get product details', 'parameters' => ['type' => 'object', 'properties' => []]],
        ]));

        $body = json_decode($this->client->getRequest()->getContent(), true);

        self::assertArrayNotHasKey('cache_control', $body['tools'][0]);
        self::assertSame(['type' => 'ephemeral'], $body['tools'][1]['cache_control']);
    }

    public function testReturnsTextAndToolCallsParsedFromContentBlocksWithoutJsonDecodingInput(): void
    {
        $provider = $this->provider(
            'HTTP/1.1 200 OK' . "\r\n\r\n" .
            '{"model":"claude-sonnet-test","content":[' .
            '{"type":"text","text":"Here you go."},' .
            '{"type":"tool_use","id":"toolu_1","name":"search_products","input":{"q":"phone"}}' .
            '],"usage":{"input_tokens":42,"output_tokens":8,"cache_read_input_tokens":10}}'
        );

        $response = $provider->chat($this->request());

        self::assertSame('Here you go.', $response->text);
        self::assertCount(1, $response->toolCalls);
        self::assertSame('toolu_1', $response->toolCalls[0]->id);
        self::assertSame('search_products', $response->toolCalls[0]->name);
        self::assertSame(['q' => 'phone'], $response->toolCalls[0]->arguments);
        self::assertSame('anthropic', $response->provider);
        self::assertSame('claude-sonnet-test', $response->model);
        // Task 48: Anthropic's input_tokens/cache_read_input_tokens/
        // cache_creation_input_tokens are additive, non-overlapping
        // fields (Anthropic's own documented formula) — the real total
        // is 42 (new) + 10 (cache read) = 52, not 42 alone.
        self::assertSame(52, $response->usage->inputTokens);
        self::assertSame(8, $response->usage->outputTokens);
        self::assertSame(10, $response->usage->cachedInputTokens);
    }

    /**
     * A real, documented Anthropic behavior: the model can request
     * several tools in one turn (e.g. check_price + check_inventory for
     * the same product) — every tool_use block in the content array
     * must become its own ToolCall, not just the first one.
     */
    public function testMultipleToolUseBlocksInOneResponseAllBecomeSeparateToolCalls(): void
    {
        $provider = $this->provider(
            'HTTP/1.1 200 OK' . "\r\n\r\n" .
            '{"content":[' .
            '{"type":"tool_use","id":"toolu_1","name":"check_price","input":{"skus":["SKU-1"]}},' .
            '{"type":"tool_use","id":"toolu_2","name":"check_inventory","input":{"skus":["SKU-1"]}}' .
            ']}'
        );

        $response = $provider->chat($this->request());

        self::assertCount(2, $response->toolCalls);
        self::assertSame('toolu_1', $response->toolCalls[0]->id);
        self::assertSame('check_price', $response->toolCalls[0]->name);
        self::assertSame('toolu_2', $response->toolCalls[1]->id);
        self::assertSame('check_inventory', $response->toolCalls[1]->name);
    }

    /**
     * Multiple text blocks in one response is real, documented Anthropic
     * behavior (e.g. text interleaved with tool_use blocks) — every text
     * block must be concatenated, not just the first.
     */
    public function testMultipleTextBlocksAreConcatenated(): void
    {
        $provider = $this->provider(
            'HTTP/1.1 200 OK' . "\r\n\r\n" .
            '{"content":[{"type":"text","text":"Part one. "},{"type":"text","text":"Part two."}]}'
        );

        $response = $provider->chat($this->request());

        self::assertSame('Part one. Part two.', $response->text);
    }

    /**
     * `stop_reason: "max_tokens"` is a normal, real Anthropic outcome
     * (the response was truncated by the configured max_tokens, not an
     * error) — whatever text was generated before truncation must still
     * be returned, not treated as a failure. This class deliberately
     * doesn't branch on stop_reason at all for success/failure (only
     * content emptiness matters), so this proves that design choice is
     * correct for this specific, real, named case.
     */
    public function testMaxTokensStopReasonStillReturnsTheTruncatedText(): void
    {
        $provider = $this->provider(
            'HTTP/1.1 200 OK' . "\r\n\r\n" .
            '{"content":[{"type":"text","text":"This response got cut off becau"}],"stop_reason":"max_tokens"}'
        );

        $response = $provider->chat($this->request());

        self::assertSame('This response got cut off becau', $response->text);
    }

    /**
     * Anthropic's real prompt-caching usage shape can carry BOTH
     * `cache_creation_input_tokens` (a cache write, this turn) and
     * `cache_read_input_tokens` (a cache hit) in the same response —
     * only cache_read maps onto this module's own cachedInputTokens
     * concept (a genuine discount on this call's real cost); a cache
     * write is not a discount and must never be double-counted as one.
     * It DOES still count toward the real total input token count
     * though (Task 48) — Anthropic's own documented formula is
     * `input_tokens + cache_read_input_tokens + cache_creation_input_tokens
     * = total`, all three additive and non-overlapping — so the real
     * total here is 100 + 0 + 80 = 180, not 100 alone.
     */
    public function testCacheCreationTokensAreNeverConflatedWithCacheReadTokens(): void
    {
        $provider = $this->provider(
            'HTTP/1.1 200 OK' . "\r\n\r\n" .
            '{"content":[{"type":"text","text":"ok"}],' .
            '"usage":{"input_tokens":100,"output_tokens":5,"cache_creation_input_tokens":80,"cache_read_input_tokens":0}}'
        );

        $response = $provider->chat($this->request());

        self::assertSame(180, $response->usage->inputTokens);
        self::assertSame(0, $response->usage->cachedInputTokens);
    }

    public function testNoSystemRoleMessagesMeansTheSystemFieldIsOmittedEntirely(): void
    {
        $provider = $this->provider(
            'HTTP/1.1 200 OK' . "\r\n\r\n" . '{"content":[{"type":"text","text":"ok"}]}'
        );

        $provider->chat($this->request());

        $body = json_decode($this->client->getRequest()->getContent(), true);
        self::assertArrayNotHasKey('system', $body);
    }

    public function testAToolUseBlockMissingAnIdIsRejected(): void
    {
        $provider = $this->provider(
            'HTTP/1.1 200 OK' . "\r\n\r\n" .
            '{"content":[{"type":"tool_use","name":"search_products","input":{}}]}'
        );

        $this->expectException(ProviderInvalidResponseException::class);
        $provider->chat($this->request());
    }

    public function testMissingApiKeyFailsClosedBeforeRequest(): void
    {
        $provider = $this->provider('HTTP/1.1 200 OK' . "\r\n\r\n" . '{}');
        $this->client->expects(self::never())->method('send');

        $this->expectException(ProviderConfigurationException::class);
        $provider->chat($this->request(withApiKey: false));
    }

    public function testCustomBaseUrlIsRejectedFailClosed(): void
    {
        $provider = $this->provider('HTTP/1.1 200 OK' . "\r\n\r\n" . '{}');
        $this->client->expects(self::never())->method('send');

        $this->expectException(ProviderConfigurationException::class);
        $provider->chat($this->request(baseUrl: 'https://evil.example.test/v1'));
    }

    public function testAuthenticationStatusMapsToAuthenticationException(): void
    {
        $provider = $this->provider('HTTP/1.1 401 Unauthorized' . "\r\n\r\n" . '{}');

        $this->expectException(ProviderAuthenticationException::class);
        $provider->chat($this->request());
    }

    public function testRateLimitStatusMapsToRateLimitException(): void
    {
        $provider = $this->provider('HTTP/1.1 429 Too Many Requests' . "\r\n\r\n" . '{}');

        $this->expectException(ProviderRateLimitException::class);
        $provider->chat($this->request());
    }

    public function testServerErrorMapsToUnavailableException(): void
    {
        $provider = $this->provider('HTTP/1.1 500 Internal Server Error' . "\r\n\r\n" . '{}');

        $this->expectException(ProviderUnavailableException::class);
        $provider->chat($this->request());
    }

    public function testMissingContentIsRejected(): void
    {
        $provider = $this->provider('HTTP/1.1 200 OK' . "\r\n\r\n" . '{}');

        $this->expectException(ProviderInvalidResponseException::class);
        $provider->chat($this->request());
    }

    public function testEmptyTextAndNoToolUseBlocksIsRejected(): void
    {
        $provider = $this->provider('HTTP/1.1 200 OK' . "\r\n\r\n" . '{"content":[]}');

        $this->expectException(ProviderInvalidResponseException::class);
        $provider->chat($this->request());
    }

    public function testTransportFailureIsSanitized(): void
    {
        $this->client = $this->makeClient();
        $this->client->method('send')->willThrowException(new \RuntimeException('secret detail'));
        $provider = new AnthropicProvider(
            new ChatHttpTransport($this->client, new HttpUrlPolicy()),
            new HttpUrlPolicy(),
            new HttpStatusMapper()
        );

        try {
            $provider->chat($this->request());
            self::fail('Expected ProviderTransportException.');
        } catch (ProviderTransportException $exception) {
            self::assertSame('PROVIDER_TRANSPORT_ERROR', $exception->errorCode());
            self::assertStringNotContainsString('secret detail', $exception->getMessage());
        }
    }

    public function testConnectionSucceedsOnValidResponse(): void
    {
        $provider = $this->provider(
            'HTTP/1.1 200 OK' . "\r\n\r\n" . '{"content":[{"type":"text","text":"OK"}]}'
        );

        $result = $provider->testConnection(1, self::MODEL, '', new SecretValue('claude-key'), 20);

        self::assertTrue($result->successful);
        self::assertNull($result->sanitizedErrorCode);
    }

    public function testConnectionFailsClosedOnMissingApiKeyWithoutSending(): void
    {
        $provider = $this->provider('HTTP/1.1 200 OK' . "\r\n\r\n" . '{}');
        $this->client->expects(self::never())->method('send');

        $result = $provider->testConnection(1, self::MODEL, '', SecretValue::empty(), 20);

        self::assertFalse($result->successful);
        self::assertSame('PROVIDER_CONFIGURATION_ERROR', $result->sanitizedErrorCode);
    }

    /**
     * @param list<array<string, mixed>> $tools
     */
    private function request(
        array $tools = [],
        int $maxOutputTokens = 1200,
        string $baseUrl = '',
        bool $withApiKey = true
    ): ChatRequest {
        return new ChatRequest(
            storeId: 1,
            messages: [new ChatMessage('user', 'Show waterproof tents.')],
            model: self::MODEL,
            baseUrl: $baseUrl,
            apiKey: $withApiKey ? new SecretValue('claude-key') : SecretValue::empty(),
            timeoutSeconds: 20,
            tools: $tools,
            maxOutputTokens: $maxOutputTokens
        );
    }

    private function provider(string $rawResponse): AnthropicProvider
    {
        $this->client = $this->makeClient();
        $this->client->method('send')->willReturn(Response::fromString($rawResponse));

        return new AnthropicProvider(
            new ChatHttpTransport($this->client, new HttpUrlPolicy()),
            new HttpUrlPolicy(),
            new HttpStatusMapper()
        );
    }

    private function makeClient(): LaminasClient
    {
        $client = $this->getMockBuilder(LaminasClient::class)
            ->onlyMethods(['send'])
            ->setConstructorArgs([])
            ->getMock();

        $this->client = $client;

        return $client;
    }
}
