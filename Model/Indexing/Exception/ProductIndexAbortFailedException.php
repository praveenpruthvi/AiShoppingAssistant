<?php

declare(strict_types=1);

namespace Aavirbhava\AiShoppingAssistant\Model\Indexing\Exception;

use Aavirbhava\AiShoppingAssistant\Api\Indexing\RebuildResultInterface;
use Magento\Framework\Phrase;

/**
 * Cleaning up an aborted run's physical indexes failed.
 *
 * The writer contract keeps cleanup failures from propagating to callers; this
 * class exists so cleanup errors can be surfaced through the taxonomy if a
 * future caller decides to log them.
 */
final class ProductIndexAbortFailedException extends ProductIndexingException
{
    public function __construct(?\Exception $previous = null, ?RebuildResultInterface $rebuildResult = null)
    {
        parent::__construct(
            self::ERROR_INDEX_ABORT,
            new Phrase('The AI shopping assistant index could not be cleaned up.'),
            $previous,
            $rebuildResult
        );
    }
}