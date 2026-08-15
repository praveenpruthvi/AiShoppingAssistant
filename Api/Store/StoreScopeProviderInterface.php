<?php

declare(strict_types=1);

namespace Aavirbhava\AiShoppingAssistant\Api\Store;

use Aavirbhava\AiShoppingAssistant\Model\Store\Exception\StoreScopeException;

/**
 * Resolves the active frontend store views that the assistant may operate on.
 *
 * The admin store (id 0) is never a valid assistant scope.
 */
interface StoreScopeProviderInterface
{
    /**
     * All active frontend store views, sorted by store id ascending.
     *
     * @return list<StoreScopeInterface>
     */
    public function activeStores(): array;

    /**
     * Returns the active store view for the given id, or throws.
     *
     * @throws StoreScopeException when the store does not exist or is not active.
     */
    public function requireActive(int $storeId): StoreScopeInterface;
}
