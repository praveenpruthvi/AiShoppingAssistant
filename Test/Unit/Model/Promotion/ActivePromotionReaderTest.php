<?php

declare(strict_types=1);

namespace Aavirbhava\AiShoppingAssistant\Test\Unit\Model\Promotion;

use Aavirbhava\AiShoppingAssistant\Api\Store\StoreScopeInterface;
use Aavirbhava\AiShoppingAssistant\Api\Store\StoreScopeProviderInterface;
use Aavirbhava\AiShoppingAssistant\Model\Indexing\Clock\ClockInterface;
use Aavirbhava\AiShoppingAssistant\Model\Promotion\ActivePromotionReader;
use Aavirbhava\AiShoppingAssistant\Model\Revalidation\RevalidatedProduct;
use Magento\CatalogRule\Model\ResourceModel\Rule as CatalogRuleResource;
use Magento\Customer\Model\Group;
use Magento\SalesRule\Model\Coupon;
use Magento\SalesRule\Model\ResourceModel\Rule\Collection as CartRuleCollection;
use Magento\SalesRule\Model\ResourceModel\Rule\CollectionFactory as CartRuleCollectionFactory;
use Magento\SalesRule\Model\Rule as CartRule;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(ActivePromotionReader::class)]
final class ActivePromotionReaderTest extends TestCase
{
    private const STORE_ID = 1;
    private const WEBSITE_ID = 1;

    public function testCatalogRuleDiscountsMapsARealDiscountedRulePrice(): void
    {
        $product = new RevalidatedProduct(10, 'SKU-1', 'Blue Shoe', 50.00, null, 'https://store.test/s', '2026-01-01T00:00:00+00:00');
        $catalogRuleResource = $this->createMock(CatalogRuleResource::class);
        $catalogRuleResource->method('getRulePrices')->willReturn([10 => '40.0000']);

        $reader = $this->reader($catalogRuleResource);

        $result = $reader->catalogRuleDiscounts(self::STORE_ID, null, [$product]);

        self::assertArrayHasKey('SKU-1', $result);
        self::assertSame(50.00, $result['SKU-1']->regularPrice());
        self::assertSame(40.00, $result['SKU-1']->discountedPrice());
        self::assertSame(20.0, $result['SKU-1']->percentOff());
    }

    public function testCatalogRuleDiscountsOmitsAProductWithNoRulePriceEntry(): void
    {
        $product = new RevalidatedProduct(10, 'SKU-1', 'Blue Shoe', 50.00, null, 'https://store.test/s', '2026-01-01T00:00:00+00:00');
        $catalogRuleResource = $this->createMock(CatalogRuleResource::class);
        $catalogRuleResource->method('getRulePrices')->willReturn([]);

        $reader = $this->reader($catalogRuleResource);

        self::assertSame([], $reader->catalogRuleDiscounts(self::STORE_ID, null, [$product]));
    }

    public function testCatalogRuleDiscountsOmitsARulePriceThatIsNotActuallyLowerThanRegular(): void
    {
        // A real, confirmed Magento quirk this class must defend against:
        // a rule row can exist with no effective reduction.
        $product = new RevalidatedProduct(10, 'SKU-1', 'Blue Shoe', 50.00, null, 'https://store.test/s', '2026-01-01T00:00:00+00:00');
        $catalogRuleResource = $this->createMock(CatalogRuleResource::class);
        $catalogRuleResource->method('getRulePrices')->willReturn([10 => '50.0000']);

        $reader = $this->reader($catalogRuleResource);

        self::assertSame([], $reader->catalogRuleDiscounts(self::STORE_ID, null, [$product]));
    }

    public function testCatalogRuleDiscountsIsEmptyForAnEmptyProductList(): void
    {
        $catalogRuleResource = $this->createMock(CatalogRuleResource::class);
        $catalogRuleResource->expects(self::never())->method('getRulePrices');

        $reader = $this->reader($catalogRuleResource);

        self::assertSame([], $reader->catalogRuleDiscounts(self::STORE_ID, null, []));
    }

    public function testCatalogRuleDiscountsResolvesANullCustomerGroupToNotLoggedIn(): void
    {
        $product = new RevalidatedProduct(10, 'SKU-1', 'Blue Shoe', 50.00, null, 'https://store.test/s', '2026-01-01T00:00:00+00:00');
        $catalogRuleResource = $this->createMock(CatalogRuleResource::class);
        $catalogRuleResource->expects(self::once())
            ->method('getRulePrices')
            ->with(self::isInstanceOf(\DateTimeInterface::class), self::WEBSITE_ID, Group::NOT_LOGGED_IN_ID, [10])
            ->willReturn([]);

        $reader = $this->reader($catalogRuleResource);

        $reader->catalogRuleDiscounts(self::STORE_ID, null, [$product]);
    }

    public function testActiveCartRulesDistinguishesAutoAppliedFromCouponRequired(): void
    {
        $autoRule = $this->cartRuleMock(1, 'Storewide Sale', CartRule::COUPON_TYPE_NO_COUPON, CartRule::BY_PERCENT_ACTION, 15.0);
        $couponRule = $this->cartRuleMock(2, 'Summer Sale', CartRule::COUPON_TYPE_SPECIFIC, CartRule::BY_PERCENT_ACTION, 10.0);
        $coupon = $this->createMock(Coupon::class);
        $coupon->method('getCode')->willReturn('SUMMER10');
        $couponRule->method('getPrimaryCoupon')->willReturn($coupon);

        $reader = $this->reader($this->createMock(CatalogRuleResource::class), [$autoRule, $couponRule]);

        $result = $reader->activeCartRules(self::STORE_ID, null);

        self::assertCount(2, $result);
        self::assertFalse($result[0]->requiresCoupon());
        self::assertNull($result[0]->couponCode());
        self::assertTrue($result[1]->requiresCoupon());
        self::assertSame('SUMMER10', $result[1]->couponCode());
    }

    public function testActiveCartRulesWithAutoGeneratedCouponsRequiresACouponButGivesNoSingleCode(): void
    {
        $rule = $this->cartRuleMock(3, 'Loyalty Reward', CartRule::COUPON_TYPE_AUTO, CartRule::CART_FIXED_ACTION, 5.0);

        $reader = $this->reader($this->createMock(CatalogRuleResource::class), [$rule]);

        $result = $reader->activeCartRules(self::STORE_ID, null);

        self::assertTrue($result[0]->requiresCoupon());
        self::assertNull($result[0]->couponCode());
    }

    public function testActiveCartRulesDescribesEachSimpleActionType(): void
    {
        $percent = $this->cartRuleMock(1, 'A', CartRule::COUPON_TYPE_NO_COUPON, CartRule::BY_PERCENT_ACTION, 15.0);
        $fixed = $this->cartRuleMock(2, 'B', CartRule::COUPON_TYPE_NO_COUPON, CartRule::BY_FIXED_ACTION, 5.0);
        $cartFixed = $this->cartRuleMock(3, 'C', CartRule::COUPON_TYPE_NO_COUPON, CartRule::CART_FIXED_ACTION, 10.0);

        $reader = $this->reader($this->createMock(CatalogRuleResource::class), [$percent, $fixed, $cartFixed]);

        $result = $reader->activeCartRules(self::STORE_ID, null);

        self::assertSame('15% off', $result[0]->discountDescription());
        self::assertSame('$5 off each qualifying item', $result[1]->discountDescription());
        self::assertSame('$10 off the order', $result[2]->discountDescription());
    }

    public function testActiveCartRulesDescribesAFreeShippingOnlyRuleAsFreeShippingNotZeroPercentOff(): void
    {
        $freeShippingOnly = $this->cartRuleMock(1, 'Free Ship', CartRule::COUPON_TYPE_NO_COUPON, CartRule::BY_PERCENT_ACTION, 0.0, 2);
        $percentPlusFreeShipping = $this->cartRuleMock(2, 'Percent + Ship', CartRule::COUPON_TYPE_NO_COUPON, CartRule::BY_PERCENT_ACTION, 15.0, 1);

        $reader = $this->reader($this->createMock(CatalogRuleResource::class), [$freeShippingOnly, $percentPlusFreeShipping]);

        $result = $reader->activeCartRules(self::STORE_ID, null);

        self::assertSame('free shipping', $result[0]->discountDescription());
        self::assertSame('15% off + free shipping', $result[1]->discountDescription());
    }

    public function testActiveCartRulesAppliesTheRealWebsiteAndGroupFilter(): void
    {
        $collection = $this->createMock(CartRuleCollection::class);
        $collection->expects(self::once())
            ->method('addWebsiteGroupDateFilter')
            ->with(self::WEBSITE_ID, Group::NOT_LOGGED_IN_ID, self::isType('string'))
            ->willReturnSelf();
        $collection->method('getIterator')->willReturn(new \ArrayIterator([]));

        $collectionFactory = $this->createMock(CartRuleCollectionFactory::class);
        $collectionFactory->method('create')->willReturn($collection);

        $reader = new ActivePromotionReader(
            $this->storeScopeProvider(),
            $this->clock(),
            $this->createMock(CatalogRuleResource::class),
            $collectionFactory
        );

        $reader->activeCartRules(self::STORE_ID, null);
    }

    /**
     * Magento\SalesRule\Model\Rule's name/coupon_type/simple_action/
     * discount_amount/discount_step/to_date getters are all magic
     * __call()-based (proxying AbstractModel::getData()), not real
     * declared methods — PHPUnit's createMock() cannot stub a method
     * that reflection cannot see exists (confirmed live:
     * MethodCannotBeConfiguredException). The correct way to test a
     * magic-getter Magento model is a real instance with the
     * constructor disabled, using the real, inherited setData()/getId()
     * — only getPrimaryCoupon() (a real declared method) is mocked.
     */
    private function cartRuleMock(
        int $id,
        string $name,
        int $couponType,
        string $simpleAction,
        float $discountAmount,
        int $simpleFreeShipping = 0
    ): CartRule {
        $rule = $this->getMockBuilder(CartRule::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['getPrimaryCoupon'])
            ->getMock();

        $rule->setData([
            'rule_id' => $id,
            'id' => $id,
            'name' => $name,
            'coupon_type' => $couponType,
            'simple_action' => $simpleAction,
            'discount_amount' => $discountAmount,
            'discount_step' => 0,
            'simple_free_shipping' => $simpleFreeShipping,
            'to_date' => null,
        ]);

        return $rule;
    }

    /**
     * @param list<CartRule> $cartRules
     */
    private function reader(CatalogRuleResource $catalogRuleResource, array $cartRules = []): ActivePromotionReader
    {
        $collection = $this->createMock(CartRuleCollection::class);
        $collection->method('addWebsiteGroupDateFilter')->willReturnSelf();
        $collection->method('getIterator')->willReturn(new \ArrayIterator($cartRules));

        $collectionFactory = $this->createMock(CartRuleCollectionFactory::class);
        $collectionFactory->method('create')->willReturn($collection);

        return new ActivePromotionReader(
            $this->storeScopeProvider(),
            $this->clock(),
            $catalogRuleResource,
            $collectionFactory
        );
    }

    private function storeScopeProvider(): StoreScopeProviderInterface
    {
        $scope = $this->createMock(StoreScopeInterface::class);
        $scope->method('storeId')->willReturn(self::STORE_ID);
        $scope->method('websiteId')->willReturn(self::WEBSITE_ID);

        $provider = $this->createMock(StoreScopeProviderInterface::class);
        $provider->method('requireActive')->with(self::STORE_ID)->willReturn($scope);

        return $provider;
    }

    private function clock(): ClockInterface
    {
        $clock = $this->createMock(ClockInterface::class);
        $clock->method('now')->willReturn(new \DateTimeImmutable('2026-08-21 12:00:00'));

        return $clock;
    }
}
