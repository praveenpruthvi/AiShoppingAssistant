<?php

declare(strict_types=1);

namespace Aavirbhava\AiShoppingAssistant\Model\Indexing\Exception;

use Aavirbhava\AiShoppingAssistant\Api\Indexing\RebuildResultInterface;
use Magento\Framework\Phrase;

/**
 * The OpenSearch backend lacks a capability required by the assistant index.
 *
 * Messages are generic and never contain cluster details or version values.
 */
final class OpenSearchCapabilityUnsupportedException extends ProductIndexingException
{
    public function __construct(?\Exception $previous = null, ?RebuildResultInterface $rebuildResult = null)
    {
        parent::__construct(
            self::ERROR_OPENSEARCH_CAPABILITY_UNSUPPORTED,
            new Phrase('The AI shopping assistant search backend does not support the required capabilities.'),
            $previous,
            $rebuildResult
        );
    }
}