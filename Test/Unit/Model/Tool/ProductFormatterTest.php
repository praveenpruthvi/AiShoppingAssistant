<?php

declare(strict_types=1);

namespace Aavirbhava\AiShoppingAssistant\Test\Unit\Model\Tool;

use Aavirbhava\AiShoppingAssistant\Model\Revalidation\RevalidatedProduct;
use Aavirbhava\AiShoppingAssistant\Model\Tool\ProductFormatter;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(ProductFormatter::class)]
final class ProductFormatterTest extends TestCase
{
    public function testFormatsEveryFieldFromTheRevalidatedProduct(): void
    {
        $product = new RevalidatedProduct(
            1,
            'SKU-1',
            'Blue Shoe',
            49.99,
            39.99,
            'https://store.test/blue-shoe',
            '2026-08-16T00:00:00+00:00'
        );

        $formatted = (new ProductFormatter())->format($product);

        self::assertSame([
            'sku' => 'SKU-1',
            'name' => 'Blue Shoe',
            'price' => 49.99,
            'special_price' => 39.99,
            'url' => 'https://store.test/blue-shoe',
        ], $formatted);
    }

    public function testNullSpecialPriceIsPreserved(): void
    {
        $product = new RevalidatedProduct(1, 'SKU-1', 'Blue Shoe', 49.99, null, 'https://store.test/blue-shoe', '2026-08-16T00:00:00+00:00');

        $formatted = (new ProductFormatter())->format($product);

        self::assertNull($formatted['special_price']);
    }
}
