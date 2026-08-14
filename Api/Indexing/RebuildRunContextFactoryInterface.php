<?php

declare(strict_types=1);

namespace Aavirbhava\AiShoppingAssistant\Api\Indexing;

use Aavirbhava\AiShoppingAssistant\Api\Store\StoreScopeInterface;
use Aavirbhava\AiShoppingAssistant\Model\Indexing\Exception\ProductIndexingException;

/**
 * Creates the immutable context for a full assistant-index rebuild run.
 *
 * The factory is the only place that generates the server-side run id and start
 * time, so every attempt gets a distinct run identity even after a retry.
 */
interface RebuildRunContextFactoryInterface
{
    /**
     * Builds a run context for the given enabled store scopes.
     *
     * Duplicate scopes (same store id) are collapsed before the run starts.
     * An empty scope list is rejected: a run with no enabled scope is a no-op
     * and must never reach the document writer.
     *
     * @param list<StoreScopeInterface> $enabledScopes
     *
     * @throws ProductIndexingException when the run cannot be initialized.
     */
    public function create(array $enabledScopes): RebuildRunContextInterface;
}
