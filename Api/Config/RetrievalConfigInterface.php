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
}