<?php

declare(strict_types=1);

namespace Aavirbhava\AiShoppingAssistant\Test\Unit\Model\Chat\Response;

use Aavirbhava\AiShoppingAssistant\Model\Chat\Response\ProductResult;
use Aavirbhava\AiShoppingAssistant\Model\Revalidation\RevalidatedProduct;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(ProductResult::class)]
final class ProductResultTest extends TestCase
{
    private function product(): RevalidatedProduct
    {
        return new RevalidatedProduct(1, 'SKU-1', 'Blue Shoe', 49.99, null, 'https://store.test/blue-shoe', '2026-08-16T00:00:00+00:00');
    }

    public function testDefaultsToOrganicRecommendationType(): void
    {
        $result = new ProductResult($this->product(), 'A good fit.');

        self::assertSame('organic', $result->recommendationType);
    }

    public function testRejectsAnUnsupportedRecommendationType(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new ProductResult($this->product(), 'A good fit.', 'sponsored');
    }

    public function testAcceptsThePhase2VocabularyValues(): void
    {
        $recommended = new ProductResult($this->product(), 'r', ProductResult::TYPE_RECOMMENDED);
        $promoted = new ProductResult($this->product(), 'r', ProductResult::TYPE_PROMOTED);

        self::assertSame('recommended', $recommended->recommendationType);
        self::assertSame('promoted', $promoted->recommendationType);
    }

    public function testExposesTheUnderlyingRevalidatedProduct(): void
    {
        $product = $this->product();
        $result = new ProductResult($product, 'A good fit.');

        self::assertSame($product, $result->product);
        self::assertSame('SKU-1', $result->product->sku);
        self::assertSame(49.99, $result->product->price);
    }
}
