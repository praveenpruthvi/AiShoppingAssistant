<?php

declare(strict_types=1);

namespace Aavirbhava\AiShoppingAssistant\Api\Indexing;

use Aavirbhava\AiShoppingAssistant\Api\Store\StoreScopeInterface;
use Aavirbhava\AiShoppingAssistant\Model\Indexing\Exception\ProductIndexingException;

/**
 * Immutable description of one full assistant-index rebuild run.
 *
 * The run id is server-generated and cryptographically random; it never
 * originates from customer, catalogue, or configuration input. The context
 * carries only identifiers and typed values, never product content or secrets.
 */
interface RebuildRunContextInterface
{
    /**
     * Server-generated UUID-compatible run identifier, safe for logs.
     *
     * @throws ProductIndexingException
     */
    public function runId(): string;

    /**
     * Product document schema version for this run (ProductDocumentSchema::VERSION).
     *
     * @throws ProductIndexingException
     */
    public function schemaVersion(): int;

    /**
     * Enabled store scopes for this run, deduplicated and sorted by store id.
     *
     * @return list<StoreScopeInterface>
     *
     * @throws ProductIndexingException
     */
    public function enabledScopes(): array;

    /**
     * Monotonic start time as a float (seconds since the Unix epoch).
     */
    public function startedAt(): float;
}
