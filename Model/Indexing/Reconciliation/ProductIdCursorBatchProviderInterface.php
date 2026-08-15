<?php

declare(strict_types=1);

namespace Aavirbhava\AiShoppingAssistant\Model\Indexing\Reconciliation;

interface ProductIdCursorBatchProviderInterface
{
    /**
     * @return list<int>
     */
    public function idsAfter(int $lastProductId, int $limit): array;
}
