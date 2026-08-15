<?php

declare(strict_types=1);

namespace Aavirbhava\AiShoppingAssistant\Api\Store;

/**
 * Immutable store view scope for assistant features.
 *
 * Every assistant query, index document, and tool call is scoped by one store
 * view and its website. Locale is optional because some scopes may not resolve
 * a configured locale reliably; callers treat null as "unknown locale".
 */
interface StoreScopeInterface
{
    /**
     * Positive Magento store view id. The admin store (0) is never a scope.
     */
    public function storeId(): int;

    /**
     * Positive Magento website id the store view belongs to.
     */
    public function websiteId(): int;

    /**
     * Non-empty Magento store view code, e.g. "default".
     */
    public function storeCode(): string;

    /**
     * Optional locale code, e.g. "en_US". Null when it cannot be resolved.
     */
    public function localeCode(): ?string;
}
