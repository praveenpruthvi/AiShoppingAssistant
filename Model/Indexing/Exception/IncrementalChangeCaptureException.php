<?php

declare(strict_types=1);

namespace Aavirbhava\AiShoppingAssistant\Model\Indexing\Exception;

use Magento\Framework\Phrase;

/**
 * A Magento catalogue change could not be captured safely for incremental indexing.
 */
final class IncrementalChangeCaptureException extends ProductIndexingException
{
    public function __construct()
    {
        parent::__construct(
            self::ERROR_INCREMENTAL_CHANGE_CAPTURE_FAILED,
            new Phrase('The incremental product index change could not be captured.')
        );
    }
}
