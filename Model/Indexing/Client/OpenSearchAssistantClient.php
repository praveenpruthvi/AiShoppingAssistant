<?php

declare(strict_types=1);

namespace Aavirbhava\AiShoppingAssistant\Model\Indexing\Client;

use Aavirbhava\AiShoppingAssistant\Api\Indexing\AssistantSearchClientInterface;
use Aavirbhava\AiShoppingAssistant\Api\Indexing\StoragePayloadInterface;
use Aavirbhava\AiShoppingAssistant\Model\Indexing\Exception\AliasActivationFailedException;
use Aavirbhava\AiShoppingAssistant\Model\Indexing\Exception\BulkIndexFailedException;
use Aavirbhava\AiShoppingAssistant\Model\Indexing\Exception\BulkResponseInvalidException;
use Aavirbhava\AiShoppingAssistant\Model\Indexing\Exception\OpenSearchBackendUnavailableException;
use Aavirbhava\AiShoppingAssistant\Model\Indexing\Exception\OpenSearchCapabilityUnsupportedException;
use Aavirbhava\AiShoppingAssistant\Model\Indexing\Exception\OpenSearchConfigurationInvalidException;
use Aavirbhava\AiShoppingAssistant\Model\Indexing\Exception\ProductIndexCreateFailedException;
use Aavirbhava\AiShoppingAssistant\Model\Indexing\Exception\ProductIndexingException;
use Magento\Elasticsearch\Model\Config;
use OpenSearch\Client;

/**
 * Production assistant-search client backed by the configured OpenSearch
 * cluster.
 *
 * Connection settings are reused from the Magento catalogue-search engine
 * configuration (same cluster and credentials as Magento's own search), so no
 * duplicate credentials are stored. The OpenSearch\Client is built lazily by
 * OpenSearchClientFactory and cached per process.
 *
 * All transport failures are translated into sanitized ProductIndexingException
 * subclasses without a raw previous cause: hosts, credentials, and request and
 * response bodies never leave this class, not even in the exception chain.
 * Request timeouts are bounded and automatic retries are disabled so the writer
 * controls failure handling.
 */
final class OpenSearchAssistantClient implements AssistantSearchClientInterface
{
    /** Default request timeout in seconds when the store configuration is absent. */
    public const DEFAULT_TIMEOUT = 15;

    /** Minimum accepted request timeout in seconds. */
    public const MIN_TIMEOUT = 1;

    /** Maximum accepted request timeout in seconds. */
    public const MAX_TIMEOUT = 120;

    private ?Client $client = null;

    private ?int $requestTimeout = null;

    public function __construct(
        private readonly Config $elasticsearchConfig,
        private readonly OpenSearchClientFactoryInterface $clientFactory
    ) {
    }

    public function ping(): bool
    {
        try {
            return $this->client()->ping(['client' => ['timeout' => $this->timeout()]]);
        } catch (\Throwable $throwable) {
            return false;
        }
    }

    public function distribution(): string
    {
        try {
            $info = $this->client()->info(['client' => ['timeout' => $this->timeout()]]);
        } catch (ProductIndexingException $exception) {
            throw $exception;
        } catch (\Throwable $throwable) {
            throw new OpenSearchBackendUnavailableException();
        }

        $distribution = $info['version']['distribution'] ?? '';
        if (!is_string($distribution) || $distribution === '') {
            throw new OpenSearchCapabilityUnsupportedException();
        }

        return $distribution;
    }

    public function supportsVectorSearch(): bool
    {
        return $this->distribution() === 'opensearch';
    }

    public function indexExists(string $indexName): bool
    {
        try {
            return $this->client()->indices()->exists(
                ['index' => $indexName, 'client' => ['timeout' => $this->timeout()]]
            );
        } catch (ProductIndexingException $exception) {
            throw $exception;
        } catch (\Throwable $throwable) {
            throw new OpenSearchBackendUnavailableException();
        }
    }

    public function createIndex(string $indexName, array $createBody): void
    {
        try {
            $this->client()->indices()->create(
                [
                    'index' => $indexName,
                    'body' => $createBody,
                    'client' => ['timeout' => $this->timeout()],
                ]
            );
        } catch (ProductIndexingException $exception) {
            throw $exception;
        } catch (\Throwable $throwable) {
            throw new ProductIndexCreateFailedException();
        }
    }

    public function writeDocuments(string $indexName, array $documents): void
    {
        if ($documents === []) {
            return;
        }

        $seen = [];
        foreach ($documents as $document) {
            if (!$document instanceof StoragePayloadInterface) {
                throw new BulkResponseInvalidException();
            }
            $id = $document->id();
            if (isset($seen[$id])) {
                throw new BulkResponseInvalidException();
            }
            $seen[$id] = true;
        }

        $body = [];
        foreach ($documents as $document) {
            $body[] = ['index' => ['_index' => $indexName, '_id' => $document->id()]];
            $body[] = $document->source();
        }

        try {
            $response = $this->client()->bulk(
                [
                    'body' => $body,
                    'client' => ['timeout' => $this->timeout()],
                ]
            );
        } catch (ProductIndexingException $exception) {
            throw $exception;
        } catch (\Throwable $throwable) {
            throw new BulkIndexFailedException();
        }

        if (!is_array($response)) {
            throw new BulkResponseInvalidException();
        }

        if (!isset($response['errors']) || !is_bool($response['errors'])) {
            throw new BulkResponseInvalidException();
        }

        if (!isset($response['items']) || !is_array($response['items']) || !array_is_list($response['items'])) {
            throw new BulkResponseInvalidException();
        }

        $items = $response['items'];
        if (count($items) !== count($documents)) {
            throw new BulkResponseInvalidException();
        }

        foreach ($items as $position => $item) {
            if (!is_array($item) || count($item) !== 1 || !isset($item['index'])) {
                throw new BulkResponseInvalidException();
            }

            $result = $item['index'];
            if (!is_array($result)) {
                throw new BulkResponseInvalidException();
            }

            if (isset($result['error'])) {
                throw new BulkIndexFailedException();
            }

            if (!isset($result['status']) || !is_int($result['status'])) {
                throw new BulkResponseInvalidException();
            }

            if ($result['status'] < 200 || $result['status'] >= 300) {
                throw new BulkIndexFailedException();
            }

            $respondedId = $result['_id'] ?? null;
            if (!is_string($respondedId) || $respondedId !== $documents[$position]->id()) {
                throw new BulkResponseInvalidException();
            }
        }
    }

    public function indexMeta(string $indexName): array
    {
        try {
            $response = $this->client()->indices()->getMapping(
                ['index' => $indexName, 'client' => ['timeout' => $this->timeout()]]
            );
        } catch (ProductIndexingException $exception) {
            throw $exception;
        } catch (\Throwable $throwable) {
            throw new OpenSearchBackendUnavailableException();
        }

        if (!is_array($response)) {
            throw new OpenSearchBackendUnavailableException();
        }

        foreach ($response as $indexData) {
            if (is_array($indexData)
                && isset($indexData['mappings'])
                && is_array($indexData['mappings'])
                && isset($indexData['mappings']['_meta'])
                && is_array($indexData['mappings']['_meta'])
            ) {
                return $indexData['mappings']['_meta'];
            }
        }

        return [];
    }

    public function refresh(string $indexName): void
    {
        try {
            $this->client()->indices()->refresh(
                ['index' => $indexName, 'client' => ['timeout' => $this->timeout()]]
            );
        } catch (ProductIndexingException $exception) {
            throw $exception;
        } catch (\Throwable $throwable) {
            throw new OpenSearchBackendUnavailableException();
        }
    }

    public function aliasExists(string $aliasName): bool
    {
        try {
            return $this->client()->indices()->existsAlias(
                ['name' => $aliasName, 'client' => ['timeout' => $this->timeout()]]
            );
        } catch (ProductIndexingException $exception) {
            throw $exception;
        } catch (\Throwable $throwable) {
            throw new OpenSearchBackendUnavailableException();
        }
    }

    public function aliasTargets(string $aliasName): array
    {
        if (!$this->aliasExists($aliasName)) {
            return [];
        }

        try {
            $response = $this->client()->indices()->getAlias(
                ['name' => $aliasName, 'client' => ['timeout' => $this->timeout()]]
            );
        } catch (ProductIndexingException $exception) {
            throw $exception;
        } catch (\Throwable $throwable) {
            throw new OpenSearchBackendUnavailableException();
        }

        if (!is_array($response)) {
            throw new OpenSearchBackendUnavailableException();
        }

        return array_map('strval', array_keys($response));
    }

    public function updateAliases(array $actions): void
    {
        if ($actions === []) {
            return;
        }

        try {
            $this->client()->indices()->updateAliases(
                [
                    'body' => ['actions' => $actions],
                    'client' => ['timeout' => $this->timeout()],
                ]
            );
        } catch (ProductIndexingException $exception) {
            throw $exception;
        } catch (\Throwable $throwable) {
            throw new AliasActivationFailedException();
        }
    }

    public function deleteIndex(string $indexName): void
    {
        try {
            $this->client()->indices()->delete(
                ['index' => $indexName, 'client' => ['timeout' => $this->timeout()]]
            );
        } catch (ProductIndexingException $exception) {
            throw $exception;
        } catch (\Throwable $throwable) {
            throw new OpenSearchBackendUnavailableException();
        }
    }

    private function client(): Client
    {
        if ($this->client === null) {
            try {
                $options = $this->elasticsearchConfig->prepareClientOptions();
                $this->client = $this->clientFactory->create($options);
            } catch (ProductIndexingException $exception) {
                throw $exception;
            } catch (\Throwable $throwable) {
                throw new OpenSearchConfigurationInvalidException();
            }
            $timeout = isset($options['timeout']) ? (int)$options['timeout'] : self::DEFAULT_TIMEOUT;
            $this->requestTimeout = $this->clampTimeout($timeout);
        }

        return $this->client;
    }

    private function timeout(): int
    {
        if ($this->requestTimeout === null) {
            $this->requestTimeout = self::DEFAULT_TIMEOUT;
        }

        return $this->requestTimeout;
    }

    private function clampTimeout(int $timeout): int
    {
        if ($timeout < self::MIN_TIMEOUT) {
            return self::MIN_TIMEOUT;
        }
        if ($timeout > self::MAX_TIMEOUT) {
            return self::MAX_TIMEOUT;
        }

        return $timeout;
    }
}