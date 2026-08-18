<?php

declare(strict_types=1);

namespace Aavirbhava\AiShoppingAssistant\Test\Unit\Model\Chat;

use Aavirbhava\AiShoppingAssistant\Api\Config\ConfigurationReaderInterface;
use Aavirbhava\AiShoppingAssistant\Api\Config\LlmConfigInterface;
use Aavirbhava\AiShoppingAssistant\Api\Config\SecretReaderInterface;
use Aavirbhava\AiShoppingAssistant\Api\LlmProviderInterface;
use Aavirbhava\AiShoppingAssistant\Api\Provider\ConfiguredProviderResolverInterface;
use Aavirbhava\AiShoppingAssistant\Api\Store\StoreScopeInterface;
use Aavirbhava\AiShoppingAssistant\Api\Store\StoreScopeProviderInterface;
use Aavirbhava\AiShoppingAssistant\Model\Chat\ChatGenerationService;
use Aavirbhava\AiShoppingAssistant\Model\Config\Exception\ConfigurationException;
use Aavirbhava\AiShoppingAssistant\Model\Config\SecretValue;
use Aavirbhava\AiShoppingAssistant\Model\Dto\ChatMessage;
use Aavirbhava\AiShoppingAssistant\Model\Dto\ChatRequest;
use Aavirbhava\AiShoppingAssistant\Model\Dto\ChatResponse;
use Aavirbhava\AiShoppingAssistant\Model\Dto\TokenUsage;
use Aavirbhava\AiShoppingAssistant\Model\Provider\Exception\ProviderConfigurationException;
use Aavirbhava\AiShoppingAssistant\Model\Provider\Exception\ProviderInvalidResponseException;
use Aavirbhava\AiShoppingAssistant\Model\Provider\Exception\ProviderNotFoundException;
use Aavirbhava\AiShoppingAssistant\Model\Provider\Exception\ProviderUnavailableException;
use Aavirbhava\AiShoppingAssistant\Model\Store\Exception\StoreScopeException;
use Magento\Framework\Phrase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(ChatGenerationService::class)]
final class ChatGenerationServiceTest extends TestCase
{
    private const STORE_ID = 3;

    public function testSuccessfullyGeneratesChatResponseScopedToStore(): void
    {
        $config = $this->config('gpt-4o-mini', '', 22, 1500);
        $provider = $this->createMock(LlmProviderInterface::class);

        $captured = null;
        $provider->method('chat')
            ->willReturnCallback(function (ChatRequest $request) use (&$captured): ChatResponse {
                $captured = $request;

                return new ChatResponse('Here are some options.', [], new TokenUsage(5, 5), 'openai', 'gpt-4o-mini', 10);
            });

        $service = $this->service(
            $this->activeStoreScope(),
            $this->reader($config),
            $this->resolver($provider),
            $this->secretReader()
        );

        $messages = [new ChatMessage('user', 'Show waterproof phones under 25000.')];
        $response = $service->chat(self::STORE_ID, $messages);

        self::assertSame('Here are some options.', $response->text);

        self::assertNotNull($captured);
        self::assertSame(self::STORE_ID, $captured->storeId);
        self::assertSame($messages, $captured->messages);
        self::assertSame('gpt-4o-mini', $captured->model);
        self::assertSame('', $captured->baseUrl);
        self::assertSame('key-123', $captured->apiKey->reveal());
        self::assertSame(22, $captured->timeoutSeconds);
        self::assertSame(1500, $captured->maxOutputTokens);
    }

    public function testToolsAndResponseSchemaArePassedThrough(): void
    {
        $provider = $this->createMock(LlmProviderInterface::class);
        $captured = null;
        $provider->method('chat')
            ->willReturnCallback(function (ChatRequest $request) use (&$captured): ChatResponse {
                $captured = $request;

                return new ChatResponse('{}', [], new TokenUsage(0, 0), 'openai', 'gpt-4o-mini', 5);
            });

        $service = $this->service(
            $this->activeStoreScope(),
            $this->reader($this->config('gpt-4o-mini', '', 20, 1200)),
            $this->resolver($provider),
            $this->secretReader()
        );

        $tools = [['name' => 'search_products']];
        $schema = ['type' => 'object'];

        $service->chat(self::STORE_ID, [new ChatMessage('user', 'hi')], $tools, $schema);

        self::assertNotNull($captured);
        self::assertSame($tools, $captured->tools);
        self::assertSame($schema, $captured->responseSchema);
    }

    public function testInactiveStoreFailsClosedBeforeAnyRead(): void
    {
        $storeScope = $this->createMock(StoreScopeProviderInterface::class);
        $storeScope->method('requireActive')
            ->with(self::STORE_ID)
            ->willThrowException(new StoreScopeException(new Phrase('Store is not active.')));

        $service = $this->service($storeScope, $this->reader(), $this->resolver(), $this->secretReader());

        $this->expectException(StoreScopeException::class);
        $service->chat(self::STORE_ID, [new ChatMessage('user', 'hi')]);
    }

    public function testMissingLlmConfigFailsWithSanitizedException(): void
    {
        $reader = $this->createMock(ConfigurationReaderInterface::class);
        $reader->method('readLlm')
            ->with(self::STORE_ID)
            ->willThrowException(new ConfigurationException(new Phrase('LLM provider is not configured')));

        $service = $this->service($this->activeStoreScope(), $reader, $this->resolver(), $this->secretReader());

        try {
            $service->chat(self::STORE_ID, [new ChatMessage('user', 'hi')]);
            self::fail('Expected ProviderConfigurationException.');
        } catch (ProviderConfigurationException $exception) {
            self::assertSame('PROVIDER_CONFIGURATION_ERROR', $exception->errorCode());
            self::assertStringNotContainsString('hi', $exception->getMessage());
        }
    }

    public function testUnknownProviderFailsWithSanitizedException(): void
    {
        $resolver = $this->createMock(ConfiguredProviderResolverInterface::class);
        $resolver->method('primaryLlmProvider')
            ->with(self::STORE_ID)
            ->willThrowException(new ProviderNotFoundException(new Phrase('Provider not found.')));

        $service = $this->service(
            $this->activeStoreScope(),
            $this->reader($this->config('gpt-4o-mini', '', 20, 1200)),
            $resolver,
            $this->secretReader()
        );

        try {
            $service->chat(self::STORE_ID, [new ChatMessage('user', 'hi')]);
            self::fail('Expected ProviderConfigurationException.');
        } catch (ProviderConfigurationException $exception) {
            self::assertSame('PROVIDER_CONFIGURATION_ERROR', $exception->errorCode());
        }
    }

    public function testSecretReadFailureIsSanitized(): void
    {
        $secretReader = $this->createMock(SecretReaderInterface::class);
        $secretReader->method('getPrimaryLlmApiKey')
            ->with(self::STORE_ID)
            ->willThrowException(new ConfigurationException(new Phrase('Unable to decrypt the LLM API key.')));

        $service = $this->service(
            $this->activeStoreScope(),
            $this->reader($this->config('gpt-4o-mini', '', 20, 1200)),
            $this->resolver($this->createMock(LlmProviderInterface::class)),
            $secretReader
        );

        try {
            $service->chat(self::STORE_ID, [new ChatMessage('user', 'hi')]);
            self::fail('Expected ProviderConfigurationException.');
        } catch (ProviderConfigurationException $exception) {
            self::assertSame('PROVIDER_CONFIGURATION_ERROR', $exception->errorCode());
        }
    }

    public function testProviderExceptionPropagatesUnchanged(): void
    {
        $provider = $this->createMock(LlmProviderInterface::class);
        $provider->method('chat')->willThrowException(
            new ProviderUnavailableException(new Phrase('The chat provider is temporarily unavailable.'))
        );

        $service = $this->service(
            $this->activeStoreScope(),
            $this->reader($this->config('gpt-4o-mini', '', 20, 1200)),
            $this->resolver($provider),
            $this->secretReader()
        );

        try {
            $service->chat(self::STORE_ID, [new ChatMessage('user', 'hi')]);
            self::fail('Expected ProviderUnavailableException.');
        } catch (ProviderUnavailableException $exception) {
            self::assertSame('PROVIDER_UNAVAILABLE', $exception->errorCode());
        }
    }

    public function testUnexpectedProviderFailureIsSanitized(): void
    {
        $provider = $this->createMock(LlmProviderInterface::class);
        $provider->method('chat')->willThrowException(new \RuntimeException('secret-key leaked detail'));

        $service = $this->service(
            $this->activeStoreScope(),
            $this->reader($this->config('gpt-4o-mini', '', 20, 1200)),
            $this->resolver($provider),
            $this->secretReader()
        );

        try {
            $service->chat(self::STORE_ID, [new ChatMessage('user', 'hi')]);
            self::fail('Expected ProviderInvalidResponseException.');
        } catch (ProviderInvalidResponseException $exception) {
            self::assertStringNotContainsString('secret-key', $exception->getMessage());
        }
    }

    public function testNeverConsultsFallbackProvider(): void
    {
        $reader = $this->createMock(ConfigurationReaderInterface::class);
        $reader->expects(self::never())->method('readFallback');
        $reader->method('readLlm')
            ->with(self::STORE_ID)
            ->willReturn($this->config('gpt-4o-mini', '', 20, 1200));

        $service = $this->service($this->activeStoreScope(), $reader, $this->resolver(), $this->secretReader());

        $service->chat(self::STORE_ID, [new ChatMessage('user', 'hi')]);
        $this->addToAssertionCount(1);
    }

    private function service(
        StoreScopeProviderInterface $storeScope,
        ConfigurationReaderInterface $reader,
        ConfiguredProviderResolverInterface $resolver,
        SecretReaderInterface $secretReader
    ): ChatGenerationService {
        return new ChatGenerationService($storeScope, $reader, $resolver, $secretReader);
    }

    private function activeStoreScope(): StoreScopeProviderInterface
    {
        $storeScope = $this->createMock(StoreScopeProviderInterface::class);
        $storeScope->method('requireActive')
            ->with(self::STORE_ID)
            ->willReturn($this->createMock(StoreScopeInterface::class));

        return $storeScope;
    }

    private function reader(?LlmConfigInterface $config = null): ConfigurationReaderInterface
    {
        $reader = $this->createMock(ConfigurationReaderInterface::class);
        $reader->method('readLlm')
            ->with(self::STORE_ID)
            ->willReturn($config ?? $this->config('gpt-4o-mini', '', 20, 1200));

        return $reader;
    }

    private function resolver(?LlmProviderInterface $provider = null): ConfiguredProviderResolverInterface
    {
        $provider ??= $this->defaultProvider();

        $resolver = $this->createMock(ConfiguredProviderResolverInterface::class);
        $resolver->method('primaryLlmProvider')
            ->with(self::STORE_ID)
            ->willReturn($provider);

        return $resolver;
    }

    private function defaultProvider(): LlmProviderInterface
    {
        $provider = $this->createMock(LlmProviderInterface::class);
        $provider->method('chat')->willReturn(
            new ChatResponse('ok', [], new TokenUsage(0, 0), 'openai', 'gpt-4o-mini', 1)
        );

        return $provider;
    }

    private function secretReader(): SecretReaderInterface
    {
        $secretReader = $this->createMock(SecretReaderInterface::class);
        $secretReader->method('getPrimaryLlmApiKey')
            ->with(self::STORE_ID)
            ->willReturn(new SecretValue('key-123'));

        return $secretReader;
    }

    private function config(string $model, string $baseUrl, int $timeoutSeconds, int $maxOutputTokens): LlmConfigInterface
    {
        $config = $this->createMock(LlmConfigInterface::class);
        $config->method('provider')->willReturn('openai');
        $config->method('model')->willReturn($model);
        $config->method('baseUrl')->willReturn($baseUrl);
        $config->method('timeoutSeconds')->willReturn($timeoutSeconds);
        $config->method('maxOutputTokens')->willReturn($maxOutputTokens);

        return $config;
    }
}
