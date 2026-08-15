<?php

declare(strict_types=1);

namespace Aavirbhava\AiShoppingAssistant\Api\Indexing;

use Aavirbhava\AiShoppingAssistant\Model\Indexing\Exception\ProductIndexingException;

/**
 * Transport-independent core for incrementally reconciling one product id.
 *
 * Implementations are safe for repeated queue delivery: each call reloads the
 * current Magento catalogue state and the current assistant index state before
 * deciding whether to delete, reuse an existing vector, or embed and write.
 */
interface ProductIncrementalIndexerInterface
{
    /**
     * Reconciles one positive Magento product entity id across active stores.
     *
     * @throws ProductIndexingException when the id is invalid, the live alias is
     *     missing/incompatible, catalogue processing fails, embedding fails, or
     *     the backend write/delete cannot be verified
     */
    public function process(int $productId): void;
}
