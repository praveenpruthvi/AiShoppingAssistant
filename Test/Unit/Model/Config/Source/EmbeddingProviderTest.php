<?php

declare(strict_types=1);

namespace Aavirbhava\AiShoppingAssistant\Test\Unit\Model\Config\Source;

use Aavirbhava\AiShoppingAssistant\Model\Config\Source\EmbeddingProvider;
use Aavirbhava\AiShoppingAssistant\Model\Provider\EmbeddingProviderRegistry;
use Aavirbhava\AiShoppingAssistant\Model\Provider\ProviderLabelRegistry;
use Aavirbhava\AiShoppingAssistant\Model\Provider\ProviderOptionService;
use Aavirbhava\AiShoppingAssistant\Test\Unit\Fake\FakeEmbeddingProvider;
use PHPUnit\Framework\TestCase;

class EmbeddingProviderTest extends TestCase
{
    public function testOptionsContainBuiltInAndRegisteredThirdPartyProvidersSorted(): void
    {
        $registry = new EmbeddingProviderRegistry([
            'voyage' => new FakeEmbeddingProvider('voyage'),
            'acme_embeddings' => new FakeEmbeddingProvider('acme_embeddings'),
        ]);
        $labels = new ProviderLabelRegistry([
            'voyage' => 'Voyage AI',
            'acme_embeddings' => 'Acme Embeddings',
        ]);

        $options = (new EmbeddingProvider($registry, new ProviderOptionService($labels)))->toOptionArray();

        self::assertSame(['acme_embeddings', 'voyage'], array_column($options, 'value'));
        self::assertSame(
            ['Acme Embeddings', 'Voyage AI'],
            array_column($options, 'label')
        );
    }

    public function testEmptyRegistryProducesEmptyOptionList(): void
    {
        $registry = new EmbeddingProviderRegistry([]);

        $options = (new EmbeddingProvider($registry, new ProviderOptionService(new ProviderLabelRegistry([]))))->toOptionArray();

        self::assertSame([], $options);
    }

    public function testOptionsExposeNoSecretOrConfigurationData(): void
    {
        $registry = new EmbeddingProviderRegistry([
            'openai' => new FakeEmbeddingProvider('openai'),
        ]);
        $labels = new ProviderLabelRegistry(['openai' => 'OpenAI']);

        $options = (new EmbeddingProvider($registry, new ProviderOptionService($labels)))->toOptionArray();

        $serialized = json_encode($options);
        self::assertNotFalse($serialized);
        self::assertStringNotContainsString('api_key', $serialized);
        self::assertStringNotContainsString('secret', strtolower($serialized));
        self::assertStringNotContainsString('base_url', $serialized);
        self::assertStringNotContainsString('dimensions', $serialized);
    }
}