<?php

declare(strict_types=1);

namespace Aavirbhava\AiShoppingAssistant\Test\Unit\Model\Tool;

use Aavirbhava\AiShoppingAssistant\Model\Tool\NullBlogContentSearcher;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(NullBlogContentSearcher::class)]
final class NullBlogContentSearcherTest extends TestCase
{
    public function testAlwaysReturnsAnEmptyList(): void
    {
        $searcher = new NullBlogContentSearcher();

        self::assertSame([], $searcher->search(1, 'waterproofing', 5));
    }
}
