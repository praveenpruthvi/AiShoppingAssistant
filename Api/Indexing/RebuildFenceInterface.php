<?php

declare(strict_types=1);

namespace Aavirbhava\AiShoppingAssistant\Api\Indexing;

interface RebuildFenceInterface
{
    public function acquire(int $leaseSeconds): string;

    public function renew(string $ownerToken, int $leaseSeconds): void;

    public function assertOwned(string $ownerToken): void;

    public function release(string $ownerToken): void;
}

