<?php

declare(strict_types=1);

namespace Aavirbhava\AiShoppingAssistant\Model\Indexing\Exception;

use Aavirbhava\AiShoppingAssistant\Api\Indexing\RebuildResultInterface;
use Magento\Framework\Phrase;

/**
 * A per-product incremental worker lock could not be managed safely.
 */
final class IncrementalWorkerLockException extends ProductIndexingException
{
    public function __construct(?\Exception $previous = null, ?RebuildResultInterface $rebuildResult = null)
    {
        parent::__construct(
            self::ERROR_INCREMENTAL_WORKER_LOCK_FAILED,
            new Phrase('The incremental product index worker lock could not be managed.'),
            $previous,
            $rebuildResult
        );
    }
}
