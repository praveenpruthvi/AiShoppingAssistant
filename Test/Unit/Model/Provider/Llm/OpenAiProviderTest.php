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
use Aavirbhava\AiShoppingAssistant\Model\Provider\Exception\ProviderRefusalException;
use Aavirbhava\AiShoppingAssistant\Model\Provider\Exception\ProviderTimeoutException;
use Aavirbhava\AiShoppingAssistant\Model\Provider\Exception\ProviderTransportException;
use Aavirbhava\AiShoppingAssistant\Model\Provider\Exception\ProviderUnavailableException;
use Aavirbhava\AiShoppingAssistant\Model\Provider\Http\HttpUrlPolicy;
use Aavirbhava\AiShoppingAssistant\Model\Provider\Llm\ChatEndpointPolicy;
use Aavirbhava\AiShoppingAssistant\Model\Provider\Llm\ChatHttpTransport;
use Aavirbhava\AiShoppingAssistant\Model\Provider\Llm\OpenAiProvider;
use Laminas\Http\Response;
use Magento\Framework\HTTP\LaminasClient;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(OpenAiProvider::class)]
final class OpenAiProviderTest extends TestCase
{
    private const MODEL = 'gpt-4o-mini-test';

    private ?LaminasClient $client = null;

    public function testIdentifierAndCapabilities(): void
    {
        $provider = $this->provider('HTTP/1.1 200 OK' . "\r\n\r\n" . '{}');

        self::assertSame('openai', $provider->identifier());
        self::assertTrue($provider->capabilities()->supportsChatGeneration());
        self::assertTrue($provider->capabilities()->supportsToolCalling());
        self::assertTrue($provider->capabilities()->supportsStructuredOutput());
        self::assertFalse($provider->capabilities()->isApiKeyOptional());
    }

    public function testReturnsTextResponseWithUsageAndLatency(): void
    {
        $provider = $this->provider(
            'HTTP/1.1 200 OK' . "\r\n\r\n" .
            '{"model":"gpt-4o-mini-test","choices":[{"message":{"role":"assistant",' .
            '"content":"Here are waterproof phones."}}],' .
            '"usage":{"prompt_tokens":42,"completion_tokens":8,"prompt_tokens_details":{"cached_tokens":10}}}'
        );

        $response = $provider->chat($this->request());

        self::assertSame('Here are waterproof phones.', $response->text);
        self::assertSame([], $response->toolCalls);
        self::assertSame('openai', $response->provider);
        self::assertSame('gpt-4o-mini-test', $response->model);
        self::assertSame(42, $response->usage->inputTokens);
        self::assertSame(8, $response->usage->outputTokens);
        self::assertSame(10, $response->usage->cachedInputTokens);
        self::assertGreaterThanOrEqual(0, $response->latencyMilliseconds);
    }

    public function testSendsEndpointHeadersAndBody(): void
    {
        $provider = $this->provider(
            'HTTP/1.1 200 OK' . "\r\n\r\n" .
            '{"choices":[{"message":{"content":"ok"}}]}'
        );

        $provider->chat($this->request(maxOutputTokens: 500));

        $request = $this->client->getRequest();

        self::assertSame('https://api.openai.com/v1/chat/completions', $request->getUriString());
        self::assertSame('Bearer key-123', $request->getHeaders()->get('Authorization')->getFieldValue());

        $body = json_decode($request->getContent(), true);
        self::assertSame(self::MODEL, $body['model']);
        self::assertSame('user', $body['messages'][0]['role']);
        self::assertSame('Show waterproof phones under 25000.', $body['messages'][0]['content']);
        self::assertSame(500, $body['max_completion_tokens']);
        self::assertStringNotContainsString('key-123', $request->getContent());
    }

    public function testToolsAreTranslatedToFunctionShape(): void
    {
        $provider = $this->provider(
            'HTTP/1.1 200 OK' . "\r\n\r\n" .
            '{"choices":[{"message":{"content":"ok"}}]}'
        );

        $provider->chat($this->request(tools: [
            ['name' => 'search_products', 'description' => 'Search the catalog', 'parameters' => ['type' => 'object', 'properties' => ['q' => ['type' => 'string']]]],
        ]));

        $body = json_decode($this->client->getRequest()->getContent(), true);

        self::assertSame('auto', $body['tool_choice']);
        self::assertSame('function', $body['tools'][0]['type']);
        self::assertSame('search_products', $body['tools'][0]['function']['name']);
        self::assertSame('Search the catalog', $body['tools'][0]['function']['description']);
    }

    /**
     * A tool with no arguments (e.g. get_cart) must encode `properties`
     * as a JSON object (`{}`), not an array (`[]`) — json_decode(...,
     * true) round-trips both to an identical PHP `[]`, so this has to
     * assert on the raw request string to actually distinguish them.
     * Regression test for a real, live-confirmed bug: a real Ollama
     * instance rejects the entire chat request with HTTP 400 the moment
     * a tool's `properties` is a JSON array instead of an object.
     */
    public function testZeroArgumentToolPropertiesEncodesAsJsonObject(): void
    {
        $provider = $this->provider(
            'HTTP/1.1 200 OK' . "\r\n\r\n" .
            '{"choices":[{"message":{"content":"ok"}}]}'
        );

        $provider->chat($this->request(tools: [
            ['name' => 'get_cart', 'description' => 'Get the cart', 'parameters' => ['type' => 'object', 'properties' => new \stdClass(), 'required' => [], 'additionalProperties' => false]],
        ]));

        $rawContent = $this->client->getRequest()->getContent();

        self::assertStringContainsString('"properties":{}', $rawContent);
        self::assertStringNotContainsString('"properties":[]', $rawContent);
    }

    public function testToolWithoutNameIsRejectedBeforeSending(): void
    {
        $provider = $this->provider('HTTP/1.1 200 OK' . "\r\n\r\n" . '{}');
        $this->client->expects(self::never())->method('send');

        $this->expectException(ProviderConfigurationException::class);
        $provider->chat($this->request(tools: [['description' => 'missing name']]));
    }

    public function testResponseSchemaIsSentAsJsonSchemaFormat(): void
    {
        $provider = $this->provider(
            'HTTP/1.1 200 OK' . "\r\n\r\n" .
            '{"choices":[{"message":{"content":"{}"}}]}'
        );

        $provider->chat($this->request(responseSchema: ['type' => 'object', 'properties' => []]));

        $body = json_decode($this->client->getRequest()->getContent(), true);

        self::assertSame('json_schema', $body['response_format']['type']);
        self::assertTrue($body['response_format']['json_schema']['strict']);
        self::assertSame(['type' => 'object', 'properties' => []], $body['response_format']['json_schema']['schema']);
    }

    public function testReturnsToolCallsParsedFromJsonArguments(): void
    {
        $provider = $this->provider(
            'HTTP/1.1 200 OK' . "\r\n\r\n" .
            '{"choices":[{"message":{"content":null,"tool_calls":[' .
            '{"id":"call_1","type":"function","function":{"name":"search_products","arguments":"{\"q\":\"phone\"}"}}' .
            ']}}]}'
        );

        $response = $provider->chat($this->request());

        self::assertSame('', $response->text);
        self::assertCount(1, $response->toolCalls);
        self::assertSame('call_1', $response->toolCalls[0]->id);
        self::assertSame('search_products', $response->toolCalls[0]->name);
        self::assertSame(['q' => 'phone'], $response->toolCalls[0]->arguments);
    }

    public function testMalformedToolCallArgumentsAreRejected(): void
    {
        $provider = $this->provider(
            'HTTP/1.1 200 OK' . "\r\n\r\n" .
            '{"choices":[{"message":{"content":null,"tool_calls":[' .
            '{"id":"call_1","type":"function","function":{"name":"search_products","arguments":"not json"}}' .
            ']}}]}'
        );

        $this->expectException(ProviderInvalidResponseException::class);
        $provider->chat($this->request());
    }

    public function testAssistantToolCallHistoryIsSerializedAsToolCalls(): void
    {
        $provider = $this->provider(
            'HTTP/1.1 200 OK' . "\r\n\r\n" .
            '{"choices":[{"message":{"content":"Here it is."}}]}'
        );

        $request = new ChatRequest(
            storeId: 1,
            messages: [
                new ChatMessage('user', 'What is the price of SKU-1?'),
                new ChatMessage(
                    'assistant',
                    '',
                    null,
                    [new ToolCall('call_1', 'check_price', ['skus' => ['SKU-1']])]
                ),
                new ChatMessage('tool', '{"prices":[{"sku":"SKU-1","price":9.99}]}', 'call_1'),
            ],
            model: self::MODEL,
            baseUrl: '',
            apiKey: new SecretValue('key-123'),
            timeoutSeconds: 20
        );

        $provider->chat($request);

        $body = json_decode($this->client->getRequest()->getContent(), true);

        self::assertSame('user', $body['messages'][0]['role']);
        self::assertArrayNotHasKey('tool_calls', $body['messages'][0]);

        self::assertSame('assistant', $body['messages'][1]['role']);
        self::assertSame('', $body['messages'][1]['content']);
        self::assertCount(1, $body['messages'][1]['tool_calls']);
        self::assertSame('call_1', $body['messages'][1]['tool_calls'][0]['id']);
        self::assertSame('function', $body['messages'][1]['tool_calls'][0]['type']);
        self::assertSame('check_price', $body['messages'][1]['tool_calls'][0]['function']['name']);
        self::assertSame('{"skus":["SKU-1"]}', $body['messages'][1]['tool_calls'][0]['function']['arguments']);

        self::assertSame('tool', $body['messages'][2]['role']);
        self::assertSame('call_1', $body['messages'][2]['tool_call_id']);
        self::assertSame('{"prices":[{"sku":"SKU-1","price":9.99}]}', $body['messages'][2]['content']);
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
        $provider = $this->provider('HTTP/1.1 401 Unauthorized' . "\r\n\r\n" . '{"error":"bad"}');

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

    public function testGatewayTimeoutStatusMapsToTimeoutException(): void
    {
        $provider = $this->provider('HTTP/1.1 504 Gateway Timeout' . "\r\n\r\n" . '{}');

        $this->expectException(ProviderTimeoutException::class);
        $provider->chat($this->request());
    }

    public function testInvalidJsonIsRejected(): void
    {
        $provider = $this->provider('HTTP/1.1 200 OK' . "\r\n\r\n" . 'not json');

        $this->expectException(ProviderInvalidResponseException::class);
        $provider->chat($this->request());
    }

    public function testMissingChoicesAreRejected(): void
    {
        $provider = $this->provider('HTTP/1.1 200 OK' . "\r\n\r\n" . '{"choices":[]}');

        $this->expectException(ProviderInvalidResponseException::class);
        $provider->chat($this->request());
    }

    public function testEmptyContentAndNoToolCallsIsRejected(): void
    {
        $provider = $this->provider(
            'HTTP/1.1 200 OK' . "\r\n\r\n" . '{"choices":[{"message":{"content":""}}]}'
        );

        $this->expectException(ProviderInvalidResponseException::class);
        $provider->chat($this->request());
    }

    public function testRefusalIsMappedToRefusalException(): void
    {
        $provider = $this->provider(
            'HTTP/1.1 200 OK' . "\r\n\r\n" .
            '{"choices":[{"message":{"content":null,"refusal":"I cannot help with that."}}]}'
        );

        $this->expectException(ProviderRefusalException::class);
        $provider->chat($this->request());
    }

    public function testTransportFailureIsSanitized(): void
    {
        $this->client = $this->makeClient();
        $this->client->method('send')->willThrowException(new \RuntimeException('secret detail'));
        $provider = new OpenAiProvider(
            new ChatHttpTransport($this->client, new HttpUrlPolicy()),
            new ChatEndpointPolicy(new HttpUrlPolicy())
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
            'HTTP/1.1 200 OK' . "\r\n\r\n" . '{"choices":[{"message":{"content":"OK"}}]}'
        );

        $result = $provider->testConnection(1, self::MODEL, '', new SecretValue('key-123'), 20);

        self::assertTrue($result->successful);
        self::assertNull($result->sanitizedErrorCode);
    }

    public function testConnectionFailsClosedOnAuthenticationError(): void
    {
        $provider = $this->provider('HTTP/1.1 401 Unauthorized' . "\r\n\r\n" . '{}');

        $result = $provider->testConnection(1, self::MODEL, '', new SecretValue('bad-key'), 20);

        self::assertFalse($result->successful);
        self::assertSame('PROVIDER_AUTHENTICATION_ERROR', $result->sanitizedErrorCode);
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
     * @param array<string, mixed>|null $responseSchema
     */
    private function request(
        array $tools = [],
        ?array $responseSchema = null,
        int $maxOutputTokens = 1200,
        string $baseUrl = '',
        bool $withApiKey = true
    ): ChatRequest {
        return new ChatRequest(
            storeId: 1,
            messages: [new ChatMessage('user', 'Show waterproof phones under 25000.')],
            model: self::MODEL,
            baseUrl: $baseUrl,
            apiKey: $withApiKey ? new SecretValue('key-123') : SecretValue::empty(),
            timeoutSeconds: 20,
            tools: $tools,
            responseSchema: $responseSchema,
            maxOutputTokens: $maxOutputTokens
        );
    }

    private function provider(string $rawResponse): OpenAiProvider
    {
        $this->client = $this->makeClient();
        $this->client->method('send')->willReturn(Response::fromString($rawResponse));

        return new OpenAiProvider(
            new ChatHttpTransport($this->client, new HttpUrlPolicy()),
            new ChatEndpointPolicy(new HttpUrlPolicy())
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
