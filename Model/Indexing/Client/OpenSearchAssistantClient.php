<?php

declare(strict_types=1);

namespace Aavirbhava\AiShoppingAssistant\Model\Indexing\Client;

use Aavirbhava\AiShoppingAssistant\Api\Indexing\AssistantSearchClientInterface;
use Aavirbhava\AiShoppingAssistant\Model\Indexing\Exception\AliasActivationFailedException;
use Aavirbhava\AiShoppingAssistant\Model\Indexing\Exception\BulkIndexFailedException;
use Aavirbhava\AiShoppingAssistant\Model\Indexing\Exception\BulkResponseInvalidException;
use Aavirbhava\AiShoppingAssistant\Model\Indexing\Exception\OpenSearchBackendUnavailableException;
use Aavirbhava\AiShoppingAssistant\Model\Indexing\Exception\OpenSearchCapabilityUnsupportedException;
use Aavirbhava\AiShoppingAssistant\Model\Indexing\Exception\ProductIndexCreateFailedException;
use Magento\Elasticsearch\Model\Config;
use OpenSearch\Client;
use OpenSearch\ClientBuilder;

/**
 * Production assistant-search client backed by the configured OpenSearch
 * cluster.
 *
 * Connection settings are reused from the Magento catalogue-search engine
 * configuration (same cluster and credentials as Magento's own search), so no
 * duplicate credentials are stored. The OpenSearch\Client is built lazily and
 * cached per process. All transport failures are translated into sanitized
 * ProductIndexingException subclasses; hosts, credentials, and request/response
 * bodies never leave this class.
 *
 * The timeouts and retries are bounded: request timeouts are clamped to the
 * configured window and automatic retries are disabled so the writer controls
 * failure handling.
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
        private readonly Config $elasticsearchConfig
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
        } catch (\Throwable $throwable) {
            throw new OpenSearchBackendUnavailableException($this->safeCause($throwable));
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
        } catch (\Throwable $throwable) {
            throw new OpenSearchBackendUnavailableException($this->safeCause($throwable));
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
        } catch (\Throwable $throwable) {
            throw new ProductIndexCreateFailedException($this->safeCause($throwable));
        }
    }

    public function writeDocuments(string $indexName, array $documents): void
    {
        if ($documents === []) {
            return;
        }

        $body = [];
        foreach ($documents as $document) {
            $body[] = ['index' => ['_index' => $indexName, '_id' => $document['_id']]];
            $body[] = $document;
        }

        try {
            $response = $this->client()->bulk(
                [
                    'body' => $body,
                    'client' => ['timeout' => $this->timeout()],
                ]
            );
        } catch (\Throwable $throwable) {
            throw new BulkIndexFailedException($this->safeCause($throwable));
        }

        if (!is_array($response) || !isset($response['errors']) || !is_array($response['items'])) {
            throw new BulkResponseInvalidException();
        }

        if ($response['errors'] !== false && $response['errors'] !== 0) {
            throw new BulkIndexFailedException();
        }

        foreach ($response['items'] as $item) {
            if (!is_array($item) || !isset($item['index'])) {
                throw new BulkResponseInvalidException();
            }
            $result = $item['index'];
            if (!is_array($result) || (isset($result['status']) && (int)$result['status'] >= 400)) {
                throw new BulkIndexFailedException();
            }
        }
    }

    public function refresh(string $indexName): void
    {
        try {
            $this->client()->indices()->refresh(
                ['index' => $indexName, 'client' => ['timeout' => $this->timeout()]]
            );
        } catch (\Throwable $throwable) {
            throw new OpenSearchBackendUnavailableException($this->safeCause($throwable));
        }
    }

    public function aliasExists(string $aliasName): bool
    {
        try {
            return $this->client()->indices()->existsAlias(
                ['name' => $aliasName, 'client' => ['timeout' => $this->timeout()]]
            );
        } catch (\Throwable $throwable) {
            throw new OpenSearchBackendUnavailableException($this->safeCause($throwable));
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
        } catch (\Throwable $throwable) {
            throw new OpenSearchBackendUnavailableException($this->safeCause($throwable));
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
        } catch (\Throwable $throwable) {
            throw new AliasActivationFailedException($this->safeCause($throwable));
        }
    }

    public function deleteIndex(string $indexName): void
    {
        try {
            $this->client()->indices()->delete(
                ['index' => $indexName, 'client' => ['timeout' => $this->timeout()]]
            );
        } catch (\Throwable $throwable) {
            throw new OpenSearchBackendUnavailableException($this->safeCause($throwable));
        }
    }

    private function client(): Client
    {
        if ($this->client === null) {
            $options = $this->elasticsearchConfig->prepareClientOptions();
            $this->client = ClientBuilder::fromConfig($this->buildClientConfig($options), true);
        }

        return $this->client;
    }

    /**
     * @param array<string, mixed> $options
     *
     * @return array<string, mixed>
     */
    private function buildClientConfig(array $options): array
    {
        $hostname = is_string($options['hostname'] ?? '') ? $options['hostname'] : '';
        $scheme = parse_url($hostname, PHP_URL_SCHEME);
        if (!is_string($scheme)) {
            $scheme = 'http';
        }

        $host = preg_replace('/^[a-z][a-z0-9+.-]*:\/\//i', '', $hostname) ?? $hostname;
        $port = (int)($options['port'] ?? 9200);
        $auth = '';
        if (($options['enableAuth'] ?? false) && !empty($options['username']) && $options['password'] !== '') {
            $auth = $options['username'] . ':' . $options['password'] . '@';
        }

        $timeout = isset($options['timeout']) ? (int)$options['timeout'] : self::DEFAULT_TIMEOUT;
        $this->requestTimeout = $this->clampTimeout($timeout);

        return [
            'hosts' => [sprintf('%s://%s%s:%d', $scheme, $auth, $host, $port)],
            'retries' => 0,
        ];
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

    private function safeCause(\Throwable $throwable): ?\Exception
    {
        return $throwable instanceof \Exception ? $throwable : null;
    }
}