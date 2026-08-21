<?php

declare(strict_types=1);

namespace Aavirbhava\AiShoppingAssistant\Model\Promotion;

use Aavirbhava\AiShoppingAssistant\Api\Promotion\CartPromotionInterface;
use Aavirbhava\AiShoppingAssistant\Model\Promotion\Exception\PromotionException;

final readonly class CartPromotion implements CartPromotionInterface
{
    public function __construct(
        private int $ruleId,
        private string $name,
        private bool $requiresCoupon,
        private ?string $couponCode,
        private string $discountDescription,
        private ?string $toDate
    ) {
        if ($this->ruleId < 1) {
            throw new PromotionException(__('A cart promotion requires a positive rule id.'));
        }

        if ($this->name === '') {
            throw new PromotionException(__('A cart promotion requires a non-empty name.'));
        }

        if (!$this->requiresCoupon && $this->couponCode !== null) {
            throw new PromotionException(
                __('An auto-applied cart promotion must not carry a coupon code.')
            );
        }

        if ($this->discountDescription === '') {
            throw new PromotionException(__('A cart promotion requires a non-empty discount description.'));
        }
    }

    public function ruleId(): int
    {
        return $this->ruleId;
    }

    public function name(): string
    {
        return $this->name;
    }

    public function requiresCoupon(): bool
    {
        return $this->requiresCoupon;
    }

    public function couponCode(): ?string
    {
        return $this->couponCode;
    }

    public function discountDescription(): string
    {
        return $this->discountDescription;
    }

    public function toDate(): ?string
    {
        return $this->toDate;
    }
}
