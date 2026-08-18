<?php

declare(strict_types=1);

namespace Aavirbhava\AiShoppingAssistant\Test\Unit\Model\Retrieval;

use Aavirbhava\AiShoppingAssistant\Model\Retrieval\SearchQueryBuilder;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(SearchQueryBuilder::class)]
final class SearchQueryBuilderTest extends TestCase
{
    public function testKeywordQueryHasSizeAndStoreScopeFilter(): void
    {
        $builder = new SearchQueryBuilder();

        $body = $builder->keyword(3, 'blue shoe', 50);

        self::assertSame(50, $body['size']);
        self::assertSame(
            [
                ['term' => ['store_id' => '3']],
                ['term' => ['is_enabled' => true]],
            ],
            $body['query']['bool']['filter']
        );
    }

    public function testKeywordQuerySearchesTextFieldsAndNestedFields(): void
    {
        $builder = new SearchQueryBuilder();

        $body = $builder->keyword(1, 'blue shoe', 10);

        $should = $body['query']['bool']['should'];
        self::assertSame('blue shoe', $should[0]['multi_match']['query']);
        self::assertContains('name^3', $should[0]['multi_match']['fields']);
        self::assertContains('searchable_text^2', $should[0]['multi_match']['fields']);

        self::assertSame('categories', $should[1]['nested']['path']);
        self::assertSame('blue shoe', $should[1]['nested']['query']['match']['categories.name']);

        self::assertSame('attributes', $should[2]['nested']['path']);
        self::assertSame('blue shoe', $should[2]['nested']['query']['match']['attributes.values']);

        self::assertSame(1, $body['query']['bool']['minimum_should_match']);
    }

    public function testVectorQueryHasSizeVectorKAndEfficientFilter(): void
    {
        $builder = new SearchQueryBuilder();

        $body = $builder->vector(3, [0.1, 0.2, 0.3], 25);

        self::assertSame(25, $body['size']);
        self::assertSame([0.1, 0.2, 0.3], $body['query']['knn']['embedding']['vector']);
        self::assertSame(25, $body['query']['knn']['embedding']['k']);
        self::assertSame(
            [
                ['term' => ['store_id' => '3']],
                ['term' => ['is_enabled' => true]],
            ],
            $body['query']['knn']['embedding']['filter']['bool']['filter']
        );
    }

    public function testSourceFieldsExcludeEmbeddingAndHashFields(): void
    {
        $builder = new SearchQueryBuilder();

        $body = $builder->keyword(1, 'phone', 10);

        self::assertNotContains('embedding', $body['_source']);
        self::assertNotContains('embedding_hash', $body['_source']);
        self::assertContains('entity_id', $body['_source']);
        self::assertContains('sku', $body['_source']);
    }
}
