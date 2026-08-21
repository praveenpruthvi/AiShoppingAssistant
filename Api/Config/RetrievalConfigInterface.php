<?php

declare(strict_types=1);

namespace Aavirbhava\AiShoppingAssistant\Api\Config;

interface RetrievalConfigInterface
{
    public function keywordCandidates(): int;

    public function vectorCandidates(): int;

    public function mergedCandidates(): int;

    public function finalProducts(): int;

    public function isRerankerEnabled(): bool;

    /**
     * How much RatingSignal's Bayesian-weighted rating contributes to a
     * candidate's running rank score. 0.0 disables the signal's effect
     * entirely without needing to remove it from the pipeline.
     */
    public function ratingSignalWeight(): float;
}
