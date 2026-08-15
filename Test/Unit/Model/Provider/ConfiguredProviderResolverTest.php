<?php

declare(strict_types=1);

namespace Aavirbhava\AiShoppingAssistant\Test\Unit\Model\Provider;

use Aavirbhava\AiShoppingAssistant\Api\Config\ConfigurationReaderInterface;
use Aavirbhava\AiShoppingAssistant\Api\Config\EmbeddingConfigInterface;
use Aavirbhava\AiShoppingAssistant\Api\Config\FallbackConfigInterface;
use Aavirbhava\AiShoppingAssistant\Api\Config\LlmConfigInterface;
use Aavirbhava\AiShoppingAssistant\Api\Provider\EmbeddingProviderRegistryInterface;
use Aavirbhava\AiShoppingAssistant\Api\Provider\LlmProviderRegistryInterface;
use Aavirbhava\AiShoppingAssistant\Model\Provider\ConfiguredProviderResolver;
use Aavirbhava\AiShoppingAssistant\Model\Provider\Exception\ProviderNotFoundException;
use Aavirbhava\AiShoppingAssistant\Model\Provider\LlmProviderRegistry;
use Aavirbhava\AiShoppingAssistant\Test\Unit\Fake\FakeEmbeddingProvider;
use Aavirbhava\AiShoppingAssistant\Test\Unit\Fake\FakeLlmProvider;
use PHPUnit\Framework\TestCase;

class ConfiguredProviderResolverTest extends TestCase
{
    private const STORE_ID = 3;

    public function testResolvesConfiguredPrimaryLlmProvider(): void
    {
        $provider = new FakeLlmProvider('openai');

        $llmConfig = $this->createMock(LlmConfigInterface::class);
        $llmConfig->method('provider')->willReturn('openai');

        $reader = $this->createMock(ConfigurationReaderInterface::class);
        $reader->method('readLlm')->with(self::STORE_ID)->willReturn($llmConfig);

        $llmRegistry = $this->createMock(LlmProviderRegistryInterface::class);
        $llmRegistry->method('get')->with('openai')->willReturn($provider);

        $resolver = $this->resolver($reader, $llmRegistry);

        self::assertSame($provider, $resolver->primaryLlmProvider(self::STORE_ID));
    }

    public function testPrimaryProviderLookupIsScopedToStore(): void
    {
        $provider = new FakeLlmProvider('openai');

        $llmConfig = $this->createMock(LlmConfigInterface::class);
        $llmConfig->method('provider')->willReturn('openai');

        $reader = $this->createMock(ConfigurationReaderInterface::class);
        $reader->method('readLlm')->with(self::STORE_ID)->willReturn($llmConfig);

        $llmRegistry = $this->createMock(LlmProviderRegistryInterface::class);
        $llmRegistry->expects(self::once())->method('get')->with('openai')->willReturn($provider);

        $resolver = $this->resolver($reader, $llmRegistry);

        $resolver->primaryLlmProvider(self::STORE_ID);
    }

    public function testUnresolvablePrimaryProviderPropagatesFailure(): void
    {
        $llmConfig = $this->createMock(LlmConfigInterface::class);
        $llmConfig->method('provider')->willReturn('openai');

        $reader = $this->createMock(ConfigurationReaderInterface::class);
        $reader->method('readLlm')->with(self::STORE_ID)->willReturn($llmConfig);

        $llmRegistry = $this->createMock(LlmProviderRegistryInterface::class);
        $llmRegistry->method('get')->willThrowException(new ProviderNotFoundException(
            new \Magento\Framework\Phrase('The requested LLM provider is not available for this store.')
        ));

        $resolver = $this->resolver($reader, $llmRegistry);

        $this->expectException(ProviderNotFoundException::class);
        $resolver->primaryLlmProvider(self::STORE_ID);
    }

    public function testFallbackIsNullWhenDisabled(): void
    {
        $fallbackConfig = $this->createMock(FallbackConfigInterface::class);
        $fallbackConfig->method('isEnabled')->willReturn(false);

        $reader = $this->createMock(ConfigurationReaderInterface::class);
        $reader->method('readFallback')->with(self::STORE_ID)->willReturn($fallbackConfig);

        $resolver = $this->resolver($reader, $this->createMock(LlmProviderRegistryInterface::class));

        self::assertNull($resolver->fallbackLlmProvider(self::STORE_ID));
    }

    public function testFallbackIsNullWhenEnabledButProviderEmpty(): void
    {
        $fallbackConfig = $this->createMock(FallbackConfigInterface::class);
        $fallbackConfig->method('isEnabled')->willReturn(true);
        $fallbackConfig->method('provider')->willReturn('');

        $reader = $this->createMock(ConfigurationReaderInterface::class);
        $reader->method('readFallback')->with(self::STORE_ID)->willReturn($fallbackConfig);

        $llmRegistry = $this->createMock(LlmProviderRegistryInterface::class);
        $llmRegistry->expects(self::never())->method('get');

        $resolver = $this->resolver($reader, $llmRegistry);

        self::assertNull($resolver->fallbackLlmProvider(self::STORE_ID));
    }

    public function testFallbackResolvesConfiguredProviderWhenEnabled(): void
    {
        $provider = new FakeLlmProvider('openai_compatible');

        $fallbackConfig = $this->createMock(FallbackConfigInterface::class);
        $fallbackConfig->method('isEnabled')->willReturn(true);
        $fallbackConfig->method('provider')->willReturn('openai_compatible');

        $reader = $this->createMock(ConfigurationReaderInterface::class);
        $reader->method('readFallback')->with(self::STORE_ID)->willReturn($fallbackConfig);

        $llmRegistry = $this->createMock(LlmProviderRegistryInterface::class);
        $llmRegistry->method('get')->with('openai_compatible')->willReturn($provider);

        $resolver = $this->resolver($reader, $llmRegistry);

        self::assertSame($provider, $resolver->fallbackLlmProvider(self::STORE_ID));
    }

    public function testResolvesConfiguredEmbeddingProvider(): void
    {
        $provider = new FakeEmbeddingProvider('voyage');

        $embeddingConfig = $this->createMock(EmbeddingConfigInterface::class);
        $embeddingConfig->method('provider')->willReturn('voyage');

        $reader = $this->createMock(ConfigurationReaderInterface::class);
        $reader->method('readEmbedding')->with(self::STORE_ID)->willReturn($embeddingConfig);

        $embeddingRegistry = $this->createMock(EmbeddingProviderRegistryInterface::class);
        $embeddingRegistry->method('get')->with('voyage')->willReturn($provider);

        $resolver = new ConfiguredProviderResolver(
            $reader,
            $this->createMock(LlmProviderRegistryInterface::class),
            $embeddingRegistry
        );

        self::assertSame($provider, $resolver->embeddingProvider(self::STORE_ID));
    }

    public function testClassNameLikeConfiguredProviderFailsClosedWithoutDynamicResolution(): void
    {
        $llmConfig = $this->createMock(LlmConfigInterface::class);
        $llmConfig->method('provider')->willReturn('Acme\\Evil\\Provider');

        $reader = $this->createMock(ConfigurationReaderInterface::class);
        $reader->method('readLlm')->with(self::STORE_ID)->willReturn($llmConfig);

        $resolver = $this->resolver($reader, new LlmProviderRegistry([]));

        $this->expectException(ProviderNotFoundException::class);
        $resolver->primaryLlmProvider(self::STORE_ID);
    }

    private function resolver(
        ConfigurationReaderInterface $reader,
        LlmProviderRegistryInterface $llmRegistry
    ): ConfiguredProviderResolver {
        return new ConfiguredProviderResolver(
            $reader,
            $llmRegistry,
            $this->createMock(EmbeddingProviderRegistryInterface::class)
        );
    }
}
