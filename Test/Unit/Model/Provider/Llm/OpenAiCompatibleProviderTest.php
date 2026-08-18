<?php

declare(strict_types=1);

namespace Aavirbhava\AiShoppingAssistant\Test\Unit\Model\Provider\Llm;

use Aavirbhava\AiShoppingAssistant\Model\Config\SecretValue;
use Aavirbhava\AiShoppingAssistant\Model\Dto\ChatMessage;
use Aavirbhava\AiShoppingAssistant\Model\Dto\ChatRequest;
use Aavirbhava\AiShoppingAssistant\Model\Provider\Exception\ProviderConfigurationException;
use Aavirbhava\AiShoppingAssistant\Model\Provider\Http\HttpUrlPolicy;
use Aavirbhava\AiShoppingAssistant\Model\Provider\Llm\ChatEndpointPolicy;
use Aavirbhava\AiShoppingAssistant\Model\Provider\Llm\ChatHttpTransport;
use Aavirbhava\AiShoppingAssistant\Model\Provider\Llm\OpenAiCompatibleProvider;
use Laminas\Http\Response;
use Magento\Framework\HTTP\LaminasClient;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Response parsing / tool-call parsing / status-code-to-exception mapping
 * are already thoroughly covered by OpenAiProviderTest against the exact
 * same shared AbstractChatProvider code — this file only proves what is
 * actually specific to this provider: an explicit base URL is required
 * (never a fixed default), plain HTTP is allowed, the API key is
 * genuinely optional (no Authorization header at all when empty, not
 * merely an empty one), and the max-output-tokens field name is
 * `max_tokens` (not OpenAiProvider's `max_completion_tokens`) — the one
 * confirmed wire-format difference for Ollama's OpenAI-compatible layer.
 */
#[CoversClass(OpenAiCompatibleProvider::class)]
final class OpenAiCompatibleProviderTest extends TestCase
{
    private const MODEL = 'llama3.1';
    private const LOCAL_BASE_URL = 'http://127.0.0.1:11434/v1';

    private ?LaminasClient $client = null;

    public function testIdentifierAndCapabilities(): void
    {
        $provider = $this->provider('HTTP/1.1 200 OK' . "\r\n\r\n" . '{}');

        self::assertSame('openai_compatible', $provider->identifier());
        self::assertTrue($provider->capabilities()->supportsChatGeneration());
        self::assertTrue($provider->capabilities()->supportsToolCalling());
        self::assertTrue($provider->capabilities()->supportsStructuredOutput());
        self::assertTrue($provider->capabilities()->isApiKeyOptional());
        self::assertTrue($provider->capabilities()->supportsConfigurableBaseUrl());
    }

    public function testMissingBaseUrlFailsClosedBeforeRequest(): void
    {
        $provider = $this->provider('HTTP/1.1 200 OK' . "\r\n\r\n" . '{}');
        $this->client->expects(self::never())->method('send');

        $this->expectException(ProviderConfigurationException::class);
        $provider->chat($this->request(baseUrl: ''));
    }

    public function testPlainHttpLocalServerIsAllowed(): void
    {
        $provider = $this->provider(
            'HTTP/1.1 200 OK' . "\r\n\r\n" . '{"choices":[{"message":{"content":"ok"}}]}'
        );

        $provider->chat($this->request());

        self::assertSame(
            self::LOCAL_BASE_URL . '/chat/completions',
            $this->client->getRequest()->getUriString()
        );
    }

    public function testNoApiKeyMeansNoAuthorizationHeaderAtAll(): void
    {
        $provider = $this->provider(
            'HTTP/1.1 200 OK' . "\r\n\r\n" . '{"choices":[{"message":{"content":"ok"}}]}'
        );

        $provider->chat($this->request(withApiKey: false));

        self::assertFalse($this->client->getRequest()->getHeaders()->get('Authorization'));
    }

    public function testConfiguredApiKeyIsSentAsBearerHeaderButNeverLogged(): void
    {
        $provider = $this->provider(
            'HTTP/1.1 200 OK' . "\r\n\r\n" . '{"choices":[{"message":{"content":"ok"}}]}'
        );

        $provider->chat($this->request(withApiKey: true));

        $header = $this->client->getRequest()->getHeaders()->get('Authorization');
        self::assertNotFalse($header);
        self::assertSame('Bearer local-key', $header->getFieldValue());
    }

    public function testMaxOutputTokensFieldIsMaxTokensNotMaxCompletionTokens(): void
    {
        $provider = $this->provider(
            'HTTP/1.1 200 OK' . "\r\n\r\n" . '{"choices":[{"message":{"content":"ok"}}]}'
        );

        $provider->chat($this->request(maxOutputTokens: 300));

        $body = json_decode($this->client->getRequest()->getContent(), true);

        self::assertSame(300, $body['max_tokens']);
        self::assertArrayNotHasKey('max_completion_tokens', $body);
    }

    public function testToolsAndResponseFormatUseTheSameOpenAiWireShape(): void
    {
        $provider = $this->provider(
            'HTTP/1.1 200 OK' . "\r\n\r\n" . '{"choices":[{"message":{"content":"ok"}}]}'
        );

        $provider->chat($this->request(
            tools: [['name' => 'search_products', 'description' => 'Search', 'parameters' => ['type' => 'object', 'properties' => []]]],
            responseSchema: ['type' => 'object', 'properties' => []]
        ));

        $body = json_decode($this->client->getRequest()->getContent(), true);

        self::assertSame('function', $body['tools'][0]['type']);
        self::assertSame('search_products', $body['tools'][0]['function']['name']);
        self::assertSame('json_schema', $body['response_format']['type']);
        self::assertTrue($body['response_format']['json_schema']['strict']);
    }

    public function testReturnsTextResponseWithUsageAndLatency(): void
    {
        $provider = $this->provider(
            'HTTP/1.1 200 OK' . "\r\n\r\n" .
            '{"model":"llama3.1","choices":[{"message":{"role":"assistant","content":"Here are duffle bags."}}],' .
            '"usage":{"prompt_tokens":30,"completion_tokens":12}}'
        );

        $response = $provider->chat($this->request());

        self::assertSame('Here are duffle bags.', $response->text);
        self::assertSame('openai_compatible', $response->provider);
        self::assertSame('llama3.1', $response->model);
        self::assertSame(30, $response->usage->inputTokens);
        self::assertSame(12, $response->usage->outputTokens);
    }

    public function testConnectionSucceedsAgainstALocalServerWithNoApiKey(): void
    {
        $provider = $this->provider(
            'HTTP/1.1 200 OK' . "\r\n\r\n" . '{"choices":[{"message":{"content":"OK"}}]}'
        );

        $result = $provider->testConnection(1, self::MODEL, self::LOCAL_BASE_URL, SecretValue::empty(), 20);

        self::assertTrue($result->successful);
        self::assertFalse($this->client->getRequest()->getHeaders()->get('Authorization'));
    }

    public function testConnectionFailsClosedWhenBaseUrlIsMissing(): void
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
        string $baseUrl = self::LOCAL_BASE_URL,
        bool $withApiKey = false
    ): ChatRequest {
        return new ChatRequest(
            storeId: 1,
            messages: [new ChatMessage('user', 'Show me some duffle bags.')],
            model: self::MODEL,
            baseUrl: $baseUrl,
            apiKey: $withApiKey ? new SecretValue('local-key') : SecretValue::empty(),
            timeoutSeconds: 20,
            tools: $tools,
            responseSchema: $responseSchema,
            maxOutputTokens: $maxOutputTokens
        );
    }

    private function provider(string $rawResponse): OpenAiCompatibleProvider
    {
        $this->client = $this->makeClient();
        $this->client->method('send')->willReturn(Response::fromString($rawResponse));

        return new OpenAiCompatibleProvider(
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
