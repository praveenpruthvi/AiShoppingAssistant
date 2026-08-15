<?php

declare(strict_types=1);

namespace Aavirbhava\AiShoppingAssistant\Api\Indexing;

use Aavirbhava\AiShoppingAssistant\Model\Indexing\Exception\ProductIndexingException;

/**
 * Backend-neutral seam for the assistant search cluster.
 *
 * Implementations hide the transport (OpenSearch REST client, an in-memory
 * fake, or a deliberate "no backend" fallback). All errors are translated into
 * sanitized ProductIndexingException subclasses; cluster hosts, credentials,
 * request and response bodies never appear in messages or logs.
 *
 * The writer depends on this interface so its lifecycle can be exercised
 * without a live cluster.
 */
interface AssistantSearchClientInterface
{
    /**
     * Returns true when the backend responds to a connectivity check.
     *
     * Never throws: a false result means the backend is unavailable.
     */
    public function ping(): bool;

    /**
     * Distribution reported by the backend, e.g. "opensearch" or "elasticsearch".
     *
     * @throws ProductIndexingException when the distribution cannot be read
     */
    public function distribution(): string;

    /**
     * True when the backend supports vector (kNN) fields required by the
     * assistant index.
     *
     * @throws ProductIndexingException when the capability cannot be determined
     */
    public function supportsVectorSearch(): bool;

    /**
     * @throws ProductIndexingException when the check itself fails
     */
    public function indexExists(string $indexName): bool;

    /**
     * Creates a physical index from a validated create body (settings + mapping).
     *
     * @throws ProductIndexingException on transport errors or invalid bodies
     */
    public function createIndex(string $indexName, array $createBody): void;

    /**
     * Writes one chunk of storage payloads to a physical index.
     *
     * Every item in the backend response is inspected; any rejected or
     * malformed item fails the whole call. The call never partially succeeds
     * silently.
     *
     * @param list<StoragePayloadInterface> $documents storage payloads
     *
     * @throws ProductIndexingException when any item is rejected or the response
     *     cannot be verified
     */
    public function writeDocuments(string $indexName, array $documents): void;

    /**
     * Returns the _meta section of a physical index mapping.
     *
     * Used to prove that an index was created by the assistant before it is
     * dropped during cleanup. Implementations return an empty array when the
     * index has no _meta or does not exist.
     *
     * @return array<string, mixed>
     *
     * @throws ProductIndexingException when the index metadata cannot be read
     */
    public function indexMeta(string $indexName): array;

    /**
     * Makes recently written documents immediately searchable.
     *
     * @throws ProductIndexingException on transport errors
     */
    public function refresh(string $indexName): void;

    /**
     * @throws ProductIndexingException when the check itself fails
     */
    public function aliasExists(string $aliasName): bool;

    /**
     * Physical index names the given alias currently points to.
     *
     * @return list<string>
     *
     * @throws ProductIndexingException when the alias cannot be resolved
     */
    public function aliasTargets(string $aliasName): array;

    /**
     * Applies an atomic list of alias actions (add / remove).
     *
     * @param list<array<string, mixed>> $actions
     *
     * @throws ProductIndexingException when the alias update fails
     */
    public function updateAliases(array $actions): void;

    /**
     * Deletes a physical index.
     *
     * @throws ProductIndexingException when the index cannot be deleted
     */
    public function deleteIndex(string $indexName): void;
}
