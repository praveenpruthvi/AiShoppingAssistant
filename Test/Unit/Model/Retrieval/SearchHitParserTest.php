<?php

declare(strict_types=1);

namespace Aavirbhava\AiShoppingAssistant\Test\Unit\Model\Retrieval;

use Aavirbhava\AiShoppingAssistant\Model\Indexing\Exception\SearchResponseInvalidException;
use Aavirbhava\AiShoppingAssistant\Model\Retrieval\SearchHitParser;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(SearchHitParser::class)]
final class SearchHitParserTest extends TestCase
{
    private function validSource(): array
    {
        return [
            'entity_id' => 42,
            'sku' => 'SKU-42',
            'store_id' => '1',
            'name' => 'Blue Shoe',
            'short_description' => 'A comfortable blue shoe.',
            'categories' => [
                ['category_id' => 5, 'name' => 'Shoes', 'path' => '1/2/5'],
            ],
            'attributes' => [
                ['code' => 'color', 'label' => 'Color', 'values' => ['Blue']],
            ],
            'is_enabled' => true,
            'visibility' => 4,
        ];
    }

    public function testParsesAValidSourceIntoACandidate(): void
    {
        $parser = new SearchHitParser();

        $candidate = $parser->parse($this->validSource(), 1.5, 0.8);

        self::assertSame(42, $candidate->entityId);
        self::assertSame('SKU-42', $candidate->sku);
        self::assertSame(1, $candidate->storeId);
        self::assertSame('Blue Shoe', $candidate->name);
        self::assertSame(['Shoes'], $candidate->categoryNames);
        self::assertSame([['code' => 'color', 'label' => 'Color', 'values' => ['Blue']]], $candidate->attributes);
        self::assertTrue($candidate->isEnabled);
        self::assertSame(4, $candidate->visibility);
        self::assertSame(1.5, $candidate->bm25Score);
        self::assertSame(0.8, $candidate->vectorScore);
        self::assertSame(0.0, $candidate->score);
    }

    public function testDefaultsMissingOptionalFields(): void
    {
        $parser = new SearchHitParser();
        $source = $this->validSource();
        unset($source['short_description'], $source['categories'], $source['attributes']);

        $candidate = $parser->parse($source, 0.0, 0.0);

        self::assertSame('', $candidate->shortDescription);
        self::assertSame([], $candidate->categoryNames);
        self::assertSame([], $candidate->attributes);
    }

    public function testRejectsMissingEntityId(): void
    {
        $parser = new SearchHitParser();
        $source = $this->validSource();
        unset($source['entity_id']);

        $this->expectException(SearchResponseInvalidException::class);
        $parser->parse($source, 0.0, 0.0);
    }

    public function testRejectsEmptySku(): void
    {
        $parser = new SearchHitParser();
        $source = $this->validSource();
        $source['sku'] = '';

        $this->expectException(SearchResponseInvalidException::class);
        $parser->parse($source, 0.0, 0.0);
    }

    public function testRejectsMalformedCategoryEntry(): void
    {
        $parser = new SearchHitParser();
        $source = $this->validSource();
        $source['categories'] = [['path' => '1/2/5']];

        $this->expectException(SearchResponseInvalidException::class);
        $parser->parse($source, 0.0, 0.0);
    }

    public function testRejectsMalformedAttributeEntry(): void
    {
        $parser = new SearchHitParser();
        $source = $this->validSource();
        $source['attributes'] = [['code' => 'color']];

        $this->expectException(SearchResponseInvalidException::class);
        $parser->parse($source, 0.0, 0.0);
    }

    public function testRejectsNonBooleanIsEnabled(): void
    {
        $parser = new SearchHitParser();
        $source = $this->validSource();
        $source['is_enabled'] = 1;

        $this->expectException(SearchResponseInvalidException::class);
        $parser->parse($source, 0.0, 0.0);
    }
}
