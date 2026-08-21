<?php

declare(strict_types=1);

namespace Aavirbhava\AiShoppingAssistant\Model\Merchandising;

use Aavirbhava\AiShoppingAssistant\Api\Merchandising\MerchandisingBoostInterface;
use Aavirbhava\AiShoppingAssistant\Model\Merchandising\Exception\MerchandisingBoostException;

/**
 * Immutable value object for a merchandising boost — this module's usual
 * DTO shape (see ProductSnapshot/ProductDocument), used at every boundary
 * outside the admin-grid ORM plumbing (Model\MerchandisingBoost is the
 * mutable AbstractModel row Magento's Collection/grid machinery needs;
 * MerchandisingBoostRepository translates between the two so nothing
 * outside that repository ever touches the mutable row directly).
 */
final readonly class MerchandisingBoostRow implements MerchandisingBoostInterface
{
    /**
     * Maximum additive score contribution a single boost may carry —
     * deliberately larger than RatingSignal's conservative default (0.1)
     * since a boost is explicit merchant intent, not a soft nudge, but
     * still bounded to roughly one full relevance signal's own typical
     * contribution (TextRelevanceSignal/VectorSimilaritySignal each top
     * out near 1.0) so a boost stays additive rather than becoming a de
     * facto override of relevance on its own.
     */
    public const MAX_BOOST_WEIGHT = 1.0;

    public function __construct(
        private ?int $boostId,
        private int $productId,
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

        if ($this->productId < 1) {
            throw new MerchandisingBoostException(__('A boost requires a positive product id.'));
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

    public function productId(): int
    {
        return $this->productId;
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
