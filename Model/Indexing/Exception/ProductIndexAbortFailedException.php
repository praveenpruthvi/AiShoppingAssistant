<?php

declare(strict_types=1);

namespace Aavirbhava\AiShoppingAssistant\Model\Indexing\Exception;

use Aavirbhava\AiShoppingAssistant\Api\Indexing\RebuildResultInterface;
use Magento\Framework\Phrase;

/**
 * Cleaning up an aborted run's physical indexes failed.
 *
 * The writer reports this when it cannot prove ownership or cannot delete an
 * unaliased current-run index during abort. Full rebuild orchestration surfaces
 * the same stable code while preserving the primary rebuild failure in the
 * exception chain.
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
