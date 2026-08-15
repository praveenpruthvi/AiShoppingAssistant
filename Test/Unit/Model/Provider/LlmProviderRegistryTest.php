<?php

declare(strict_types=1);

namespace Aavirbhava\AiShoppingAssistant\Test\Unit\Model\Provider;

use Aavirbhava\AiShoppingAssistant\Model\Provider\Exception\ProviderConfigurationException;
use Aavirbhava\AiShoppingAssistant\Model\Provider\Exception\ProviderNotFoundException;
use Aavirbhava\AiShoppingAssistant\Model\Provider\LlmProviderRegistry;
use Aavirbhava\AiShoppingAssistant\Test\Unit\Fake\FakeEmbeddingProvider;
use Aavirbhava\AiShoppingAssistant\Test\Unit\Fake\FakeLlmProvider;
use PHPUnit\Framework\TestCase;

class LlmProviderRegistryTest extends TestCase
{
    public function testEmptyRegistryHasNoProviders(): void
    {
        $registry = new LlmProviderRegistry([]);

        self::assertFalse($registry->has('openai'));
        self::assertSame([], $registry->all());
    }

    public function testBuiltInRegisteredProviderResolves(): void
    {
        $provider = new FakeLlmProvider('openai');
        $registry = new LlmProviderRegistry(['openai' => $provider]);

        self::assertTrue($registry->has('openai'));
        self::assertSame($provider, $registry->get('openai'));
        self::assertSame(['openai' => $provider], $registry->all());
        self::assertTrue($registry->capabilities('openai')->supportsToolCalling());
    }

    public function testThirdPartyProviderResolvesThroughDiRegistration(): void
    {
        $provider = new FakeLlmProvider('acme_local_llm');
        $registry = new LlmProviderRegistry(['acme_local_llm' => $provider]);

        self::assertTrue($registry->has('acme_local_llm'));
        self::assertSame($provider, $registry->get('acme_local_llm'));
        self::assertSame($provider, $registry->get('acme_local_llm'));
    }

    public function testUnregisteredIdentifierFailsClosed(): void
    {
        $registry = new LlmProviderRegistry(['openai' => new FakeLlmProvider('openai')]);

        self::assertFalse($registry->has('UnknownClass'));
        self::assertFalse($registry->has('google'));
        self::assertFalse($registry->has('acme_local_llm'));

        $this->expectException(ProviderNotFoundException::class);
        $registry->get('acme_local_llm');
    }

    public function testRegisteredIdentifierIsTheAllowlistEvenWhenNotBuiltIn(): void
    {
        $registry = new LlmProviderRegistry(['acme_local_llm' => new FakeLlmProvider('acme_local_llm')]);

        self::assertTrue($registry->has('acme_local_llm'));
        self::assertFalse($registry->has('openai'));
    }

    public function testInvalidDiKeyIsRejectedAtConstruction(): void
    {
        $this->expectException(ProviderConfigurationException::class);
        new LlmProviderRegistry(['Acme LLM' => new FakeLlmProvider('acme_local_llm')]);
    }

    public function testInvalidProviderIdentifierIsRejectedAtConstruction(): void
    {
        $this->expectException(ProviderConfigurationException::class);
        new LlmProviderRegistry(['openai' => new FakeLlmProvider('openai/Evil')]);
    }

    public function testDiKeyProviderIdentifierMismatchIsRejected(): void
    {
        $this->expectException(ProviderConfigurationException::class);
        new LlmProviderRegistry(['openai' => new FakeLlmProvider('acme_local_llm')]);
    }

    public function testNonStringIdentifierIsRejectedAtConstruction(): void
    {
        $this->expectException(ProviderConfigurationException::class);
        new LlmProviderRegistry([new FakeLlmProvider('openai')]);
    }

    public function testNonProviderInstanceIsRejectedAtConstruction(): void
    {
        $this->expectException(ProviderConfigurationException::class);
        new LlmProviderRegistry(['openai' => $this->createMock(\stdClass::class)]);
    }

    public function testEmbeddingProviderCannotRegisterAsLlm(): void
    {
        $this->expectException(ProviderConfigurationException::class);
        new LlmProviderRegistry(['openai' => new FakeEmbeddingProvider('openai')]);
    }

    public function testErrorMessageNeverContainsTheRequestedIdentifier(): void
    {
        $registry = new LlmProviderRegistry([]);

        try {
            $registry->get('acme_local_llm');
            self::fail('Expected ProviderNotFoundException to be thrown.');
        } catch (ProviderNotFoundException $exception) {
            self::assertStringNotContainsString('acme_local_llm', $exception->getMessage());
        }
    }
}
