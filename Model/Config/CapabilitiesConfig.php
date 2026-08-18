<?php

declare(strict_types=1);

namespace Aavirbhava\AiShoppingAssistant\Model\Config;

use Aavirbhava\AiShoppingAssistant\Api\Config\CapabilitiesConfigInterface;

final readonly class CapabilitiesConfig implements CapabilitiesConfigInterface
{
    public function __construct(
        private bool $productDiscoveryEnabled,
        private bool $productDetailsEnabled,
        private bool $comparisonEnabled,
        private bool $priceCheckingEnabled,
        private bool $stockCheckingEnabled,
        private bool $policySearchEnabled
    ) {
    }

    public function isProductDiscoveryEnabled(): bool
    {
        return $this->productDiscoveryEnabled;
    }

    public function isProductDetailsEnabled(): bool
    {
        return $this->productDetailsEnabled;
    }

    public function isComparisonEnabled(): bool
    {
        return $this->comparisonEnabled;
    }

    public function isPriceCheckingEnabled(): bool
    {
        return $this->priceCheckingEnabled;
    }

    public function isStockCheckingEnabled(): bool
    {
        return $this->stockCheckingEnabled;
    }

    public function isPolicySearchEnabled(): bool
    {
        return $this->policySearchEnabled;
    }
}
