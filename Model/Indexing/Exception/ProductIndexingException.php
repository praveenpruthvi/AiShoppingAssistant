<?php

declare(strict_types=1);

namespace Aavirbhava\AiShoppingAssistant\Model\Indexing\Exception;

use Aavirbhava\AiShoppingAssistant\Api\Indexing\RebuildResultInterface;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Phrase;

/**
 * Sanitized base exception for assistant-index failures.
 *
 * Messages are generic and customer-safe. They never contain product content,
 * provider payloads, API keys, configuration values, SQL, or internal traces.
 * Every failure carries a stable, machine-readable error code. The original
 * failure is preserved as the previous exception when safe.
 *
 * Concrete final subclasses describe the documented failure categories. The
 * root itself may be constructed directly for internal invariant failures of
 * immutable DTOs (invalid metrics or invalid result values).
 */
class ProductIndexingException extends LocalizedException
{
    /** No assistant-index backend is configured or available. */
    public const ERROR_BACKEND_UNAVAILABLE = 'backend_unavailable';

    /** Incremental scheduling was requested with invalid entity ids. */
    public const ERROR_INVALID_ENTITY_IDS = 'invalid_entity_ids';

    /** The run context could not be created or store configuration could not be read. */
    public const ERROR_RUN_INIT = 'run_init_failed';

    /** Preparing a store scope in the writer failed. */
    public const ERROR_STORE_PREP = 'store_prep_failed';

    /** Loading snapshots or normalizing a batch into documents failed. */
    public const ERROR_BATCH_NORMALIZATION = 'batch_normalization_failed';

    /** Writing a document batch to the backend failed. */
    public const ERROR_BATCH_WRITE = 'batch_write_failed';

    /** Promoting the run to the live index failed. */
    public const ERROR_ACTIVATION = 'activation_failed';

    /** Cleaning up an aborted run failed. */
    public const ERROR_ABORT = 'abort_failed';

    /** Incremental scheduling is not active until durable recovery is implemented. */
    public const ERROR_INCREMENTAL_SCHEDULER_UNAVAILABLE = 'incremental_scheduler_unavailable';

    /** Publishing an incremental product-index queue message failed. */
    public const ERROR_INCREMENTAL_QUEUE_PUBLISH_FAILED = 'incremental_queue_publish_failed';

    /** Durable incremental work state could not be recorded safely. */
    public const ERROR_INCREMENTAL_LEDGER_PERSISTENCE = 'incremental_ledger_persistence_failed';

    /** A per-product incremental worker lock could not be managed safely. */
    public const ERROR_INCREMENTAL_WORKER_LOCK_FAILED = 'incremental_worker_lock_failed';

    /** A full-rebuild incremental work fence could not be managed safely. */
    public const ERROR_REBUILD_FENCE_FAILED = 'rebuild_fence_failed';

    /** Capturing a Magento catalogue change for incremental indexing failed. */
    public const ERROR_INCREMENTAL_CHANGE_CAPTURE_FAILED = 'incremental_change_capture_failed';

    /** Bounded incremental reconciliation could not be recorded or scheduled safely. */
    public const ERROR_INCREMENTAL_RECONCILIATION_FAILED = 'incremental_reconciliation_failed';

    /** The live alias for incremental indexing is missing or incompatible. */
    public const ERROR_INCREMENTAL_TARGET_INVALID = 'incremental_target_invalid';

    /** Existing indexed document state could not be verified. */
    public const ERROR_INDEX_DOCUMENT_STATE_INVALID = 'index_document_state_invalid';

    /** An immutable rebuild metrics value failed validation. */
    public const ERROR_INVALID_METRICS = 'invalid_metrics';

    /** An immutable rebuild result value failed validation. */
    public const ERROR_INVALID_RESULT = 'invalid_result';

    /** The OpenSearch backend is not reachable or its connection failed. */
    public const ERROR_OPENSEARCH_BACKEND_UNAVAILABLE = 'opensearch_backend_unavailable';

    /** The OpenSearch backend connection or configuration is invalid. */
    public const ERROR_OPENSEARCH_CONFIGURATION_INVALID = 'opensearch_configuration_invalid';

    /** The OpenSearch backend lacks a required capability. */
    public const ERROR_OPENSEARCH_CAPABILITY_UNSUPPORTED = 'opensearch_capability_unsupported';

    /** An index or alias name is invalid or oversize. */
    public const ERROR_INDEX_NAME_INVALID = 'index_name_invalid';

    /** Creating a physical index failed. */
    public const ERROR_INDEX_CREATE_FAILED = 'index_create_failed';

    /** The physical index mapping is invalid. */
    public const ERROR_INDEX_MAPPING_INVALID = 'index_mapping_invalid';

    /** Generating or correlating document embeddings failed. */
    public const ERROR_EMBEDDING_ENRICHMENT = 'embedding_enrichment_failed';

    /** A bulk document write was rejected by the backend. */
    public const ERROR_BULK_INDEX = 'bulk_index_failed';

    /** A bulk response was malformed or could not be verified. */
    public const ERROR_BULK_RESPONSE_INVALID = 'bulk_response_invalid';

    /** The atomic alias activation failed. */
    public const ERROR_ALIAS_ACTIVATION = 'alias_activation_failed';

    /** Cleaning up an aborted run's physical indexes failed. */
    public const ERROR_INDEX_ABORT = 'index_abort_failed';

    /** The run state does not allow the requested lifecycle call. */
    public const ERROR_INDEX_RUN_STATE_INVALID = 'index_run_state_invalid';

    /** A document or store scope does not match the active run store. */
    public const ERROR_INDEX_SCOPE_MISMATCH = 'index_scope_mismatch';

    /** The document or embedding configuration is incompatible with the run. */
    public const ERROR_INDEX_COMPATIBILITY_MISMATCH = 'index_compatibility_mismatch';

    /**
     * @param string $errorCode stable machine-readable failure category
     * @param Phrase $phrase sanitized, customer-safe message
     * @param \Exception|null $previous original failure when safe to expose
     * @param RebuildResultInterface|null $rebuildResult aborted-run metrics, if produced
     */
    public function __construct(
        private readonly string $errorCode,
        Phrase $phrase,
        ?\Exception $previous = null,
        private readonly ?RebuildResultInterface $rebuildResult = null
    ) {
        parent::__construct($phrase, $previous);
    }

    public function errorCode(): string
    {
        return $this->errorCode;
    }

    /**
     * Aborted-run result with redacted metrics, when this failure came from a
     * full rebuild that had already begun.
     */
    public function rebuildResult(): ?RebuildResultInterface
    {
        return $this->rebuildResult;
    }
}
