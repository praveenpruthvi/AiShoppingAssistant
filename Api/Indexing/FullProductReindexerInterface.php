<?php

declare(strict_types=1);

namespace Aavirbhava\AiShoppingAssistant\Api\Indexing;

/**
 * Orchestrates a full rebuild of the assistant product index.
 *
 * The reindexer owns the safe algorithm: it resolves enabled store scopes with
 * explicit store-scoped configuration, streams bounded product batches, loads
 * snapshots, normalizes them, sends only eligible ProductDocuments to the
 * ProductDocumentWriter, and only activates the run after every enabled store
 * has been prepared. Failures abort the run exactly once and surface as a
 * sanitized ProductIndexingException.
 */
interface FullProductReindexerInterface
{
    /**
     * Runs a full assistant-index rebuild and returns its outcome.
     *
     * A safe no-op (no enabled store) returns a no_op result without touching
     * the document writer. Failures abort the run and throw a sanitized
     * ProductIndexingException that carries the aborted result.
     */
    public function rebuild(): RebuildResultInterface;
}
