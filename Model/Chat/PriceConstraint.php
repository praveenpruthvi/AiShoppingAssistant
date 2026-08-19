<?php

declare(strict_types=1);

namespace Aavirbhava\AiShoppingAssistant\Model\Chat;

use InvalidArgumentException;

/**
 * A price threshold parsed from a customer's own query text
 * (PriceConstraintDetector) — "under $60" (max=60, exclusive), "over $20"
 * (min=20, exclusive), "up to $60" (max=60, inclusive), "between $20 and
 * $60" (both). At least one bound is always present.
 */
final readonly class PriceConstraint
{
    public function __construct(
        public ?float $max,
        public bool $maxInclusive,
        public ?float $min,
        public bool $minInclusive
    ) {
        if ($max === null && $min === null) {
            throw new InvalidArgumentException('A price constraint requires at least a max or a min bound.');
        }

        if ($max !== null && $max < 0.0) {
            throw new InvalidArgumentException('A price constraint max bound must not be negative.');
        }

        if ($min !== null && $min < 0.0) {
            throw new InvalidArgumentException('A price constraint min bound must not be negative.');
        }

        if ($max !== null && $min !== null && $min > $max) {
            throw new InvalidArgumentException('A price constraint min bound must not exceed its max bound.');
        }
    }

    public function isSatisfiedBy(float $price): bool
    {
        if ($this->max !== null) {
            if ($this->maxInclusive ? $price > $this->max : $price >= $this->max) {
                return false;
            }
        }

        if ($this->min !== null) {
            if ($this->minInclusive ? $price < $this->min : $price <= $this->min) {
                return false;
            }
        }

        return true;
    }
}
