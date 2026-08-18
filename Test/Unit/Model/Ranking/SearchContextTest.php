<?php

declare(strict_types=1);

namespace Aavirbhava\AiShoppingAssistant\Test\Unit\Model\Ranking;

use Aavirbhava\AiShoppingAssistant\Model\Ranking\SearchContext;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(SearchContext::class)]
final class SearchContextTest extends TestCase
{
    public function testValidContext(): void
    {
        $context = new SearchContext(3, 'blue shoe', true);

        self::assertSame(3, $context->storeId);
        self::assertSame('blue shoe', $context->queryText);
        self::assertTrue($context->rerankerRequested);
    }

    public function testRejectsNonPositiveStoreId(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new SearchContext(0, 'blue shoe', false);
    }

    public function testRejectsEmptyQueryText(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new SearchContext(1, '', false);
    }
}
