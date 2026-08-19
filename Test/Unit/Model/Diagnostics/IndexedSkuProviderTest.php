<?php

declare(strict_types=1);

namespace Aavirbhava\AiShoppingAssistant\Test\Unit\Model\Diagnostics;

use Aavirbhava\AiShoppingAssistant\Api\Config\ConfigurationReaderInterface;
use Aavirbhava\AiShoppingAssistant\Api\Config\IndexingConfigInterface;
use Aavirbhava\AiShoppingAssistant\Api\Indexing\AssistantSearchClientInterface;
use Aavirbhava\AiShoppingAssistant\Api\Indexing\IndexNamingServiceInterface;
use Aavirbhava\AiShoppingAssistant\Api\Indexing\ProductIndexMappingInterface as Field;
use Aavirbhava\AiShoppingAssistant\Api\Store\StoreScopeInterface;
use Aavirbhava\AiShoppingAssistant\Model\Diagnostics\IndexedSkuProvider;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(IndexedSkuProvider::class)]
final class IndexedSkuProviderTest extends TestCase
{
    private function scope(): StoreScopeInterface
    {
        $scope = $this->createMock(StoreScopeInterface::class);
        $scope->method('storeId')->willReturn(1);

        return $scope;
    }

    private function configReader(): ConfigurationReaderInterface
    {
        $indexingConfig = $this->createMock(IndexingConfigInterface::class);
        $indexingConfig->method('indexPrefix')->willReturn('ai_product_rag');

        $reader = $this->createMock(ConfigurationReaderInterface::class);
        $reader->method('readIndexing')->with(1)->willReturn($indexingConfig);

        return $reader;
    }

    private function naming(string $alias): IndexNamingServiceInterface
    {
        $naming = $this->createMock(IndexNamingServiceInterface::class);
        $naming->method('readAlias')->willReturn($alias);

        return $naming;
    }

    public function testReturnsNullWhenNoAliasExistsYet(): void
    {
        $client = $this->createMock(AssistantSearchClientInterface::class);
        $client->method('aliasExists')->with('ai_product_rag_store_1_current')->willReturn(false);
        $client->expects(self::never())->method('search');

        $provider = new IndexedSkuProvider($this->configReader(), $this->naming('ai_product_rag_store_1_current'), $client);

        self::assertNull($provider->indexedSkus($this->scope()));
    }

    public function testReturnsDeduplicatedSkusFromEveryHit(): void
    {
        $client = $this->createMock(AssistantSearchClientInterface::class);
        $client->method('aliasExists')->willReturn(true);
        $client->method('search')->willReturn([
            ['_id' => 'd1', '_score' => 1.0, '_source' => [Field::FIELD_SKU => 'SKU-1']],
            ['_id' => 'd2', '_score' => 1.0, '_source' => [Field::FIELD_SKU => 'SKU-2']],
            ['_id' => 'd3', '_score' => 1.0, '_source' => [Field::FIELD_SKU => 'SKU-1']],
        ]);

        $provider = new IndexedSkuProvider($this->configReader(), $this->naming('ai_product_rag_store_1_current'), $client);

        self::assertSame(['SKU-1', 'SKU-2'], $provider->indexedSkus($this->scope()));
    }

    public function testSkipsAHitWithNoUsableSku(): void
    {
        $client = $this->createMock(AssistantSearchClientInterface::class);
        $client->method('aliasExists')->willReturn(true);
        $client->method('search')->willReturn([
            ['_id' => 'd1', '_score' => 1.0, '_source' => [Field::FIELD_SKU => 'SKU-1']],
            ['_id' => 'd2', '_score' => 1.0, '_source' => []],
        ]);

        $provider = new IndexedSkuProvider($this->configReader(), $this->naming('ai_product_rag_store_1_current'), $client);

        self::assertSame(['SKU-1'], $provider->indexedSkus($this->scope()));
    }
}
