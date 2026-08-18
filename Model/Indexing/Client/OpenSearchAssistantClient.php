<?php

declare(strict_types=1);

namespace Aavirbhava\AiShoppingAssistant\Model\Indexing\Client;

use Aavirbhava\AiShoppingAssistant\Api\Indexing\AssistantSearchClientInterface;
use Aavirbhava\AiShoppingAssistant\Api\Indexing\IndexedDocumentStateInterface;
use Aavirbhava\AiShoppingAssistant\Api\Indexing\StoragePayloadInterface;
use Aavirbhava\AiShoppingAssistant\Model\Indexing\Document\IndexedDocumentState;
use Aavirbhava\AiShoppingAssistant\Model\Indexing\Exception\AliasActivationFailedException;
use Aavirbhava\AiShoppingAssistant\Model\Indexing\Exception\BulkIndexFailedException;
use Aavirbhava\AiShoppingAssistant\Model\Indexing\Exception\BulkResponseInvalidException;
use Aavirbhava\AiShoppingAssistant\Model\Indexing\Exception\IndexDocumentStateInvalidException;
use Aavirbhava\AiShoppingAssistant\Model\Indexing\Exception\OpenSearchBackendUnavailableException;
use Aavirbhava\AiShoppingAssistant\Model\Indexing\Exception\OpenSearchCapabilityUnsupportedException;
use Aavirbhava\AiShoppingAssistant\Model\Indexing\Exception\OpenSearchConfigurationInvalidException;
use Aavirbhava\AiShoppingAssistant\Model\Indexing\Exception\ProductIndexCreateFailedException;
use Aavirbhava\AiShoppingAssistant\Model\Indexing\Exception\ProductIndexingException;
use Aavirbhava\AiShoppingAssistant\Model\Indexing\Exception\SearchQueryFailedException;
use Aavirbhava\AiShoppingAssistant\Model\Indexing\Exception\SearchResponseInvalidException;
use Magento\Elasticsearch\Model\Config;
use OpenSearch\Client;
use OpenSearch\Common\Exceptions\Missing404Exception;

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

    /**
     * @var list<string>
     */
    private const DOCUMENT_STATE_SOURCE_FIELDS = [
        'document_id',
        'complete_document_hash',
        'embedding_content_hash',
        'embedding_fingerprint',
        'embedding',
    ];

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

        if (!is_array($info) || !isset($info['version']) || !is_array($info['version'])) {
            throw new OpenSearchCapabilityUnsupportedException();
        }

        $version = $info['version'];
        $distribution = $version['distribution'] ?? null;
        $number = $version['number'] ?? null;
        if (!is_scalar($distribution) || trim((string)$distribution) === '') {
            throw new OpenSearchCapabilityUnsupportedException();
        }
        if (!is_scalar($number) || trim((string)$number) === '') {
            throw new OpenSearchCapabilityUnsupportedException();
        }

        return strtolower((string)$distribution);
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
            if ($id === '') {
                throw new BulkResponseInvalidException();
            }
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
        if ($response['errors'] === true) {
            throw new BulkIndexFailedException();
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

            if (array_key_exists('error', $result)) {
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

    public function writeDocument(string $indexName, StoragePayloadInterface $document): void
    {
        $this->writeDocuments($indexName, [$document]);
    }

    public function documentState(string $indexName, string $documentId): ?IndexedDocumentStateInterface
    {
        if ($documentId === '') {
            throw new IndexDocumentStateInvalidException();
        }

        try {
            $response = $this->client()->get(
                [
                    'index' => $indexName,
                    'id' => $documentId,
                    '_source_includes' => self::DOCUMENT_STATE_SOURCE_FIELDS,
                    'client' => ['timeout' => $this->timeout()],
                ]
            );
        } catch (ProductIndexingException $exception) {
            throw $exception;
        } catch (\Throwable $throwable) {
            if ($this->isNotFound($throwable)) {
                return null;
            }

            throw new OpenSearchBackendUnavailableException();
        }

        if (!is_array($response)) {
            throw new IndexDocumentStateInvalidException();
        }

        if (!array_key_exists('found', $response) || !is_bool($response['found'])) {
            throw new IndexDocumentStateInvalidException();
        }

        $found = $response['found'];
        $responseId = $response['_id'] ?? null;
        if ($found === false) {
            if (array_key_exists('_source', $response)
                || ($responseId !== null && (!is_string($responseId) || $responseId !== $documentId))
            ) {
                throw new IndexDocumentStateInvalidException();
            }

            return null;
        }

        if (!is_string($responseId) || $responseId !== $documentId) {
            throw new IndexDocumentStateInvalidException();
        }

        $source = $response['_source'] ?? null;
        if (!is_array($source)) {
            throw new IndexDocumentStateInvalidException();
        }

        return IndexedDocumentState::fromSource($documentId, $source);
    }

    public function deleteDocument(string $indexName, string $documentId): void
    {
        if ($documentId === '') {
            throw new IndexDocumentStateInvalidException();
        }

        try {
            $response = $this->client()->delete(
                [
                    'index' => $indexName,
                    'id' => $documentId,
                    'client' => ['timeout' => $this->timeout()],
                ]
            );
        } catch (ProductIndexingException $exception) {
            throw $exception;
        } catch (\Throwable $throwable) {
            if ($this->isNotFound($throwable)) {
                return;
            }

            throw new OpenSearchBackendUnavailableException();
        }

        if (!is_array($response)) {
            throw new IndexDocumentStateInvalidException();
        }

        $responseId = $response['_id'] ?? null;
        if (!is_string($responseId) || $responseId !== $documentId) {
            throw new IndexDocumentStateInvalidException();
        }

        $result = $response['result'] ?? null;
        if (!is_string($result) || !in_array($result, ['deleted', 'not_found'], true)) {
            throw new IndexDocumentStateInvalidException();
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

        if (count($response) !== 1 || !array_key_exists($indexName, $response)) {
            throw new OpenSearchBackendUnavailableException();
        }

        $indexData = $response[$indexName];
        if (!is_array($indexData)
            || !isset($indexData['mappings'])
            || !is_array($indexData['mappings'])
            || !isset($indexData['mappings']['_meta'])
            || !is_array($indexData['mappings']['_meta'])
        ) {
            throw new OpenSearchBackendUnavailableException();
        }

        return $indexData['mappings']['_meta'];
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

    public function search(string $indexName, array $queryBody): array
    {
        try {
            $response = $this->client()->search(
                [
                    'index' => $indexName,
                    'body' => $queryBody,
                    'client' => ['timeout' => $this->timeout()],
                ]
            );
        } catch (ProductIndexingException $exception) {
            throw $exception;
        } catch (\Throwable $throwable) {
            throw new SearchQueryFailedException();
        }

        if (!is_array($response) || !isset($response['hits']) || !is_array($response['hits'])) {
            throw new SearchResponseInvalidException();
        }

        $rawHits = $response['hits']['hits'] ?? null;
        if (!is_array($rawHits) || !array_is_list($rawHits)) {
            throw new SearchResponseInvalidException();
        }

        $hits = [];
        foreach ($rawHits as $rawHit) {
            if (!is_array($rawHit)) {
                throw new SearchResponseInvalidException();
            }

            $id = $rawHit['_id'] ?? null;
            $score = $rawHit['_score'] ?? null;
            $source = $rawHit['_source'] ?? null;

            if (!is_string($id) || $id === '') {
                throw new SearchResponseInvalidException();
            }
            if (!is_int($score) && !is_float($score)) {
                throw new SearchResponseInvalidException();
            }
            if (!is_array($source)) {
                throw new SearchResponseInvalidException();
            }

            $hits[] = ['_id' => $id, '_score' => (float)$score, '_source' => $source];
        }

        return $hits;
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

    private function isNotFound(\Throwable $throwable): bool
    {
        return $throwable instanceof Missing404Exception;
    }
}
