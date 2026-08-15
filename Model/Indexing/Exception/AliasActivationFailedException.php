<?php

declare(strict_types=1);

namespace Aavirbhava\AiShoppingAssistant\Model\Indexing\Exception;

use Aavirbhava\AiShoppingAssistant\Api\Indexing\RebuildResultInterface;
use Magento\Framework\Phrase;

/**
 * The atomic alias activation failed.
 *
 * Messages are generic and never contain cluster hosts, credentials, or index
 * and alias names.
 */
final class AliasActivationFailedException extends ProductIndexingException
{
    public function __construct(?\Exception $previous = null, ?RebuildResultInterface $rebuildResult = null)
    {
        parent::__construct(
            self::ERROR_ALIAS_ACTIVATION,
            new Phrase('The AI shopping assistant index could not be activated.'),
            $previous,
            $rebuildResult
        );
    }
}
