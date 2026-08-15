<?php

declare(strict_types=1);

namespace Aavirbhava\AiShoppingAssistant\Model\Indexing\Client;

use OpenSearch\Client;
use OpenSearch\ClientBuilder;

/**
 * Production adapter for OpenSearch\ClientBuilder.
 */
final class OpenSearchClientBuilder implements OpenSearchClientBuilderInterface
{
    public function fromConfig(array $config): Client
    {
        return ClientBuilder::fromConfig($config, true);
    }
}
