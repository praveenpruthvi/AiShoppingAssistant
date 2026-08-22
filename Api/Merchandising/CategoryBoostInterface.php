<?php

declare(strict_types=1);

namespace Aavirbhava\AiShoppingAssistant\Api\Merchandising;

/**
 * One category-level merchandising boost record: an admin-configured,
 * additive ranking nudge applied to every product in a category,
 * optionally time-scoped. Mirrors MerchandisingBoostInterface exactly —
 * see that interface's own docblock for the per-field reasoning, which
 * applies identically here (category boost combines additively with
 * product boost, never replaces it).
 */
interface CategoryBoostInterface
{
    /**
     * Positive when persisted; null for a not-yet-saved boost.
     */
    public function boostId(): ?int;

    /**
     * Magento category entity id this boost applies to.
     */
    public function categoryId(): int;

    /**
     * Additive contribution to a candidate's running rank score. Never
     * negative — a merchandising boost only ever nudges a product up,
     * never down.
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
