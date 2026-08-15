<?php

declare(strict_types=1);

namespace Aavirbhava\AiShoppingAssistant\Test\Unit\Model\Provider;

use Aavirbhava\AiShoppingAssistant\Model\Provider\Exception\ProviderConfigurationException;
use Aavirbhava\AiShoppingAssistant\Model\Provider\Exception\ProviderNotFoundException;
use Aavirbhava\AiShoppingAssistant\Model\Provider\EmbeddingProviderRegistry;
use Aavirbhava\AiShoppingAssistant\Test\Unit\Fake\FakeEmbeddingProvider;
use Aavirbhava\AiShoppingAssistant\Test\Unit\Fake\FakeLlmProvider;
use PHPUnit\Framework\TestCase;

class EmbeddingProviderRegistryTest extends TestCase
{
    public function testEmptyRegistryHasNoProviders(): void
    {
        $registry = new EmbeddingProviderRegistry([]);

        self::assertFalse($registry->has('openai'));
        self::assertSame([], $registry->all());
    }

    public function testBuiltInRegisteredProviderResolves(): void
    {
        $provider = new FakeEmbeddingProvider('openai');
        $registry = new EmbeddingProviderRegistry(['openai' => $provider]);

        self::assertTrue($registry->has('openai'));
        self::assertSame($provider, $registry->get('openai'));
        self::assertSame(['openai' => $provider], $registry->all());
        self::assertTrue($registry->capabilities('openai')->supportsEmbeddings());
    }

    public function testThirdPartyProviderResolvesThroughDiRegistration(): void
    {
        $provider = new FakeEmbeddingProvider('acme_embeddings');
        $registry = new EmbeddingProviderRegistry(['acme_embeddings' => $provider]);

        self::assertTrue($registry->has('acme_embeddings'));
        self::assertSame($provider, $registry->get('acme_embeddings'));
    }

    public function testUnregisteredIdentifierFailsClosed(): void
    {
        $registry = new EmbeddingProviderRegistry(['openai' => new FakeEmbeddingProvider('openai')]);

        self::assertFalse($registry->has('UnknownClass'));
        self::assertFalse($registry->has('google'));
        self::assertFalse($registry->has('acme_embeddings'));

        $this->expectException(ProviderNotFoundException::class);
        $registry->get('acme_embeddings');
    }

    public function testRegisteredIdentifierIsTheAllowlistEvenWhenNotBuiltIn(): void
    {
        $registry = new EmbeddingProviderRegistry(['acme_embeddings' => new FakeEmbeddingProvider('acme_embeddings')]);

        self::assertTrue($registry->has('acme_embeddings'));
        self::assertFalse($registry->has('voyage'));
    }

    public function testInvalidDiKeyIsRejectedAtConstruction(): void
    {
        $this->expectException(ProviderConfigurationException::class);
        new EmbeddingProviderRegistry(['Acme Embeddings' => new FakeEmbeddingProvider('acme_embeddings')]);
    }

    public function testInvalidProviderIdentifierIsRejectedAtConstruction(): void
    {
        $this->expectException(ProviderConfigurationException::class);
        new EmbeddingProviderRegistry(['openai' => new FakeEmbeddingProvider('openai/Evil')]);
    }

    public function testDiKeyProviderIdentifierMismatchIsRejected(): void
    {
        $this->expectException(ProviderConfigurationException::class);
        new EmbeddingProviderRegistry(['openai' => new FakeEmbeddingProvider('acme_embeddings')]);
    }

    public function testNonStringIdentifierIsRejectedAtConstruction(): void
    {
        $this->expectException(ProviderConfigurationException::class);
        new EmbeddingProviderRegistry([new FakeEmbeddingProvider('openai')]);
    }

    public function testNonProviderInstanceIsRejectedAtConstruction(): void
    {
        $this->expectException(ProviderConfigurationException::class);
        new EmbeddingProviderRegistry(['openai' => $this->createMock(\stdClass::class)]);
    }

    public function testLlmProviderCannotRegisterAsEmbeddingProvider(): void
    {
        $this->expectException(ProviderConfigurationException::class);
        new EmbeddingProviderRegistry(['openai' => new FakeLlmProvider('openai')]);
    }

    public function testErrorMessageNeverContainsTheRequestedIdentifier(): void
    {
        $registry = new EmbeddingProviderRegistry([]);

        try {
            $registry->get('acme_embeddings');
            self::fail('Expected ProviderNotFoundException to be thrown.');
        } catch (ProviderNotFoundException $exception) {
            self::assertStringNotContainsString('acme_embeddings', $exception->getMessage());
        }
    }
}
