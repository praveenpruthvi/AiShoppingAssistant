<?php

declare(strict_types=1);

namespace Aavirbhava\AiShoppingAssistant\Model\Indexing\Exception;

use Aavirbhava\AiShoppingAssistant\Api\Indexing\RebuildResultInterface;
use Magento\Framework\Phrase;

/**
 * The OpenSearch backend connection configuration is invalid.
 *
 * Messages are generic and never contain cluster hosts, credentials, or raw
 * configuration values.
 */
final class OpenSearchConfigurationInvalidException extends ProductIndexingException
{
    public function __construct(?\Exception $previous = null, ?RebuildResultInterface $rebuildResult = null)
    {
        parent::__construct(
            self::ERROR_OPENSEARCH_CONFIGURATION_INVALID,
            new Phrase('The AI shopping assistant search backend is not configured.'),
            $previous,
            $rebuildResult
        );
    }
}