<?php

declare(strict_types=1);

namespace Aavirbhava\AiShoppingAssistant\Model\Indexing\Exception;

use Aavirbhava\AiShoppingAssistant\Api\Indexing\RebuildResultInterface;
use Magento\Framework\Phrase;

/**
 * A bulk response was malformed or could not be verified.
 *
 * Messages are generic and never expose raw response bodies or item content.
 */
final class BulkResponseInvalidException extends ProductIndexingException
{
    public function __construct(?\Exception $previous = null, ?RebuildResultInterface $rebuildResult = null)
    {
        parent::__construct(
            self::ERROR_BULK_RESPONSE_INVALID,
            new Phrase('The AI shopping assistant index write could not be verified.'),
            $previous,
            $rebuildResult
        );
    }
}