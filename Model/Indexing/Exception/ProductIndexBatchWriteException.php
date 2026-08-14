<?php

declare(strict_types=1);

namespace Aavirbhava\AiShoppingAssistant\Model\Indexing\Exception;

use Aavirbhava\AiShoppingAssistant\Api\Indexing\RebuildResultInterface;
use Magento\Framework\Phrase;

/**
 * Writing a batch of documents to the assistant-index backend failed.
 */
final class ProductIndexBatchWriteException extends ProductIndexingException
{
    public function __construct(?\Exception $previous = null, ?RebuildResultInterface $rebuildResult = null)
    {
        parent::__construct(
            self::ERROR_BATCH_WRITE,
            new Phrase('The AI shopping assistant index could not be updated.'),
            $previous,
            $rebuildResult
        );
    }
}
