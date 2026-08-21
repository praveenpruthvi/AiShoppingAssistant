<?php

declare(strict_types=1);

namespace Aavirbhava\AiShoppingAssistant\Model\Indexing\Client;

use Aavirbhava\AiShoppingAssistant\Api\Indexing\AssistantSearchClientInterface;
use Aavirbhava\AiShoppingAssistant\Api\Indexing\IndexedDocumentStateInterface;
use Aavirbhava\AiShoppingAssistant\Api\Indexing\StoragePayloadInterface;
use Aavirbhava\AiShoppingAssistant\Model\Indexing\Exception\OpenSearchBackendUnavailableException;

/**
 * Deliberate "no backend" fallback used before a production client is wired up
 * or when the assistant index backend is intentionally disabled.
 *
 * Reads fail closed with a sanitized exception instead of silently pretending a
 * backend exists. Used as the fallback preference; the production
 * OpenSearchAssistantClient replaces it in real deployments.
 */
final class UnavailableAssistantSearchClient implements AssistantSearchClientInterface
{
    public function ping(): bool
    {
        return false;
    }

    public function distribution(): string
    {
        throw new OpenSearchBackendUnavailableException();
    }

    public function supportsVectorSearch(): bool
    {
        throw new OpenSearchBackendUnavailableException();
    }

    public function indexExists(string $indexName): bool
    {
        throw new OpenSearchBackendUnavailableException();
    }

    public function createIndex(string $indexName, array $createBody): void
    {
        throw new OpenSearchBackendUnavailableException();
    }

    public function writeDocuments(string $indexName, array $documents): void
    {
        throw new OpenSearchBackendUnavailableException();
    }

    public function writeDocument(string $indexName, StoragePayloadInterface $document): void
    {
        throw new OpenSearchBackendUnavailableException();
    }

    public function documentState(string $indexName, string $documentId): ?IndexedDocumentStateInterface
    {
        throw new OpenSearchBackendUnavailableException();
    }

    public function deleteDocument(string $indexName, string $documentId): void
    {
        throw new OpenSearchBackendUnavailableException();
    }

    public function indexMeta(string $indexName): array
    {
        throw new OpenSearchBackendUnavailableException();
    }

    public function refresh(string $indexName): void
    {
        throw new OpenSearchBackendUnavailableException();
    }

    public function aliasExists(string $aliasName): bool
    {
        throw new OpenSearchBackendUnavailableException();
    }

    public function aliasTargets(string $aliasName): array
    {
        throw new OpenSearchBackendUnavailableException();
    }

    public function updateAliases(array $actions): void
    {
        throw new OpenSearchBackendUnavailableException();
    }

    public function deleteIndex(string $indexName): void
    {
        throw new OpenSearchBackendUnavailableException();
    }

    public function search(string $indexName, array $queryBody): array
    {
        throw new OpenSearchBackendUnavailableException();
    }

    public function listIndices(string $pattern): array
    {
        throw new OpenSearchBackendUnavailableException();
    }

    public function indexAliases(string $indexName): array
    {
        throw new OpenSearchBackendUnavailableException();
    }

    public function indexCreatedAt(string $indexName): int
    {
        throw new OpenSearchBackendUnavailableException();
    }
}
