<?php

declare(strict_types=1);

namespace Aavirbhava\AiShoppingAssistant\Model\Promotion;

use Aavirbhava\AiShoppingAssistant\Api\Promotion\ProductPromotionInterface;
use Aavirbhava\AiShoppingAssistant\Model\Promotion\Exception\PromotionException;

final readonly class ProductPromotion implements ProductPromotionInterface
{
    private float $percentOff;

    public function __construct(
        private string $sku,
        private float $regularPrice,
        private float $discountedPrice
    ) {
        if ($this->sku === '') {
            throw new PromotionException(__('A product promotion requires a non-empty SKU.'));
        }

        if ($this->regularPrice <= 0.0) {
            throw new PromotionException(__('A product promotion requires a positive regular price.'));
        }

        if ($this->discountedPrice < 0.0 || $this->discountedPrice >= $this->regularPrice) {
            throw new PromotionException(
                __('A product promotion\'s discounted price must be lower than its regular price.')
            );
        }

        $this->percentOff = round(
            ($this->regularPrice - $this->discountedPrice) / $this->regularPrice * 100
        );
    }

    public function sku(): string
    {
        return $this->sku;
    }

    public function regularPrice(): float
    {
        return $this->regularPrice;
    }

    public function discountedPrice(): float
    {
        return $this->discountedPrice;
    }

    public function percentOff(): float
    {
        return $this->percentOff;
    }
}
