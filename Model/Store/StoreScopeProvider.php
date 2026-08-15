<?php

declare(strict_types=1);

namespace Aavirbhava\AiShoppingAssistant\Model\Store;

use Aavirbhava\AiShoppingAssistant\Api\Store\StoreScopeInterface;
use Aavirbhava\AiShoppingAssistant\Api\Store\StoreScopeProviderInterface;
use Aavirbhava\AiShoppingAssistant\Model\Store\Exception\StoreScopeException;
use Magento\Framework\Phrase;
use Magento\Store\Model\Store;
use Magento\Store\Model\StoreManagerInterface;

final class StoreScopeProvider implements StoreScopeProviderInterface
{
    private const LOCALE_CODE_PATH = 'general/locale/code';

    public function __construct(
        private readonly StoreManagerInterface $storeManager
    ) {
    }

    public function activeStores(): array
    {
        $scopes = [];

        foreach ($this->storeManager->getStores(false) as $store) {
            if (!$store instanceof Store || $store->getId() < 1 || !$store->isActive()) {
                continue;
            }
            $scopes[] = $this->scopeFor($store);
        }

        usort(
            $scopes,
            static fn (StoreScopeInterface $a, StoreScopeInterface $b): int => $a->storeId() <=> $b->storeId()
        );

        return $scopes;
    }

    public function requireActive(int $storeId): StoreScopeInterface
    {
        if ($storeId < 1) {
            throw new StoreScopeException(__('Store id must be a positive integer.'));
        }

        $store = $this->storeManager->getStore($storeId);

        if (!$store instanceof Store || $store->getId() < 1 || !$store->isActive()) {
            throw new StoreScopeException(
                new Phrase('The requested store view is not active or does not exist.')
            );
        }

        return $this->scopeFor($store);
    }

    private function scopeFor(Store $store): StoreScopeInterface
    {
        $locale = $store->getConfig(self::LOCALE_CODE_PATH);

        return new StoreScope(
            (int) $store->getId(),
            (int) $store->getWebsiteId(),
            (string) $store->getCode(),
            is_string($locale) && $locale !== '' ? $locale : null
        );
    }
}
