<?php

declare(strict_types=1);

namespace Aavirbhava\AiShoppingAssistant\Model\Indexing\Exception;

use Aavirbhava\AiShoppingAssistant\Api\Indexing\RebuildResultInterface;
use Magento\Framework\Phrase;

/**
 * Existing indexed document state could not be verified.
 */
final class IndexDocumentStateInvalidException extends ProductIndexingException
{
    public function __construct(?\Exception $previous = null, ?RebuildResultInterface $rebuildResult = null)
    {
        parent::__construct(
            self::ERROR_INDEX_DOCUMENT_STATE_INVALID,
            new Phrase('The AI shopping assistant indexed document state is invalid.'),
            $previous,
            $rebuildResult
        );
    }
}
