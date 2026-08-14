<?php

declare(strict_types=1);

namespace Aavirbhava\AiShoppingAssistant\Test\Unit\Model\Provider;

use Aavirbhava\AiShoppingAssistant\Model\Provider\ProviderLabelRegistry;
use Aavirbhava\AiShoppingAssistant\Model\Provider\ProviderOption;
use Aavirbhava\AiShoppingAssistant\Model\Provider\ProviderOptionService;
use PHPUnit\Framework\TestCase;

class ProviderOptionServiceTest extends TestCase
{
    public function testOptionsAreSortedDeterministicallyByIdentifier(): void
    {
        $service = new ProviderOptionService(new ProviderLabelRegistry([
            'acme_local_llm' => 'Acme Local LLM',
            'openai' => 'OpenAI',
        ]));

        $options = $service->build([
            'openai' => new \stdClass(),
            'acme_local_llm' => new \stdClass(),
        ]);

        $identifiers = array_map(fn (ProviderOption $option) => $option->identifier(), $options);
        $labels = array_map(fn (ProviderOption $option) => $option->label(), $options);

        self::assertSame(['acme_local_llm', 'openai'], $identifiers);
        self::assertSame(['Acme Local LLM', 'OpenAI'], $labels);
    }

    public function testBuildOrderDoesNotDependOnInputOrder(): void
    {
        $service = new ProviderOptionService(new ProviderLabelRegistry([
            'anthropic' => 'Anthropic Claude',
            'xai' => 'xAI Grok',
            'openai' => 'OpenAI',
        ]));

        $first = $service->build([
            'openai' => new \stdClass(),
            'anthropic' => new \stdClass(),
            'xai' => new \stdClass(),
        ]);
        $second = $service->build([
            'xai' => new \stdClass(),
            'openai' => new \stdClass(),
            'anthropic' => new \stdClass(),
        ]);

        self::assertSame(
            array_map(fn (ProviderOption $option) => $option->identifier(), $first),
            array_map(fn (ProviderOption $option) => $option->identifier(), $second)
        );
        self::assertSame(
            array_map(fn (ProviderOption $option) => $option->label(), $first),
            array_map(fn (ProviderOption $option) => $option->label(), $second)
        );
    }

    public function testEmptyRegistryProducesEmptyOptionList(): void
    {
        $service = new ProviderOptionService(new ProviderLabelRegistry([]));

        self::assertSame([], $service->build([]));
    }

    public function testOptionsCarryOnlyIdentifiersAndLabels(): void
    {
        $service = new ProviderOptionService(new ProviderLabelRegistry([
            'acme_local_llm' => 'Acme Local LLM',
        ]));

        $options = $service->build(['acme_local_llm' => new \stdClass()]);

        self::assertCount(1, $options);
        self::assertSame('acme_local_llm', $options[0]->identifier());
        self::assertSame('Acme Local LLM', $options[0]->label());
    }
}