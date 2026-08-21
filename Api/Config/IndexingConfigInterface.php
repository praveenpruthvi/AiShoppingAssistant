<?php

declare(strict_types=1);

namespace Aavirbhava\AiShoppingAssistant\Api\Config;

/**
 * Immutable indexing settings for one store view.
 *
 * These values only control how catalogue data is loaded and normalized for the
 * assistant index. They never affect Magento's own catalogue or search services.
 */
interface IndexingConfigInterface
{
    /**
     * Number of products loaded per indexing batch (10-500).
     */
    public function batchSize(): int;

    /**
     * Explicit allowlist of attribute codes normalized into the assistant index
     * — sourced from AttributeIndexingSelectionRepositoryInterface (the
     * admin-controlled, global attribute selection both the native
     * product-attribute grid and this module's own bulk-select screen
     * read/write), not a per-store config field. An empty list means no
     * custom attributes are indexed.
     *
     * @return list<string>
     */
    public function searchableAttributeCodes(): array;

    /**
     * True when the product short description is part of the normalized document.
     */
    public function includeShortDescription(): bool;

    /**
     * True when the product long description is part of the normalized document.
     */
    public function includeLongDescription(): bool;

    /**
     * Reserved capability. Always false in this version; enabling the setting is
     * rejected at configuration time because variant aggregation is unavailable.
     */
    public function aggregateConfigurableVariants(): bool;

    /**
     * Total number of attribute values kept per product across the configured
     * attributes (1-500).
     */
    public function maxAttributeValuesPerProduct(): int;

    /**
     * Index prefix used to build the dedicated assistant index aliases and
     * physical indexes. Must match IndexNamingServiceInterface::PREFIX_PATTERN.
     */
    public function indexPrefix(): string;
}
