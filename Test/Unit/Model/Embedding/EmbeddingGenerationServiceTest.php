<?php

declare(strict_types=1);

namespace Aavirbhava\AiShoppingAssistant\Test\Unit\Model\Embedding;

use Aavirbhava\AiShoppingAssistant\Api\Config\ConfigurationReaderInterface;
use Aavirbhava\AiShoppingAssistant\Api\Config\EmbeddingConfigInterface;
use Aavirbhava\AiShoppingAssistant\Api\Config\SecretReaderInterface;
use Aavirbhava\AiShoppingAssistant\Api\Embedding\EmbeddingInputTypeInterface;
use Aavirbhava\AiShoppingAssistant\Api\Embedding\EmbeddingRequestInterface;
use Aavirbhava\AiShoppingAssistant\Api\Embedding\EmbeddingResultInterface;
use Aavirbhava\AiShoppingAssistant\Api\EmbeddingProviderInterface;
use Aavirbhava\AiShoppingAssistant\Api\Provider\ConfiguredProviderResolverInterface;
use Aavirbhava\AiShoppingAssistant\Api\Store\StoreScopeInterface;
use Aavirbhava\AiShoppingAssistant\Api\Store\StoreScopeProviderInterface;
use Aavirbhava\AiShoppingAssistant\Model\Config\Exception\ConfigurationException;
use Aavirbhava\AiShoppingAssistant\Model\Config\SecretValue;
use Aavirbhava\AiShoppingAssistant\Model\Embedding\EmbeddingGenerationService;
use Aavirbhava\AiShoppingAssistant\Model\Embedding\EmbeddingInputValidator;
use Aavirbhava\AiShoppingAssistant\Model\Embedding\EmbeddingInputType;
use Aavirbhava\AiShoppingAssistant\Model\Embedding\EmbeddingResult;
use Aavirbhava\AiShoppingAssistant\Model\Embedding\EmbeddingResultValidator;
use Aavirbhava\AiShoppingAssistant\Model\Embedding\EmbeddingUsage;
use Aavirbhava\AiShoppingAssistant\Model\Embedding\EmbeddingVector;
use Aavirbhava\AiShoppingAssistant\Model\Embedding\Exception\EmbeddingConfigurationException;
use Aavirbhava\AiShoppingAssistant\Model\Embedding\Exception\EmbeddingDimensionException;
use Aavirbhava\AiShoppingAssistant\Model\Embedding\Exception\EmbeddingInputException;
use Aavirbhava\AiShoppingAssistant\Model\Embedding\Exception\EmbeddingResponseException;
use Aavirbhava\AiShoppingAssistant\Model\Embedding\Exception\EmbeddingUnavailableException;
use Aavirbhava\AiShoppingAssistant\Model\Provider\Exception\ProviderNotFoundException;
use Aavirbhava\AiShoppingAssistant\Model\Store\Exception\StoreScopeException;
use Magento\Framework\Phrase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(EmbeddingGenerationService::class)]
final class EmbeddingGenerationServiceTest extends TestCase
{
    private const STORE_ID = 3;

    public function testSuccessfullyGeneratesEmbeddingsScopedToStore(): void
    {
        $config = $this->config('text-embedding-test', '', 3);
        $provider = $this->createMock(EmbeddingProviderInterface::class);

        $captured = null;
        $provider->method('embed')
            ->willReturnCallback(
                function (EmbeddingRequestInterface $request) use (&$captured): EmbeddingResultInterface {
                    $captured = $request;

                    return new EmbeddingResult(
                        [new EmbeddingVector([0.1, 0.2, 0.3], 3)],
                        ['0'],
                        'text-embedding-test',
                        new EmbeddingUsage(5, 5)
                    );
                }
            );

        $storeScope = $this->activeStoreScope();
        $reader = $this->reader($config);
        $resolver = $this->resolver($provider);
        $secretReader = $this->createMock(SecretReaderInterface::class);
        $secretReader->method('getEmbeddingApiKey')
            ->with(self::STORE_ID)
            ->willReturn(new SecretValue('key-123'));

        $service = $this->service($storeScope, $reader, $resolver, $secretReader);

        $result = $service->embed(self::STORE_ID, EmbeddingInputType::query(), [' blue shoe ']);

        self::assertInstanceOf(EmbeddingResultInterface::class, $result);
        self::assertSame(['0'], $result->inputIdentifiers());

        self::assertNotNull($captured);
        self::assertSame(self::STORE_ID, $captured->storeId());
        self::assertTrue($captured->inputType()->isQuery());
        self::assertSame('blue shoe', $captured->inputs()[0]->text());
        self::assertSame('text-embedding-test', $captured->model());
        self::assertSame('', $captured->baseUrl());
        self::assertSame('key-123', $captured->apiKey()->reveal());
        self::assertSame(EmbeddingGenerationService::TIMEOUT_SECONDS, $captured->timeoutSeconds());
        self::assertSame(3, $captured->dimensions());
    }

    public function testDocumentInputTypeIsPassedThrough(): void
    {
        $provider = $this->createMock(EmbeddingProviderInterface::class);
        $captured = null;
        $provider->method('embed')
            ->willReturnCallback(
                function (EmbeddingRequestInterface $request) use (&$captured): EmbeddingResultInterface {
                    $captured = $request;

                    return new EmbeddingResult(
                        [new EmbeddingVector([0.1, 0.2, 0.3], 3)],
                        ['0'],
                        'text-embedding-test',
                        new EmbeddingUsage(0, 0)
                    );
                }
            );

        $service = $this->service(
            $this->activeStoreScope(),
            $this->reader($this->config('text-embedding-test', '', 3)),
            $this->resolver($provider),
            $this->secretReader()
        );

        $service->embed(self::STORE_ID, EmbeddingInputType::document(), ['blue shoe']);

        self::assertNotNull($captured);
        self::assertTrue($captured->inputType()->isDocument());
    }

    public function testInactiveStoreFailsClosedBeforeAnyRead(): void
    {
        $storeScope = $this->createMock(StoreScopeProviderInterface::class);
        $storeScope->method('requireActive')
            ->with(self::STORE_ID)
            ->willThrowException(new StoreScopeException(new Phrase('Store is not active.')));

        $service = $this->service($storeScope, $this->reader(), $this->resolver(), $this->secretReader());

        $this->expectException(StoreScopeException::class);
        $service->embed(self::STORE_ID, EmbeddingInputType::query(), ['blue shoe']);
    }

    public function testMissingEmbeddingConfigFailsWithSanitizedException(): void
    {
        $reader = $this->createMock(ConfigurationReaderInterface::class);
        $reader->method('readEmbedding')
            ->with(self::STORE_ID)
            ->willThrowException(new ConfigurationException(new Phrase('embedding provider is not configured')));

        $service = $this->service($this->activeStoreScope(), $reader, $this->resolver(), $this->secretReader());

        try {
            $service->embed(self::STORE_ID, EmbeddingInputType::query(), ['blue shoe']);
            self::fail('Expected EmbeddingConfigurationException.');
        } catch (EmbeddingConfigurationException $exception) {
            self::assertSame('embedding_configuration_invalid', $exception->errorCode());
            self::assertStringNotContainsString('blue shoe', $exception->getMessage());
        }
    }

    public function testUnknownProviderFailsWithSanitizedException(): void
    {
        $resolver = $this->createMock(ConfiguredProviderResolverInterface::class);
        $resolver->method('embeddingProvider')
            ->with(self::STORE_ID)
            ->willThrowException(new ProviderNotFoundException(new Phrase('Provider not found.')));

        $service = $this->service(
            $this->activeStoreScope(),
            $this->reader($this->config('text-embedding-test', '', 3)),
            $resolver,
            $this->secretReader()
        );

        try {
            $service->embed(self::STORE_ID, EmbeddingInputType::query(), ['blue shoe']);
            self::fail('Expected EmbeddingConfigurationException.');
        } catch (EmbeddingConfigurationException $exception) {
            self::assertSame('embedding_configuration_invalid', $exception->errorCode());
        }
    }

    public function testOversizedInputFailsBeforeProviderInvocation(): void
    {
        $provider = $this->createMock(EmbeddingProviderInterface::class);
        $provider->expects(self::never())->method('embed');

        $service = $this->service(
            $this->activeStoreScope(),
            $this->reader($this->config('text-embedding-test', '', 3)),
            $this->resolver($provider),
            $this->secretReader()
        );

        $this->expectException(EmbeddingInputException::class);
        $service->embed(self::STORE_ID, EmbeddingInputType::query(), []);
    }

    public function testDimensionMismatchIsRejected(): void
    {
        $provider = $this->createMock(EmbeddingProviderInterface::class);
        $provider->method('embed')->willReturn(
            new EmbeddingResult(
                [new EmbeddingVector([0.1, 0.2, 0.3, 0.4], 4)],
                ['0'],
                'text-embedding-test',
                new EmbeddingUsage(0, 0)
            )
        );

        $service = $this->service(
            $this->activeStoreScope(),
            $this->reader($this->config('text-embedding-test', '', 3)),
            $this->resolver($provider),
            $this->secretReader()
        );

        $this->expectException(EmbeddingDimensionException::class);
        $service->embed(self::STORE_ID, EmbeddingInputType::query(), ['blue shoe']);
    }

    public function testReorderedResultIsRejected(): void
    {
        $provider = $this->createMock(EmbeddingProviderInterface::class);
        $provider->method('embed')->willReturn(
            new EmbeddingResult(
                [new EmbeddingVector([0.1, 0.2, 0.3], 3), new EmbeddingVector([0.4, 0.5, 0.6], 3)],
                ['1', '0'],
                'text-embedding-test',
                new EmbeddingUsage(0, 0)
            )
        );

        $service = $this->service(
            $this->activeStoreScope(),
            $this->reader($this->config('text-embedding-test', '', 3)),
            $this->resolver($provider),
            $this->secretReader()
        );

        $this->expectException(EmbeddingResponseException::class);
        $service->embed(self::STORE_ID, EmbeddingInputType::query(), ['blue shoe', 'red hat']);
    }

    public function testProviderExceptionPropagatesUnchanged(): void
    {
        $provider = $this->createMock(EmbeddingProviderInterface::class);
        $provider->method('embed')->willThrowException(new EmbeddingUnavailableException(
            new Phrase('The embedding provider is temporarily unavailable.')
        ));

        $service = $this->service(
            $this->activeStoreScope(),
            $this->reader($this->config('text-embedding-test', '', 3)),
            $this->resolver($provider),
            $this->secretReader()
        );

        try {
            $service->embed(self::STORE_ID, EmbeddingInputType::query(), ['blue shoe']);
            self::fail('Expected EmbeddingUnavailableException.');
        } catch (EmbeddingUnavailableException $exception) {
            self::assertSame('embedding_provider_unavailable', $exception->errorCode());
        }
    }

    public function testUnexpectedProviderFailureIsSanitized(): void
    {
        $provider = $this->createMock(EmbeddingProviderInterface::class);
        $provider->method('embed')->willThrowException(new \RuntimeException('secret-key leaked detail'));

        $service = $this->service(
            $this->activeStoreScope(),
            $this->reader($this->config('text-embedding-test', '', 3)),
            $this->resolver($provider),
            $this->secretReader()
        );

        try {
            $service->embed(self::STORE_ID, EmbeddingInputType::query(), ['blue shoe']);
            self::fail('Expected EmbeddingResponseException.');
        } catch (EmbeddingResponseException $exception) {
            self::assertSame('embedding_response_invalid', $exception->errorCode());
            self::assertStringNotContainsString('secret-key', $exception->getMessage());
        }
    }

    public function testSecretReadFailureIsSanitized(): void
    {
        $secretReader = $this->createMock(SecretReaderInterface::class);
        $secretReader->method('getEmbeddingApiKey')
            ->with(self::STORE_ID)
            ->willThrowException(new ConfigurationException(new Phrase('Unable to decrypt the embedding API key.')));

        $service = $this->service(
            $this->activeStoreScope(),
            $this->reader($this->config('text-embedding-test', '', 3)),
            $this->resolver($this->createMock(EmbeddingProviderInterface::class)),
            $secretReader
        );

        try {
            $service->embed(self::STORE_ID, EmbeddingInputType::query(), ['blue shoe']);
            self::fail('Expected EmbeddingConfigurationException.');
        } catch (EmbeddingConfigurationException $exception) {
            self::assertSame('embedding_configuration_invalid', $exception->errorCode());
        }
    }

    public function testNeverConsultsFallbackProvider(): void
    {
        $reader = $this->createMock(ConfigurationReaderInterface::class);
        $reader->expects(self::never())->method('readFallback');
        $reader->method('readEmbedding')
            ->with(self::STORE_ID)
            ->willReturn($this->config('text-embedding-test', '', 3));

        $service = $this->service(
            $this->activeStoreScope(),
            $reader,
            $this->resolver(),
            $this->secretReader()
        );

        $service->embed(self::STORE_ID, EmbeddingInputType::query(), ['blue shoe']);
        $this->addToAssertionCount(1);
    }

    private function service(
        StoreScopeProviderInterface $storeScope,
        ConfigurationReaderInterface $reader,
        ConfiguredProviderResolverInterface $resolver,
        SecretReaderInterface $secretReader
    ): EmbeddingGenerationService {
        return new EmbeddingGenerationService(
            $storeScope,
            $reader,
            $resolver,
            $secretReader,
            new EmbeddingInputValidator(),
            new EmbeddingResultValidator()
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

    private function reader(?EmbeddingConfigInterface $config = null): ConfigurationReaderInterface
    {
        $reader = $this->createMock(ConfigurationReaderInterface::class);
        $reader->method('readEmbedding')
            ->with(self::STORE_ID)
            ->willReturn($config ?? $this->config('text-embedding-test', '', 3));

        return $reader;
    }

    private function resolver(?EmbeddingProviderInterface $provider = null): ConfiguredProviderResolverInterface
    {
        $provider ??= $this->defaultProvider();

        $resolver = $this->createMock(ConfiguredProviderResolverInterface::class);
        $resolver->method('embeddingProvider')
            ->with(self::STORE_ID)
            ->willReturn($provider);

        return $resolver;
    }

    private function defaultProvider(): EmbeddingProviderInterface
    {
        $provider = $this->createMock(EmbeddingProviderInterface::class);
        $provider->method('embed')->willReturn(
            new EmbeddingResult(
                [new EmbeddingVector([0.1, 0.2, 0.3], 3)],
                ['0'],
                'text-embedding-test',
                new EmbeddingUsage(0, 0)
            )
        );

        return $provider;
    }

    private function secretReader(): SecretReaderInterface
    {
        $secretReader = $this->createMock(SecretReaderInterface::class);
        $secretReader->method('getEmbeddingApiKey')
            ->with(self::STORE_ID)
            ->willReturn(new SecretValue('key-123'));

        return $secretReader;
    }

    private function config(string $model, string $baseUrl, int $dimensions): EmbeddingConfigInterface
    {
        $config = $this->createMock(EmbeddingConfigInterface::class);
        $config->method('provider')->willReturn('openai');
        $config->method('model')->willReturn($model);
        $config->method('baseUrl')->willReturn($baseUrl);
        $config->method('dimensions')->willReturn($dimensions);

        return $config;
    }
}
