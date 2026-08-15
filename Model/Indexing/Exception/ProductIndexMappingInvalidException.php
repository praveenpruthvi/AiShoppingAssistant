<?php

declare(strict_types=1);

namespace Aavirbhava\AiShoppingAssistant\Model\Indexing\Exception;

use Aavirbhava\AiShoppingAssistant\Api\Indexing\RebuildResultInterface;
use Magento\Framework\Phrase;

/**
 * The physical index mapping is invalid.
 *
 * Messages are generic and never expose mapping bodies or field names.
 */
final class ProductIndexMappingInvalidException extends ProductIndexingException
{
    public function __construct(?\Exception $previous = null, ?RebuildResultInterface $rebuildResult = null)
    {
        parent::__construct(
            self::ERROR_INDEX_MAPPING_INVALID,
            new Phrase('The AI shopping assistant index mapping is invalid.'),
            $previous,
            $rebuildResult
        );
    }
}
