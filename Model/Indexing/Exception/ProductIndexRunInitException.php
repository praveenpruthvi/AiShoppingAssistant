<?php

declare(strict_types=1);

namespace Aavirbhava\AiShoppingAssistant\Model\Indexing\Exception;

use Aavirbhava\AiShoppingAssistant\Api\Indexing\RebuildResultInterface;
use Magento\Framework\Phrase;

/**
 * The full rebuild run could not be initialized: run identity creation or
 * store-scoped configuration resolution failed before any batch was written.
 */
final class ProductIndexRunInitException extends ProductIndexingException
{
    public function __construct(?\Exception $previous = null, ?RebuildResultInterface $rebuildResult = null)
    {
        parent::__construct(
            self::ERROR_RUN_INIT,
            new Phrase('The AI shopping assistant index could not be initialized for reindexing.'),
            $previous,
            $rebuildResult
        );
    }
}
