<?php

declare(strict_types=1);

namespace Aavirbhava\AiShoppingAssistant\Model\Store;

use Aavirbhava\AiShoppingAssistant\Api\Store\StoreScopeInterface;
use Aavirbhava\AiShoppingAssistant\Model\Store\Exception\StoreScopeException;

final readonly class StoreScope implements StoreScopeInterface
{
    public function __construct(
        private int $storeId,
        private int $websiteId,
        private string $storeCode,
        private ?string $localeCode = null
    ) {
        if ($storeId < 1) {
            throw new StoreScopeException(__('Store id must be a positive integer.'));
        }

        if ($websiteId < 1) {
            throw new StoreScopeException(__('Website id must be a positive integer.'));
        }

        if ($storeCode === '') {
            throw new StoreScopeException(__('Store code must not be empty.'));
        }
    }

    public function storeId(): int
    {
        return $this->storeId;
    }

    public function websiteId(): int
    {
        return $this->websiteId;
    }

    public function storeCode(): string
    {
        return $this->storeCode;
    }

    public function localeCode(): ?string
    {
        return $this->localeCode;
    }
}