<?php

declare(strict_types=1);

namespace Aavirbhava\AiShoppingAssistant\Test\Unit\Model\Revalidation;

use Aavirbhava\AiShoppingAssistant\Model\Revalidation\RevalidatedProduct;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(RevalidatedProduct::class)]
final class RevalidatedProductTest extends TestCase
{
    public function testValidProduct(): void
    {
        $product = new RevalidatedProduct(1, 'SKU-1', 'Blue Shoe', 49.99, 39.99, 'https://store.test/blue-shoe', '2026-08-16T00:00:00+00:00');

        self::assertSame(1, $product->entityId);
        self::assertSame('SKU-1', $product->sku);
        self::assertSame(49.99, $product->price);
        self::assertSame(39.99, $product->specialPrice);
    }

    public function testAllowsANullSpecialPrice(): void
    {
        $product = new RevalidatedProduct(1, 'SKU-1', 'Blue Shoe', 49.99, null, 'https://store.test/x', '2026-08-16T00:00:00+00:00');

        self::assertNull($product->specialPrice);
    }

    public function testImageUrlDefaultsToNull(): void
    {
        $product = new RevalidatedProduct(1, 'SKU-1', 'Blue Shoe', 49.99, null, 'https://store.test/x', '2026-08-16T00:00:00+00:00');

        self::assertNull($product->imageUrl);
    }

    public function testAcceptsAnExplicitImageUrl(): void
    {
        $product = new RevalidatedProduct(
            1,
            'SKU-1',
            'Blue Shoe',
            49.99,
            null,
            'https://store.test/x',
            '2026-08-16T00:00:00+00:00',
            'https://store.test/media/catalog/product/cache/blue-shoe.jpg'
        );

        self::assertSame('https://store.test/media/catalog/product/cache/blue-shoe.jpg', $product->imageUrl);
    }

    public function testRejectsAnEmptyImageUrl(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new RevalidatedProduct(1, 'SKU-1', 'Name', 1.0, null, 'https://store.test/x', '2026-08-16T00:00:00+00:00', '');
    }

    public function testRejectsNonPositiveEntityId(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new RevalidatedProduct(0, 'SKU-1', 'Name', 1.0, null, 'https://store.test/x', '2026-08-16T00:00:00+00:00');
    }

    public function testRejectsEmptySku(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new RevalidatedProduct(1, '', 'Name', 1.0, null, 'https://store.test/x', '2026-08-16T00:00:00+00:00');
    }

    public function testRejectsNegativePrice(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new RevalidatedProduct(1, 'SKU-1', 'Name', -1.0, null, 'https://store.test/x', '2026-08-16T00:00:00+00:00');
    }

    public function testRejectsSpecialPriceAboveRegularPrice(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new RevalidatedProduct(1, 'SKU-1', 'Name', 10.0, 20.0, 'https://store.test/x', '2026-08-16T00:00:00+00:00');
    }

    public function testRejectsEmptyUrl(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new RevalidatedProduct(1, 'SKU-1', 'Name', 1.0, null, '', '2026-08-16T00:00:00+00:00');
    }

    public function testRejectsEmptyVerifiedAt(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new RevalidatedProduct(1, 'SKU-1', 'Name', 1.0, null, 'https://store.test/x', '');
    }
}
