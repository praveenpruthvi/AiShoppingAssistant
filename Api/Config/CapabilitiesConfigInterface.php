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

    /**
     * Gates promotion awareness end to end (Task 34): both
     * get_active_promotions (excluded from the offered tool set when
     * disabled, same as every other capability here) and
     * ChatEntryPipeline's proactive PromotionContextFormatter message —
     * a merchant turning this off means no discount fact is surfaced to
     * the model at all, not merely that the model can no longer ask for
     * one explicitly.
     */
    public function isPromotionAwarenessEnabled(): bool;
}
