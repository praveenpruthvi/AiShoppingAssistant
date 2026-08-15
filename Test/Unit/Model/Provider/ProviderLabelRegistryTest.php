<?php

declare(strict_types=1);

namespace Aavirbhava\AiShoppingAssistant\Test\Unit\Model\Provider;

use Aavirbhava\AiShoppingAssistant\Model\Provider\Exception\ProviderConfigurationException;
use Aavirbhava\AiShoppingAssistant\Model\Provider\ProviderLabelRegistry;
use PHPUnit\Framework\TestCase;

class ProviderLabelRegistryTest extends TestCase
{
    public function testReturnsRegisteredLabel(): void
    {
        $registry = new ProviderLabelRegistry([
            'openai' => 'OpenAI',
            'acme_local_llm' => 'Acme Local LLM',
        ]);

        self::assertSame('OpenAI', $registry->get('openai'));
        self::assertSame('Acme Local LLM', $registry->get('acme_local_llm'));
    }

    public function testMissingLabelFallsBackToHumanizedIdentifier(): void
    {
        $registry = new ProviderLabelRegistry([]);

        self::assertSame('Openai', $registry->get('openai'));
        self::assertSame('Acme Local Llm', $registry->get('acme_local_llm'));
    }

    public function testInvalidIdentifierKeyIsRejected(): void
    {
        $this->expectException(ProviderConfigurationException::class);
        new ProviderLabelRegistry(['Acme LLM' => 'Acme LLM']);
    }

    public function testEmptyLabelIsRejected(): void
    {
        $this->expectException(ProviderConfigurationException::class);
        new ProviderLabelRegistry(['openai' => '']);
    }

    public function testEmptyRegistryIsSafe(): void
    {
        $registry = new ProviderLabelRegistry([]);

        self::assertSame('Voyage', $registry->get('voyage'));
    }
}
