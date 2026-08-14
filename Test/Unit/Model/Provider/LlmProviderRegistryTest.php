<?php

declare(strict_types=1);

namespace Aavirbhava\AiShoppingAssistant\Test\Unit\Model\Provider;

use Aavirbhava\AiShoppingAssistant\Api\LlmProviderInterface;
use Aavirbhava\AiShoppingAssistant\Model\Provider\Exception\ProviderConfigurationException;
use Aavirbhava\AiShoppingAssistant\Model\Provider\Exception\ProviderNotFoundException;
use Aavirbhava\AiShoppingAssistant\Model\Provider\LlmProviderRegistry;
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

    public function testRegisteredProviderIsAvailableAndCapabilitiesAreExposed(): void
    {
        $provider = new FakeLlmProvider('openai');
        $registry = new LlmProviderRegistry(['openai' => $provider]);

        self::assertTrue($registry->has('openai'));
        self::assertSame($provider, $registry->get('openai'));
        self::assertSame(['openai' => $provider], $registry->all());
        self::assertTrue($registry->capabilities('openai')->supportsToolCalling());
    }

    public function testUnknownIdentifierFailsClosed(): void
    {
        $registry = new LlmProviderRegistry(['openai' => new FakeLlmProvider('openai')]);

        self::assertFalse($registry->has('UnknownClass'));
        self::assertFalse($registry->has('google'));

        $this->expectException(ProviderNotFoundException::class);
        $registry->get('UnknownClass');
    }

    public function testRegisteredIdentifierOutsideAllowlistIsRejected(): void
    {
        $registry = new LlmProviderRegistry([
            'openai' => new FakeLlmProvider('openai'),
            'google' => new FakeLlmProvider('google'),
        ]);

        self::assertFalse($registry->has('google'));

        $this->expectException(ProviderNotFoundException::class);
        $registry->get('google');
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

    public function testErrorMessageNeverContainsTheRequestedIdentifier(): void
    {
        $registry = new LlmProviderRegistry([]);

        try {
            $registry->get('openai');
            self::fail('Expected ProviderNotFoundException to be thrown.');
        } catch (ProviderNotFoundException $exception) {
            self::assertStringNotContainsString('openai', $exception->getMessage());
        }
    }
}