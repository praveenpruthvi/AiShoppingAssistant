<?php

declare(strict_types=1);

namespace Aavirbhava\AiShoppingAssistant\Model\Indexing\Exception;

use Aavirbhava\AiShoppingAssistant\Api\Indexing\RebuildResultInterface;
use Magento\Framework\Phrase;

/**
 * Incremental scheduling was requested before durable recovery is available.
 * Refuses explicitly rather than silently dropping ids.
 */
final class ProductIndexIncrementalSchedulerUnavailableException extends ProductIndexingException
{
    public function __construct(?\Exception $previous = null, ?RebuildResultInterface $rebuildResult = null)
    {
        parent::__construct(
            self::ERROR_INCREMENTAL_SCHEDULER_UNAVAILABLE,
            new Phrase('Incremental product indexing is not available yet.'),
            $previous,
            $rebuildResult
        );
    }
}
