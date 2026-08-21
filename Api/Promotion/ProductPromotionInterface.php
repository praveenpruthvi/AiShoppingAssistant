<?php

declare(strict_types=1);

namespace Aavirbhava\AiShoppingAssistant\Api\Promotion;

/**
 * One product's real, currently-active Catalog Price Rule discount —
 * only ever returned for a product that genuinely has one; a product
 * with no active catalog rule simply has no entry, never a zero-valued
 * placeholder (mirrors ActiveBoostReaderInterface's own convention).
 */
interface ProductPromotionInterface
{
    public function sku(): string;

    /**
     * The product's regular price, before this discount.
     */
    public function regularPrice(): float;

    /**
     * The real, live catalog-rule-adjusted price — always less than
     * regularPrice() (a promotion is never a markup).
     */
    public function discountedPrice(): float;

    /**
     * (regularPrice - discountedPrice) / regularPrice * 100, rounded to
     * the nearest whole percent — the figure a response is allowed to
     * state as "X% off".
     */
    public function percentOff(): float;
}
