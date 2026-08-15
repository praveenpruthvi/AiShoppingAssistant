<?php

declare(strict_types=1);

namespace Aavirbhava\AiShoppingAssistant\Api\Indexing;

interface IncrementalWorkClaimInterface
{
    public function productId(): int;

    public function generation(): int;

    public function leaseToken(): string;
}
