<?php

declare(strict_types=1);

namespace Aavirbhava\AiShoppingAssistant\Model\Indexing\Client;

use OpenSearch\Client;

/**
 * Thin seam over OpenSearch\ClientBuilder so endpoint normalization can be
 * tested without creating a real OpenSearch client.
 */
interface OpenSearchClientBuilderInterface
{
    /**
     * @param array<string, mixed> $config validated OpenSearch client config
     */
    public function fromConfig(array $config): Client;
}
