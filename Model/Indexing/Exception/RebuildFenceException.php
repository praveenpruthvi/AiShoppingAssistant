<?php

declare(strict_types=1);

namespace Aavirbhava\AiShoppingAssistant\Model\Indexing\Exception;

use Aavirbhava\AiShoppingAssistant\Api\Indexing\RebuildResultInterface;
use Magento\Framework\Phrase;

/**
 * A full-rebuild incremental work fence could not be managed safely.
 */
final class RebuildFenceException extends ProductIndexingException
{
    public function __construct(?\Exception $previous = null, ?RebuildResultInterface $rebuildResult = null)
    {
        parent::__construct(
            self::ERROR_REBUILD_FENCE_FAILED,
            new Phrase('The product index rebuild fence could not be managed.'),
            $previous,
            $rebuildResult
        );
    }
}

