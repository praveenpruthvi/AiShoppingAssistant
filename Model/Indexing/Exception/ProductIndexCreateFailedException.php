<?php

declare(strict_types=1);

namespace Aavirbhava\AiShoppingAssistant\Model\Indexing\Exception;

use Aavirbhava\AiShoppingAssistant\Api\Indexing\RebuildResultInterface;
use Magento\Framework\Phrase;

/**
 * Creating a physical index failed.
 *
 * Messages are generic and never contain cluster hosts, credentials, request
 * bodies, or index names.
 */
final class ProductIndexCreateFailedException extends ProductIndexingException
{
    public function __construct(?\Exception $previous = null, ?RebuildResultInterface $rebuildResult = null)
    {
        parent::__construct(
            self::ERROR_INDEX_CREATE_FAILED,
            new Phrase('The AI shopping assistant index could not be created.'),
            $previous,
            $rebuildResult
        );
    }
}
