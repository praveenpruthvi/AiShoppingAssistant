<?php

declare(strict_types=1);

namespace Aavirbhava\AiShoppingAssistant\Test\Unit\Model\Provider;

use Aavirbhava\AiShoppingAssistant\Model\Provider\Exception\ProviderConfigurationException;
use Aavirbhava\AiShoppingAssistant\Model\Provider\Exception\ProviderNotFoundException;
use Aavirbhava\AiShoppingAssistant\Model\Provider\EmbeddingProviderRegistry;
use Aavirbhava\AiShoppingAssistant\Test\Unit\Fake\FakeEmbeddingProvider;
use PHPUnit\Framework\TestCase;

class EmbeddingProviderRegistryTest extends TestCase
{
    public function testEmptyRegistryHasNoProviders(): void
    {
        $registry = new EmbeddingProviderRegistry([]);

        self::assertFalse($registry->has('openai'));
        self::assertSame([], $registry->all());
    }

    public function testRegisteredProviderIsAvailableAndCapabilitiesAreExposed(): void
    {
        $provider = new FakeEmbeddingProvider('openai');
        $registry = new EmbeddingProviderRegistry(['openai' => $provider]);

        self::assertTrue($registry->has('openai'));
        self::assertSame($provider, $registry->get('openai'));
        self::assertSame(['openai' => $provider], $registry->all());
        self::assertTrue($registry->capabilities('openai')->supportsEmbeddings());
    }

    public function testUnknownIdentifierFailsClosed(): void
    {
        $registry = new EmbeddingProviderRegistry(['openai' => new FakeEmbeddingProvider('openai')]);

        self::assertFalse($registry->has('UnknownClass'));
        self::assertFalse($registry->has('google'));

        $this->expectException(ProviderNotFoundException::class);
        $registry->get('UnknownClass');
    }

    public function testRegisteredIdentifierOutsideAllowlistIsRejected(): void
    {
        $registry = new EmbeddingProviderRegistry([
            'openai' => new FakeEmbeddingProvider('openai'),
            'google' => new FakeEmbeddingProvider('google'),
        ]);

        self::assertFalse($registry->has('google'));

        $this->expectException(ProviderNotFoundException::class);
        $registry->get('google');
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

    public function testErrorMessageNeverContainsTheRequestedIdentifier(): void
    {
        $registry = new EmbeddingProviderRegistry([]);

        try {
            $registry->get('voyage');
            self::fail('Expected ProviderNotFoundException to be thrown.');
        } catch (ProviderNotFoundException $exception) {
            self::assertStringNotContainsString('voyage', $exception->getMessage());
        }
    }
}