<?php

declare(strict_types=1);

namespace Aavirbhava\AiShoppingAssistant\Model\Indexing\Exception;

use Aavirbhava\AiShoppingAssistant\Api\Indexing\RebuildResultInterface;
use Magento\Framework\Phrase;

/**
 * Promoting the run's non-live targets to the live assistant index failed.
 */
final class ProductIndexActivationException extends ProductIndexingException
{
    public function __construct(?\Exception $previous = null, ?RebuildResultInterface $rebuildResult = null)
    {
        parent::__construct(
            self::ERROR_ACTIVATION,
            new Phrase('The AI shopping assistant index could not be activated.'),
            $previous,
            $rebuildResult
        );
    }
}
