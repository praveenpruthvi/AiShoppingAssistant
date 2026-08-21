<?php

declare(strict_types=1);

namespace Aavirbhava\AiShoppingAssistant\Api\Promotion;

/**
 * One real, currently-active Cart Price Rule, scoped to a store's
 * website + customer group. Deliberately does not attempt to evaluate
 * the rule's own condition tree against a real cart/quote (that is
 * Magento\SalesRule\Model\Validator's job, a much heavier live-quote
 * operation this module has no cart-mutation reason to duplicate) — this
 * only reports the rule's own definition: whether it applies
 * automatically or needs a coupon, its discount, and its own end date.
 * A shopper is still told the truth ("this rule may apply, here's what
 * it does") without this module claiming a specific order total's
 * final price, which only a real cart evaluation could certify.
 */
interface CartPromotionInterface
{
    public function ruleId(): int;

    public function name(): string;

    /**
     * True when this rule requires a coupon code to apply (Magento's
     * COUPON_TYPE_SPECIFIC or COUPON_TYPE_AUTO); false when it applies
     * automatically to every qualifying order (COUPON_TYPE_NO_COUPON).
     * Never collapsed into a single boolean elsewhere — a response must
     * be able to say "already applied" vs "use this code" distinctly.
     */
    public function requiresCoupon(): bool;

    /**
     * The one real, fixed coupon code for a COUPON_TYPE_SPECIFIC rule.
     * Null for an auto-applied rule (requiresCoupon() === false) AND for
     * an auto-GENERATED-coupon rule (COUPON_TYPE_AUTO) — that type has
     * many distinct per-use codes with no single universal code to
     * state, so requiresCoupon() is still true but no couponCode() is
     * ever given (never guessed).
     */
    public function couponCode(): ?string;

    /**
     * A short, human-readable statement of what the rule actually does
     * (e.g. "15% off" or "$10 off"), derived only from the rule's own
     * real simple_action/discount_amount fields — never invented text.
     */
    public function discountDescription(): string;

    /**
     * MySQL date string (Y-m-d), or null when the rule has no end date.
     */
    public function toDate(): ?string;
}
