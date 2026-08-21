<?php

declare(strict_types=1);

namespace Aavirbhava\AiShoppingAssistant\Model\Promotion;

use Aavirbhava\AiShoppingAssistant\Api\Promotion\ActivePromotionReaderInterface;
use Aavirbhava\AiShoppingAssistant\Api\Promotion\CartPromotionInterface;
use Aavirbhava\AiShoppingAssistant\Api\Promotion\ProductPromotionInterface;
use Aavirbhava\AiShoppingAssistant\Api\Store\StoreScopeProviderInterface;
use Aavirbhava\AiShoppingAssistant\Model\Indexing\Clock\ClockInterface;
use Aavirbhava\AiShoppingAssistant\Model\Revalidation\RevalidatedProduct;
use Magento\CatalogRule\Model\ResourceModel\Rule as CatalogRuleResource;
use Magento\Customer\Model\Group;
use Magento\SalesRule\Model\Rule as CartRule;
use Magento\SalesRule\Model\ResourceModel\Rule\CollectionFactory as CartRuleCollectionFactory;

/**
 * Reads real, currently-active promotions live from Magento — Catalog
 * Price Rules via Magento's own precomputed catalogrule_product_price
 * table (Magento\CatalogRule\Model\ResourceModel\Rule::getRulePrices(),
 * the same live-price API Magento's own pricing framework uses, kept
 * fresh by Magento's OWN catalog rule indexer — this class runs no
 * indexer of its own), Cart Price Rules via
 * Magento\SalesRule\Model\ResourceModel\Rule\Collection::
 * addWebsiteGroupDateFilter() (Magento's own real "active, in-date-range,
 * applicable to this website + customer group" filter, the same one
 * cart-rule application itself is built on).
 */
final class ActivePromotionReader implements ActivePromotionReaderInterface
{
    public function __construct(
        private readonly StoreScopeProviderInterface $storeScopeProvider,
        private readonly ClockInterface $clock,
        private readonly CatalogRuleResource $catalogRuleResource,
        private readonly CartRuleCollectionFactory $cartRuleCollectionFactory
    ) {
    }

    public function catalogRuleDiscounts(int $storeId, ?int $customerGroupId, array $products): array
    {
        if ($products === []) {
            return [];
        }

        $scope = $this->storeScopeProvider->requireActive($storeId);
        $resolvedGroupId = $customerGroupId ?? Group::NOT_LOGGED_IN_ID;

        $byEntityId = [];
        foreach ($products as $product) {
            $byEntityId[$product->entityId] = $product;
        }

        $rulePrices = $this->catalogRuleResource->getRulePrices(
            $this->clock->now(),
            $scope->websiteId(),
            $resolvedGroupId,
            array_keys($byEntityId)
        );

        $result = [];
        foreach ($rulePrices as $entityId => $rulePrice) {
            $product = $byEntityId[(int) $entityId] ?? null;
            $rulePrice = (float) $rulePrice;

            // A rule price at or above the regular price is not a real
            // discount (Magento can carry a rule row with no effective
            // reduction) — never reported as a promotion.
            if ($product === null || $rulePrice <= 0.0 || $rulePrice >= $product->price) {
                continue;
            }

            $result[$product->sku] = new ProductPromotion($product->sku, $product->price, $rulePrice);
        }

        return $result;
    }

    public function activeCartRules(int $storeId, ?int $customerGroupId): array
    {
        $scope = $this->storeScopeProvider->requireActive($storeId);
        $resolvedGroupId = $customerGroupId ?? Group::NOT_LOGGED_IN_ID;
        $now = $this->clock->now()->format('Y-m-d');

        $collection = $this->cartRuleCollectionFactory->create();
        $collection->addWebsiteGroupDateFilter($scope->websiteId(), $resolvedGroupId, $now);

        $result = [];
        foreach ($collection as $rule) {
            /** @var CartRule $rule */
            $result[] = $this->toCartPromotion($rule);
        }

        return $result;
    }

    private function toCartPromotion(CartRule $rule): CartPromotionInterface
    {
        $couponType = (int) $rule->getCouponType();
        $requiresCoupon = $couponType !== CartRule::COUPON_TYPE_NO_COUPON;

        $couponCode = null;
        if ($couponType === CartRule::COUPON_TYPE_SPECIFIC) {
            $code = $rule->getPrimaryCoupon()->getCode();
            $couponCode = is_string($code) && $code !== '' ? $code : null;
        }

        return new CartPromotion(
            (int) $rule->getId(),
            (string) $rule->getName(),
            $requiresCoupon,
            $couponCode,
            $this->describeDiscount($rule),
            $this->normalizeDate($rule->getToDate())
        );
    }

    /**
     * A short, real, human-readable statement of what the rule actually
     * does — derived only from its own simple_action/discount_amount
     * fields, never invented text. buy_x_get_y is described in terms of
     * its own real discount_step/discount_amount values rather than a
     * fully-simulated "you'd save $N" figure, since that depends on a
     * real cart's actual contents, which this class deliberately does
     * not evaluate (see this class's own docblock).
     */
    private function describeDiscount(CartRule $rule): string
    {
        $amount = (float) $rule->getDiscountAmount();
        $freeShipping = (int) $rule->getSimpleFreeShipping() > 0;

        // A rule with a zero item-level amount and free shipping enabled
        // is a pure free-shipping rule — describing it as "0% off" would
        // be technically true (it matches the real discount_amount) but
        // uninformative, so it's described by what it actually does.
        if ($amount <= 0.0 && $freeShipping) {
            return 'free shipping';
        }

        $description = match ($rule->getSimpleAction()) {
            CartRule::BY_PERCENT_ACTION => $this->trimTrailingZeros($amount) . '% off',
            CartRule::BY_FIXED_ACTION => '$' . $this->trimTrailingZeros($amount) . ' off each qualifying item',
            CartRule::CART_FIXED_ACTION => '$' . $this->trimTrailingZeros($amount) . ' off the order',
            CartRule::BUY_X_GET_Y_ACTION => 'buy ' . (int) $rule->getDiscountStep()
                . ', get ' . (int) $amount . ' free',
            default => 'a special discount',
        };

        return $freeShipping ? $description . ' + free shipping' : $description;
    }

    private function trimTrailingZeros(float $value): string
    {
        return rtrim(rtrim(number_format($value, 2, '.', ''), '0'), '.');
    }

    private function normalizeDate(mixed $toDate): ?string
    {
        return is_string($toDate) && $toDate !== '' ? $toDate : null;
    }
}
