<?php

declare(strict_types=1);

namespace Aavirbhava\AiShoppingAssistant\Test\Unit\Model\Tool;

use Aavirbhava\AiShoppingAssistant\Model\Tool\StoreContentMatch;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(StoreContentMatch::class)]
final class StoreContentMatchTest extends TestCase
{
    public function testRejectsAnUnknownType(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new StoreContentMatch('unknown_type', '1', 'Title', 'Snippet');
    }

    public function testRejectsAnEmptyId(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new StoreContentMatch(StoreContentMatch::TYPE_CMS_PAGE, '', 'Title', 'Snippet');
    }

    public function testToArrayOmitsNullFieldsForACmsPage(): void
    {
        $match = new StoreContentMatch(StoreContentMatch::TYPE_CMS_PAGE, '6', 'Customer Service', 'Delivery and returns...');

        self::assertSame(
            [
                'type' => 'cms_page',
                'id' => '6',
                'title' => 'Customer Service',
                'snippet' => 'Delivery and returns...',
            ],
            $match->toArray()
        );
    }

    public function testToArrayIncludesPriceFieldsForAProduct(): void
    {
        $match = new StoreContentMatch(
            StoreContentMatch::TYPE_PRODUCT,
            '24-MB01',
            'Joust Duffle Bag',
            '',
            'https://store.test/joust-duffle-bag',
            34.0,
            29.0
        );

        self::assertSame(
            [
                'type' => 'product',
                'id' => '24-MB01',
                'title' => 'Joust Duffle Bag',
                'snippet' => '',
                'url' => 'https://store.test/joust-duffle-bag',
                'price' => 34.0,
                'special_price' => 29.0,
            ],
            $match->toArray()
        );
    }
}
