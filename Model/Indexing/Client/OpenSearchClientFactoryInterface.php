<?php

declare(strict_types=1);

namespace Aavirbhava\AiShoppingAssistant\Model\Indexing\Client;

use Aavirbhava\AiShoppingAssistant\Model\Indexing\Exception\ProductIndexingException;
use OpenSearch\Client;

/**
 * Builds a configured OpenSearch\Client from Magento catalogue-search options.
 *
 * The factory is a seam so the production client can be constructed and tested
 * without a live cluster. It validates every connection option and rejects
 * ambiguous or unsafe values (embedded credentials, non-http(s) schemes,
 * embedded ports, fragments, paths) before a client is created.
 */
interface OpenSearchClientFactoryInterface
{
    /**
     * @param array<string, mixed> $options catalogue-search options with keys
     *     hostname, port, enableAuth, username, password, and timeout
     *
     * @throws ProductIndexingException when the connection options are invalid
     */
    public function create(array $options): Client;
}