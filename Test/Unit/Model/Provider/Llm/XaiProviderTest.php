<?php

declare(strict_types=1);

namespace Aavirbhava\AiShoppingAssistant\Test\Unit\Model\Provider\Llm;

use Aavirbhava\AiShoppingAssistant\Model\Config\SecretValue;
use Aavirbhava\AiShoppingAssistant\Model\Dto\ChatRequest;
use Aavirbhava\AiShoppingAssistant\Model\Dto\ChatMessage;
use Aavirbhava\AiShoppingAssistant\Model\Provider\Exception\ProviderAuthenticationException;
use Aavirbhava\AiShoppingAssistant\Model\Provider\Exception\ProviderConfigurationException;
use Aavirbhava\AiShoppingAssistant\Model\Provider\Exception\ProviderRateLimitException;
use Aavirbhava\AiShoppingAssistant\Model\Provider\Http\HttpUrlPolicy;
use Aavirbhava\AiShoppingAssistant\Model\Provider\Llm\ChatEndpointPolicy;
use Aavirbhava\AiShoppingAssistant\Model\Provider\Llm\ChatHttpTransport;
use Aavirbhava\AiShoppingAssistant\Model\Provider\Llm\XaiProvider;
use Laminas\Http\Response;
use Magento\Framework\HTTP\LaminasClient;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * xAI's Grok endpoint reuses the identical AbstractChatProvider pipeline
 * OpenAiProvider does (confirmed real: xAI's API is documented as
 * OpenAI-SDK-compatible) — this test file only exercises what's actually
 * specific to this adapter (identifier/capabilities, the real default
 * endpoint, header shape, and the `max_tokens` field name), not the whole
 * shared pipeline OpenAiProviderTest already covers exhaustively for every
 * AbstractChatProvider subclass.
 */
#[CoversClass(XaiProvider::class)]
final class XaiProviderTest extends TestCase
{
    private const MODEL = 'grok-4-test';

    private ?LaminasClient $client = null;

    public function testIdentifierAndCapabilities(): void
    {
        $provider = $this->provider('HTTP/1.1 200 OK' . "\r\n\r\n" . '{}');

        self::assertSame('xai', $provider->identifier());
        self::assertTrue($provider->capabilities()->supportsChatGeneration());
        self::assertTrue($provider->capabilities()->supportsToolCalling());
        self::assertFalse($provider->capabilities()->isApiKeyOptional());
        self::assertFalse($provider->capabilities()->supportsConfigurableBaseUrl());
    }

    public function testSendsTheRealDefaultEndpointHeaderAndMaxTokensField(): void
    {
        $provider = $this->provider(
            'HTTP/1.1 200 OK' . "\r\n\r\n" . '{"choices":[{"message":{"content":"ok"}}]}'
        );

        $provider->chat($this->request(maxOutputTokens: 500));

        $request = $this->client->getRequest();

        self::assertSame('https://api.x.ai/v1/chat/completions', $request->getUriString());
        self::assertSame('Bearer grok-key', $request->getHeaders()->get('Authorization')->getFieldValue());

        $body = json_decode($request->getContent(), true);
        self::assertSame(500, $body['max_tokens']);
        self::assertArrayNotHasKey('max_completion_tokens', $body);
    }

    public function testMissingApiKeyFailsClosedBeforeRequest(): void
    {
        $provider = $this->provider('HTTP/1.1 200 OK' . "\r\n\r\n" . '{}');
        $this->client->expects(self::never())->method('send');

        $this->expectException(ProviderConfigurationException::class);
        $provider->chat($this->request(withApiKey: false));
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

    public function testCustomBaseUrlIsRejectedFailClosedSinceGrokIsACloudOnlyProvider(): void
    {
        $provider = $this->provider('HTTP/1.1 200 OK' . "\r\n\r\n" . '{}');
        $this->client->expects(self::never())->method('send');

        $this->expectException(ProviderConfigurationException::class);
        $provider->chat($this->request(baseUrl: 'https://evil.example.test/v1'));
    }

    /**
     * Grok's own tool-calling wire shape is the same OpenAI function-
     * calling format Ollama/OpenAI already use — this exercises it
     * specifically through XaiProvider's own real endpoint/headers, not
     * just assumed from AbstractChatProvider's shared implementation.
     */
    public function testReturnsToolCallsParsedFromJsonArguments(): void
    {
        $provider = $this->provider(
            'HTTP/1.1 200 OK' . "\r\n\r\n" .
            '{"choices":[{"message":{"content":null,"tool_calls":[' .
            '{"id":"call_1","type":"function","function":{"name":"search_products","arguments":"{\"q\":\"tires\"}"}}' .
            ']}}]}'
        );

        $response = $provider->chat($this->request());

        self::assertSame('', $response->text);
        self::assertCount(1, $response->toolCalls);
        self::assertSame('call_1', $response->toolCalls[0]->id);
        self::assertSame('search_products', $response->toolCalls[0]->name);
        self::assertSame(['q' => 'tires'], $response->toolCalls[0]->arguments);
        self::assertSame('xai', $response->provider);
    }

    public function testResponseSchemaIsSentAsJsonSchemaFormatMatchingClaimedStructuredOutputSupport(): void
    {
        $provider = $this->provider(
            'HTTP/1.1 200 OK' . "\r\n\r\n" . '{"choices":[{"message":{"content":"{}"}}]}'
        );

        $provider->chat($this->request(responseSchema: ['type' => 'object', 'properties' => []]));

        $body = json_decode($this->client->getRequest()->getContent(), true);

        self::assertSame('json_schema', $body['response_format']['type']);
        self::assertTrue($body['response_format']['json_schema']['strict']);
    }

    /**
     * @param array<string, mixed>|null $responseSchema
     */
    private function request(
        int $maxOutputTokens = 1200,
        bool $withApiKey = true,
        string $baseUrl = '',
        ?array $responseSchema = null
    ): ChatRequest {
        return new ChatRequest(
            storeId: 1,
            messages: [new ChatMessage('user', 'Show me carbon-fiber bike frames.')],
            model: self::MODEL,
            baseUrl: $baseUrl,
            apiKey: $withApiKey ? new SecretValue('grok-key') : SecretValue::empty(),
            timeoutSeconds: 20,
            responseSchema: $responseSchema,
            maxOutputTokens: $maxOutputTokens
        );
    }

    private function provider(string $rawResponse): XaiProvider
    {
        $this->client = $this->makeClient();
        $this->client->method('send')->willReturn(Response::fromString($rawResponse));

        return new XaiProvider(
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
