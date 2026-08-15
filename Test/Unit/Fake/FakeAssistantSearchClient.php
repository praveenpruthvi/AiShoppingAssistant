<?php

declare(strict_types=1);

namespace Aavirbhava\AiShoppingAssistant\Test\Unit\Fake;

use Aavirbhava\AiShoppingAssistant\Api\Indexing\AssistantSearchClientInterface;
use Aavirbhava\AiShoppingAssistant\Api\Indexing\IndexedDocumentStateInterface;
use Aavirbhava\AiShoppingAssistant\Api\Indexing\StoragePayloadInterface;
use Aavirbhava\AiShoppingAssistant\Model\Indexing\Document\IndexedDocumentState;

/**
 * In-memory assistant-search client for writer lifecycle tests.
 *
 * Tracks created indexes, written documents, refreshes, alias targets, and
 * alias actions. Stores the _meta of every created index so ownership checks can
 * be exercised. Can be configured to fail closed (unavailable backend), report
 * an unsupported distribution, or throw on a specific method so failure paths
 * are exercised deterministically. Lives under Test/ only.
 */
final class FakeAssistantSearchClient implements AssistantSearchClientInterface
{
    public string $distribution = 'opensearch';

    public bool $available = true;

    public bool $vectorSearchSupported = true;

    /**
     * @var array<string, bool>
     */
    public array $indexes = [];

    /**
     * @var array<string, list<array<string, mixed>>>
     */
    public array $documentsByIndex = [];

    /**
     * @var array<string, array<string, array<string, mixed>>>
     */
    public array $documentSources = [];

    /**
     * @var array<string, array<string, mixed>>
     */
    public array $metaByIndex = [];

    /**
     * @var array<string, list<string>>
     */
    public array $aliases = [];

    /**
     * @var list<string>
     */
    public array $refreshed = [];

    /**
     * @var list<array<string, mixed>>
     */
    public array $aliasActions = [];

    /**
     * @var list<string>
     */
    public array $deleted = [];

    /**
     * @var list<array{index: string, id: string}>
     */
    public array $deletedDocuments = [];

    /**
     * @var list<array{index: string, id: string}>
     */
    public array $writtenDocuments = [];

    public ?\Closure $beforeDocumentState = null;

    public ?\Closure $afterDocumentState = null;

    public ?\Closure $beforeWriteDocument = null;

    public ?\Closure $afterWriteDocument = null;

    public ?\Closure $beforeDeleteDocument = null;

    public ?\Closure $afterDeleteDocument = null;

    /**
     * @var array<string, \Throwable>
     */
    private array $failures = [];

    public function ping(): bool
    {
        return $this->available;
    }

    public function distribution(): string
    {
        $this->assertNoFailure('distribution');
        if (!$this->available) {
            throw new \RuntimeException('backend unavailable');
        }

        return $this->distribution;
    }

    public function supportsVectorSearch(): bool
    {
        return $this->vectorSearchSupported;
    }

    public function indexExists(string $indexName): bool
    {
        return isset($this->indexes[$indexName]);
    }

    public function createIndex(string $indexName, array $createBody): void
    {
        $this->assertNoFailure('createIndex');
        $this->indexes[$indexName] = true;
        $this->documentsByIndex[$indexName] = [];
        $this->documentSources[$indexName] = [];
        $meta = $createBody['mappings']['_meta'] ?? null;
        $this->metaByIndex[$indexName] = is_array($meta) ? $meta : [];
    }

    public function writeDocuments(string $indexName, array $documents): void
    {
        $this->assertNoFailure('writeDocuments');
        foreach ($documents as $document) {
            if (!$document instanceof StoragePayloadInterface) {
                throw new \RuntimeException('expected StoragePayloadInterface');
            }
            $this->documentsByIndex[$indexName][] = $document->source();
        }
    }

    public function writeDocument(string $indexName, StoragePayloadInterface $document): void
    {
        $this->assertNoFailure('writeDocument');
        if ($this->beforeWriteDocument !== null) {
            ($this->beforeWriteDocument)($indexName, $document->id());
        }
        $this->writeDocuments($indexName, [$document]);
        $this->documentSources[$indexName][$document->id()] = $document->source();
        $this->writtenDocuments[] = ['index' => $indexName, 'id' => $document->id()];
        if ($this->afterWriteDocument !== null) {
            ($this->afterWriteDocument)($indexName, $document->id());
        }
    }

    public function documentState(string $indexName, string $documentId): ?IndexedDocumentStateInterface
    {
        $this->assertNoFailure('documentState');
        if ($this->beforeDocumentState !== null) {
            ($this->beforeDocumentState)($indexName, $documentId);
        }
        $source = $this->documentSources[$indexName][$documentId] ?? null;
        if ($this->afterDocumentState !== null) {
            ($this->afterDocumentState)($indexName, $documentId);
        }
        if ($source === null) {
            return null;
        }

        return IndexedDocumentState::fromSource($documentId, $source);
    }

    public function deleteDocument(string $indexName, string $documentId): void
    {
        $this->assertNoFailure('deleteDocument');
        if ($this->beforeDeleteDocument !== null) {
            ($this->beforeDeleteDocument)($indexName, $documentId);
        }
        $this->deletedDocuments[] = ['index' => $indexName, 'id' => $documentId];
        unset($this->documentSources[$indexName][$documentId]);
        if ($this->afterDeleteDocument !== null) {
            ($this->afterDeleteDocument)($indexName, $documentId);
        }
    }

    public function indexMeta(string $indexName): array
    {
        $this->assertNoFailure('indexMeta');

        return $this->metaByIndex[$indexName] ?? [];
    }

    public function refresh(string $indexName): void
    {
        $this->assertNoFailure('refresh');
        $this->refreshed[] = $indexName;
    }

    public function aliasExists(string $aliasName): bool
    {
        return isset($this->aliases[$aliasName]);
    }

    public function aliasTargets(string $aliasName): array
    {
        $this->assertNoFailure('aliasTargets');

        return $this->aliases[$aliasName] ?? [];
    }

    public function updateAliases(array $actions): void
    {
        $this->assertNoFailure('updateAliases');

        foreach ($actions as $action) {
            $this->aliasActions[] = $action;
            if (isset($action['add'])) {
                $alias = $action['add']['alias'];
                $index = $action['add']['index'];
                $this->aliases[$alias] = $this->aliases[$alias] ?? [];
                $this->aliases[$alias][] = $index;
                $this->aliases[$alias] = array_values(array_unique($this->aliases[$alias]));
            }
            if (isset($action['remove'])) {
                $alias = $action['remove']['alias'];
                $index = $action['remove']['index'];
                $this->aliases[$alias] = array_values(array_diff($this->aliases[$alias] ?? [], [$index]));
            }
        }
    }

    public function deleteIndex(string $indexName): void
    {
        $this->assertNoFailure('deleteIndex');
        $this->deleted[] = $indexName;
        unset($this->indexes[$indexName]);
        unset($this->documentsByIndex[$indexName]);
        unset($this->documentSources[$indexName]);
    }

    /**
     * Makes the client throw when a method is called. Use for failure-path tests.
     */
    public function failOn(string $method, \Throwable $throwable): void
    {
        $this->failures[$method] = $throwable;
    }

    private function assertNoFailure(string $method): void
    {
        if (isset($this->failures[$method])) {
            throw $this->failures[$method];
        }
    }
}
