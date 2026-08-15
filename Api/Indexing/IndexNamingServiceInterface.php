<?php

declare(strict_types=1);

namespace Aavirbhava\AiShoppingAssistant\Api\Indexing;

use Aavirbhava\AiShoppingAssistant\Api\Store\StoreScopeInterface;
use Aavirbhava\AiShoppingAssistant\Model\Indexing\Exception\ProductIndexingException;

/**
 * Deterministic, store-scoped naming for assistant index aliases and physical
 * indexes.
 *
 * Each store view owns exactly one read alias that always points at the
 * currently active physical index for that store:
 *
 *   <prefix>_store_<storeId>_current
 *
 * Physical indexes are immutable per run and never shared between stores or
 * runs:
 *
 *   <prefix>_store_<storeId>_run_<safeRunToken>
 *
 * Names are built exclusively from validated configuration prefixes, positive
 * store ids, and server-generated run identifiers. The service never exposes
 * invalid values: violations fail closed with a generic exception.
 */
interface IndexNamingServiceInterface
{
    /**
     * Regex the configured index prefix must match.
     *
     * Lowercase start, then lowercase letters, digits, underscore, or hyphen,
     * up to 64 characters total.
     */
    public const PREFIX_PATTERN = '/^[a-z][a-z0-9_-]{0,63}$/';

    /**
     * Maximum length of a safe run token derived from a run id.
     */
    public const MAX_RUN_TOKEN_LENGTH = 32;

    /**
     * Alias suffix for the live read alias of a store.
     */
    public const READ_ALIAS_SUFFIX = 'current';

    /**
     * Segment that marks a physical run index.
     */
    public const RUN_SEGMENT = 'run';

    /**
     * Returns the live read alias for one store view.
     *
     * @throws ProductIndexingException when the prefix is invalid
     */
    public function readAlias(string $prefix, StoreScopeInterface $scope): string;

    /**
     * Returns the immutable physical index name for one store and run.
     *
     * @throws ProductIndexingException when the prefix is invalid or the run id
     *     cannot be converted to a safe token
     */
    public function physicalIndex(
        string $prefix,
        StoreScopeInterface $scope,
        RebuildRunContextInterface $context
    ): string;

    /**
     * True when an index name is owned by the assistant for the given prefix.
     *
     * Used to decide which targets an alias may safely drop during activation
     * and which physical indexes a run may delete during cleanup. The check is
     * prefix-prefixed only, so assistant-owned names are never confused with
     * Magento's own catalogue indexes.
     */
    public function isAssistantOwnedIndex(string $prefix, string $indexName): bool;

    /**
     * Validates a configured index prefix.
     */
    public function isPrefixValid(string $prefix): bool;
}