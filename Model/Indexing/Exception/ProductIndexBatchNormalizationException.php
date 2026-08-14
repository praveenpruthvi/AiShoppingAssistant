<?php

declare(strict_types=1);

namespace Aavirbhava\AiShoppingAssistant\Model\Indexing\Exception;

use Aavirbhava\AiShoppingAssistant\Api\Indexing\RebuildResultInterface;
use Magento\Framework\Phrase;

/**
 * Loading product snapshots or normalizing a batch into documents failed.
 */
final class ProductIndexBatchNormalizationException extends ProductIndexingException
{
    public function __construct(?\Exception $previous = null, ?RebuildResultInterface $rebuildResult = null)
    {
        parent::__construct(
            self::ERROR_BATCH_NORMALIZATION,
            new Phrase('The AI shopping assistant index could not process a product batch.'),
            $previous,
            $rebuildResult
        );
    }
}
