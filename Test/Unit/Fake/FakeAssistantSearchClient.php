<?php

declare(strict_types=1);

namespace Aavirbhava\AiShoppingAssistant\Test\Unit\Fake;

use Aavirbhava\AiShoppingAssistant\Api\Indexing\AssistantSearchClientInterface;

/**
 * In-memory assistant-search client for writer lifecycle tests.
 *
 * Tracks created indexes, written documents, refreshes, alias targets, and
 * alias actions. Can be configured to fail closed (unavailable backend), report
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
    }

    public function writeDocuments(string $indexName, array $documents): void
    {
        $this->assertNoFailure('writeDocuments');
        foreach ($documents as $document) {
            $this->documentsByIndex[$indexName][] = $document;
        }
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
                $this->aliases[$alias] = array_values(array_unique(array_merge($this->aliases[$alias] ?? [], [$index])));
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