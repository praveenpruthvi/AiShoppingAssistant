<?php

declare(strict_types=1);

namespace Aavirbhava\AiShoppingAssistant\Test\Unit\Model\Promotion;

use Aavirbhava\AiShoppingAssistant\Model\Promotion\CartPromotion;
use Aavirbhava\AiShoppingAssistant\Model\Promotion\Exception\PromotionException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(CartPromotion::class)]
final class CartPromotionTest extends TestCase
{
    public function testAutoAppliedRuleCarriesNoCouponCode(): void
    {
        $promotion = new CartPromotion(1, 'Spring Sale', false, null, '15% off', null);

        self::assertSame(1, $promotion->ruleId());
        self::assertSame('Spring Sale', $promotion->name());
        self::assertFalse($promotion->requiresCoupon());
        self::assertNull($promotion->couponCode());
        self::assertSame('15% off', $promotion->discountDescription());
        self::assertNull($promotion->toDate());
    }

    public function testCouponRequiredRuleCarriesItsRealCode(): void
    {
        $promotion = new CartPromotion(2, 'Summer Sale', true, 'SUMMER10', '10% off', '2026-09-01');

        self::assertTrue($promotion->requiresCoupon());
        self::assertSame('SUMMER10', $promotion->couponCode());
        self::assertSame('2026-09-01', $promotion->toDate());
    }

    public function testCouponRequiredRuleMayHaveNoSingleCode(): void
    {
        // COUPON_TYPE_AUTO (many auto-generated per-use codes) — still
        // requires a coupon, but there is no one universal code to give.
        $promotion = new CartPromotion(3, 'Loyalty Reward', true, null, '$5 off', null);

        self::assertTrue($promotion->requiresCoupon());
        self::assertNull($promotion->couponCode());
    }

    public function testRejectsAnAutoAppliedRuleCarryingACouponCode(): void
    {
        $this->expectException(PromotionException::class);

        new CartPromotion(1, 'Spring Sale', false, 'SHOULDNOTEXIST', '15% off', null);
    }

    public function testRejectsANonPositiveRuleId(): void
    {
        $this->expectException(PromotionException::class);

        new CartPromotion(0, 'Spring Sale', false, null, '15% off', null);
    }

    public function testRejectsAnEmptyName(): void
    {
        $this->expectException(PromotionException::class);

        new CartPromotion(1, '', false, null, '15% off', null);
    }

    public function testRejectsAnEmptyDiscountDescription(): void
    {
        $this->expectException(PromotionException::class);

        new CartPromotion(1, 'Spring Sale', false, null, '', null);
    }
}
