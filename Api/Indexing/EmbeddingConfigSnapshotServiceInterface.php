<?php

declare(strict_types=1);

namespace Aavirbhava\AiShoppingAssistant\Api\Indexing;

use Aavirbhava\AiShoppingAssistant\Model\Config\Exception\ConfigurationException;
use Aavirbhava\AiShoppingAssistant\Model\Indexing\Exception\ProductIndexingException;

/**
 * Captures and re-validates the store embedding configuration for one run.
 *
 * capture() reads the live configuration and returns a secret-free snapshot that
 * can be embedded in the physical index mapping and passed to enrichment.
 * matches() re-reads the live configuration and reports whether the frozen
 * snapshot is still current, so a mid-run configuration change fails the run
 * instead of silently producing an incompatible index.
 */
interface EmbeddingConfigSnapshotServiceInterface
{
    /**
     * @throws ConfigurationException when the configuration cannot be read
     * @throws ProductIndexingException when the snapshot is invalid
     */
    public function capture(int $storeId): FrozenEmbeddingConfigInterface;

    /**
     * True when the frozen snapshot still matches the live configuration.
     */
    public function matches(FrozenEmbeddingConfigInterface $frozen): bool;
}