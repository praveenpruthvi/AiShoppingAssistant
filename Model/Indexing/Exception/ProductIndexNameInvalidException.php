<?php

declare(strict_types=1);

namespace Aavirbhava\AiShoppingAssistant\Model\Indexing\Exception;

use Aavirbhava\AiShoppingAssistant\Api\Indexing\RebuildResultInterface;
use Magento\Framework\Phrase;

/**
 * An index or alias name is invalid, oversize, or unsafe.
 *
 * The offending value is never echoed in the message.
 */
final class ProductIndexNameInvalidException extends ProductIndexingException
{
    public function __construct(?\Exception $previous = null, ?RebuildResultInterface $rebuildResult = null)
    {
        parent::__construct(
            self::ERROR_INDEX_NAME_INVALID,
            new Phrase('The AI shopping assistant index name is invalid.'),
            $previous,
            $rebuildResult
        );
    }
}
