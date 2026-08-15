<?php

declare(strict_types=1);

namespace Aavirbhava\AiShoppingAssistant\Model\Indexing\Queue;

use Aavirbhava\AiShoppingAssistant\Api\Indexing\IncrementalWorkClaimInterface;

final class IncrementalWorkClaim implements IncrementalWorkClaimInterface
{
    public function __construct(
        private readonly int $productId,
        private readonly int $generation,
        private readonly string $leaseToken
    ) {
        if ($productId < 1 || $generation < 1 || $leaseToken === '') {
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

    public function leaseToken(): string
    {
        return $this->leaseToken;
    }
}
