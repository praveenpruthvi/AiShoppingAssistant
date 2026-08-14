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

    /** Incremental scheduling is not available because queues are not implemented. */
    public const ERROR_INCREMENTAL_SCHEDULER_UNAVAILABLE = 'incremental_scheduler_unavailable';

    /** An immutable rebuild metrics value failed validation. */
    public const ERROR_INVALID_METRICS = 'invalid_metrics';

    /** An immutable rebuild result value failed validation. */
    public const ERROR_INVALID_RESULT = 'invalid_result';

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
