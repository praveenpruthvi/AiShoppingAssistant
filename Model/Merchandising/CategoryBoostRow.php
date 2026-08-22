<?php

declare(strict_types=1);

namespace Aavirbhava\AiShoppingAssistant\Model\Merchandising;

use Aavirbhava\AiShoppingAssistant\Api\Merchandising\CategoryBoostInterface;
use Aavirbhava\AiShoppingAssistant\Model\Merchandising\Exception\MerchandisingBoostException;

/**
 * Immutable value object for a category-level merchandising boost —
 * mirrors MerchandisingBoostRow exactly (same DTO shape, same
 * MAX_BOOST_WEIGHT cap, same validation rules), just keyed by category id
 * instead of product id. MerchandisingBoostException is reused rather
 * than a new CategoryBoostException — it's already a generic
 * merchandising-boost domain exception, not product-specific, so a
 * second, near-identical exception class would add nothing.
 */
final readonly class CategoryBoostRow implements CategoryBoostInterface
{
    /**
     * Kept identical to MerchandisingBoostRow::MAX_BOOST_WEIGHT — both
     * boost types feed into the SAME capped total (see
     * MerchandisingBoostSignal's own docblock for the combination
     * formula), so they must share one cap, not two independently
     * chosen ones.
     */
    public const MAX_BOOST_WEIGHT = MerchandisingBoostRow::MAX_BOOST_WEIGHT;

    public function __construct(
        private ?int $boostId,
        private int $categoryId,
        private float $boostWeight,
        private ?string $startDate,
        private ?string $endDate,
        private bool $isActive,
        private ?string $createdAt = null,
        private ?string $updatedAt = null
    ) {
        if ($this->boostId !== null && $this->boostId < 1) {
            throw new MerchandisingBoostException(__('A boost id must be a positive integer.'));
        }

        if ($this->categoryId < 1) {
            throw new MerchandisingBoostException(__('A boost requires a positive category id.'));
        }

        if ($this->boostWeight < 0.0 || $this->boostWeight > self::MAX_BOOST_WEIGHT) {
            throw new MerchandisingBoostException(
                __('Boost weight must be between 0 and %1.', self::MAX_BOOST_WEIGHT)
            );
        }

        if ($this->startDate !== null && $this->endDate !== null && $this->startDate > $this->endDate) {
            throw new MerchandisingBoostException(__('A boost\'s end date must not be before its start date.'));
        }
    }

    public function boostId(): ?int
    {
        return $this->boostId;
    }

    public function categoryId(): int
    {
        return $this->categoryId;
    }

    public function boostWeight(): float
    {
        return $this->boostWeight;
    }

    public function startDate(): ?string
    {
        return $this->startDate;
    }

    public function endDate(): ?string
    {
        return $this->endDate;
    }

    public function isActive(): bool
    {
        return $this->isActive;
    }

    public function createdAt(): ?string
    {
        return $this->createdAt;
    }

    public function updatedAt(): ?string
    {
        return $this->updatedAt;
    }
}
