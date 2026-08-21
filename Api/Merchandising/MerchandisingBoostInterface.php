<?php

declare(strict_types=1);

namespace Aavirbhava\AiShoppingAssistant\Api\Merchandising;

/**
 * One merchandising boost record: an admin-configured, additive ranking
 * nudge for a single product, optionally time-scoped.
 */
interface MerchandisingBoostInterface
{
    /**
     * Positive when persisted; null for a not-yet-saved boost.
     */
    public function boostId(): ?int;

    /**
     * Magento product entity id this boost applies to.
     */
    public function productId(): int;

    /**
     * Additive contribution to a candidate's running rank score. Never
     * negative — a merchandising boost only ever nudges a product up,
     * never down (a merchant wanting to suppress a product has other,
     * unrelated tools for that, e.g. disabling/hiding it).
     */
    public function boostWeight(): float;

    /**
     * MySQL datetime string (Y-m-d H:i:s), or null when the boost has no
     * start restriction and is active from the moment it's saved.
     */
    public function startDate(): ?string;

    /**
     * MySQL datetime string (Y-m-d H:i:s), or null when the boost never
     * expires on its own.
     */
    public function endDate(): ?string;

    /**
     * Merchant on/off switch, independent of the date range — a boost can
     * be temporarily disabled without losing its configured weight/dates.
     */
    public function isActive(): bool;

    public function createdAt(): ?string;

    public function updatedAt(): ?string;
}
