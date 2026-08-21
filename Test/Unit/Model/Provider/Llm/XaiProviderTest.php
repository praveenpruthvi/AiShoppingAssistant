<?php

declare(strict_types=1);

namespace Aavirbhava\AiShoppingAssistant\Test\Unit\Model\Provider\Llm;

use Aavirbhava\AiShoppingAssistant\Model\Config\SecretValue;
use Aavirbhava\AiShoppingAssistant\Model\Dto\ChatRequest;
use Aavirbhava\AiShoppingAssistant\Model\Dto\ChatMessage;
use Aavirbhava\AiShoppingAssistant\Model\Provider\Exception\ProviderAuthenticationException;
use Aavirbhava\AiShoppingAssistant\Model\Provider\Exception\ProviderConfigurationException;
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

    private function request(int $maxOutputTokens = 1200, bool $withApiKey = true): ChatRequest
    {
        return new ChatRequest(
            storeId: 1,
            messages: [new ChatMessage('user', 'Show me carbon-fiber bike frames.')],
            model: self::MODEL,
            baseUrl: '',
            apiKey: $withApiKey ? new SecretValue('grok-key') : SecretValue::empty(),
            timeoutSeconds: 20,
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
