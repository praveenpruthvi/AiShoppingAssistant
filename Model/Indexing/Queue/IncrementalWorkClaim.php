<?php

declare(strict_types=1);

namespace Aavirbhava\AiShoppingAssistant\Model\Indexing\Queue;

use Aavirbhava\AiShoppingAssistant\Api\Indexing\IncrementalWorkClaimInterface;

final class IncrementalWorkClaim implements IncrementalWorkClaimInterface
{
    public function __construct(
        private readonly int $productId,
        private readonly int $generation,
        private readonly int $attempts,
        private readonly string $leaseToken
    ) {
        if (
            $productId < 1
            || $generation < 1
            || $attempts < 0
            || !preg_match('/^[A-Za-z0-9_-]{32,64}$/', $leaseToken)
        ) {
            throw new \InvalidArgumentException('Incremental work claim values are invalid.');
        }
    }

    public function productId(): int
    {
        return $this->productId;
    }

    public function generation(): int
    {
        return $this->generation;
    }

    public function attempts(): int
    {
        return $this->attempts;
    }

    public function leaseToken(): string
    {
        return $this->leaseToken;
    }
}
