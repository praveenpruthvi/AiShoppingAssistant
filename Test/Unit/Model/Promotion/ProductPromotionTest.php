<?php

declare(strict_types=1);

namespace Aavirbhava\AiShoppingAssistant\Test\Unit\Model\Promotion;

use Aavirbhava\AiShoppingAssistant\Model\Promotion\Exception\PromotionException;
use Aavirbhava\AiShoppingAssistant\Model\Promotion\ProductPromotion;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(ProductPromotion::class)]
final class ProductPromotionTest extends TestCase
{
    public function testComputesPercentOffRoundedToTheNearestWholePercent(): void
    {
        $promotion = new ProductPromotion('SKU-1', 50.00, 40.00);

        self::assertSame('SKU-1', $promotion->sku());
        self::assertSame(50.00, $promotion->regularPrice());
        self::assertSame(40.00, $promotion->discountedPrice());
        self::assertSame(20.0, $promotion->percentOff());
    }

    public function testRoundsAnUnevenPercentageToTheNearestWholeNumber(): void
    {
        // (30 - 20) / 30 = 33.33...% -> 33%
        $promotion = new ProductPromotion('SKU-1', 30.00, 20.00);

        self::assertSame(33.0, $promotion->percentOff());
    }

    public function testRejectsAnEmptySku(): void
    {
        $this->expectException(PromotionException::class);

        new ProductPromotion('', 50.00, 40.00);
    }

    public function testRejectsANonPositiveRegularPrice(): void
    {
        $this->expectException(PromotionException::class);

        new ProductPromotion('SKU-1', 0.0, -1.0);
    }

    public function testRejectsADiscountedPriceAtOrAboveTheRegularPrice(): void
    {
        $this->expectException(PromotionException::class);

        new ProductPromotion('SKU-1', 50.00, 50.00);
    }

    public function testRejectsANegativeDiscountedPrice(): void
    {
        $this->expectException(PromotionException::class);

        new ProductPromotion('SKU-1', 50.00, -1.0);
    }
}
