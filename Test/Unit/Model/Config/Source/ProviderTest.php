<?php

declare(strict_types=1);

namespace Aavirbhava\AiShoppingAssistant\Test\Unit\Model\Config\Source;

use Aavirbhava\AiShoppingAssistant\Model\Config\Source\Provider;
use Aavirbhava\AiShoppingAssistant\Model\Provider\LlmProviderRegistry;
use Aavirbhava\AiShoppingAssistant\Model\Provider\ProviderLabelRegistry;
use Aavirbhava\AiShoppingAssistant\Model\Provider\ProviderOptionService;
use Aavirbhava\AiShoppingAssistant\Test\Unit\Fake\FakeLlmProvider;
use PHPUnit\Framework\TestCase;

class ProviderTest extends TestCase
{
    public function testOptionsContainBuiltInAndRegisteredThirdPartyProvidersSorted(): void
    {
        $registry = new LlmProviderRegistry([
            'openai' => new FakeLlmProvider('openai'),
            'acme_local_llm' => new FakeLlmProvider('acme_local_llm'),
            'anthropic' => new FakeLlmProvider('anthropic'),
        ]);
        $labels = new ProviderLabelRegistry([
            'openai' => 'OpenAI',
            'anthropic' => 'Anthropic Claude',
            'acme_local_llm' => 'Acme Local LLM',
        ]);

        $options = (new Provider($registry, new ProviderOptionService($labels)))->toOptionArray();

        self::assertSame(['acme_local_llm', 'anthropic', 'openai'], array_column($options, 'value'));
        self::assertSame(
            ['Acme Local LLM', 'Anthropic Claude', 'OpenAI'],
            array_column($options, 'label')
        );
    }

    public function testOptionsCarryOnlyValueAndLabel(): void
    {
        $registry = new LlmProviderRegistry([
            'acme_local_llm' => new FakeLlmProvider('acme_local_llm'),
        ]);
        $labels = new ProviderLabelRegistry(['acme_local_llm' => 'Acme Local LLM']);

        $options = (new Provider($registry, new ProviderOptionService($labels)))->toOptionArray();

        self::assertCount(1, $options);
        self::assertSame(['value', 'label'], array_keys($options[0]));
        self::assertSame('acme_local_llm', $options[0]['value']);
        self::assertSame('Acme Local LLM', $options[0]['label']);
    }

    public function testEmptyRegistryProducesEmptyOptionList(): void
    {
        $registry = new LlmProviderRegistry([]);

        $options = (new Provider($registry, new ProviderOptionService(new ProviderLabelRegistry([]))))->toOptionArray();

        self::assertSame([], $options);
    }

    public function testOptionsExposeNoSecretOrConfigurationData(): void
    {
        $registry = new LlmProviderRegistry([
            'openai' => new FakeLlmProvider('openai'),
        ]);
        $labels = new ProviderLabelRegistry(['openai' => 'OpenAI']);

        $options = (new Provider($registry, new ProviderOptionService($labels)))->toOptionArray();

        $serialized = json_encode($options);
        self::assertNotFalse($serialized);
        self::assertStringNotContainsString('api_key', $serialized);
        self::assertStringNotContainsString('secret', strtolower($serialized));
        self::assertStringNotContainsString('base_url', $serialized);
        self::assertStringNotContainsString('ProviderCapabilities', $serialized);
    }
}