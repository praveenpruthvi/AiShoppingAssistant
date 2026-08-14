<?php

declare(strict_types=1);

namespace Aavirbhava\AiShoppingAssistant\Model\Indexing\Exception;

use Aavirbhava\AiShoppingAssistant\Api\Indexing\RebuildResultInterface;
use Magento\Framework\Phrase;

/**
 * Cleaning up an aborted run inside the document writer failed.
 *
 * The primary failure is always preserved; this exception describes the failed
 * cleanup only and is used for secondary diagnostics.
 */
final class ProductIndexAbortException extends ProductIndexingException
{
    public function __construct(?\Exception $previous = null, ?RebuildResultInterface $rebuildResult = null)
    {
        parent::__construct(
            self::ERROR_ABORT,
            new Phrase('The AI shopping assistant index could not clean up an interrupted rebuild.'),
            $previous,
            $rebuildResult
        );
    }
}
