<?php

declare(strict_types=1);

namespace Aavirbhava\AiShoppingAssistant\Model\Indexing\Exception;

use Aavirbhava\AiShoppingAssistant\Api\Indexing\RebuildResultInterface;
use Magento\Framework\Phrase;

/**
 * A bulk document write was rejected by the backend.
 *
 * Messages are generic and never contain cluster hosts, credentials, document
 * content, or product identifiers.
 */
final class BulkIndexFailedException extends ProductIndexingException
{
    public function __construct(?\Exception $previous = null, ?RebuildResultInterface $rebuildResult = null)
    {
        parent::__construct(
            self::ERROR_BULK_INDEX,
            new Phrase('The AI shopping assistant index could not be written.'),
            $previous,
            $rebuildResult
        );
    }
}