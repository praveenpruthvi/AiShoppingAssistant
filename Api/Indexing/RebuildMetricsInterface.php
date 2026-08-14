<?php

declare(strict_types=1);

namespace Aavirbhava\AiShoppingAssistant\Api\Indexing;

/**
 * Immutable, redacted counters for one full assistant-index rebuild run.
 *
 * The metrics contain only identifiers, counts, and timing. They never contain
 * SKUs, product names, descriptions, categories, customer data, or any content
 * that could be sensitive. All counts are non-negative. Ineligibility is broken
 * down by a bounded set of stable reason codes (see ProductEligibilityResultInterface).
 */
interface RebuildMetricsInterface
{
    public function storesConsidered(): int;

    /**
     * Active stores skipped because the assistant is disabled for them.
     */
    public function storesSkipped(): int;

    /**
     * Stores for which the run actually prepared documents.
     */
    public function storesPrepared(): int;

    /**
     * Product entity ids examined across all batches.
     */
    public function productIdsExamined(): int;

    /**
     * Raw snapshots loaded from Magento.
     */
    public function snapshotsLoaded(): int;

    /**
     * Requested product ids that could not be loaded for their store scope.
     */
    public function missingProducts(): int;

    /**
     * Documents produced and sent to the writer.
     */
    public function eligibleDocuments(): int;

    /**
     * Ineligible products keyed by a bounded reason code (never 'eligible').
     *
     * @return array<string, int>
     */
    public function ineligibleByReason(): array;

    /**
     * Batches handed to the writer (empty batches are not counted).
     */
    public function batchesWritten(): int;

    /**
     * True only when the run reached activateRun() and the index was promoted.
     */
    public function activated(): bool;

    /**
     * Total run duration in seconds, including writer activation.
     */
    public function durationSeconds(): float;
}
