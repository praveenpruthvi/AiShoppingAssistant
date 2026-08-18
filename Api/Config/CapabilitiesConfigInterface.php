<?php

declare(strict_types=1);

namespace Aavirbhava\AiShoppingAssistant\Api\Config;

/**
 * Per-feature "Assistant Capabilities" toggles (Stores > Configuration >
 * AI Shopping Assistant > Assistant Capabilities), one per read-only
 * commerce tool. A disabled capability excludes its tool from the set
 * offered to the LLM entirely — it is never merely refused when called.
 *
 * Cart read/add/remove capabilities are a later task's concern (see
 * guardrails.cart_mutations_enabled for the existing, unrelated cart
 * mutation gate).
 */
interface CapabilitiesConfigInterface
{
    public function isProductDiscoveryEnabled(): bool;

    public function isProductDetailsEnabled(): bool;

    public function isComparisonEnabled(): bool;

    public function isPriceCheckingEnabled(): bool;

    public function isStockCheckingEnabled(): bool;

    public function isPolicySearchEnabled(): bool;
}
