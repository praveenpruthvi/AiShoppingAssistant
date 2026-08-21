<?php

declare(strict_types=1);

namespace Aavirbhava\AiShoppingAssistant\Api\Catalog;

use Aavirbhava\AiShoppingAssistant\Model\Catalog\Exception\CatalogException;

/**
 * Immutable, fully normalized product document ready for indexing.
 *
 * All text fields are sanitized and length-capped. Hashes are SHA-256 hex and
 * make the document idempotent for safe retries. Price, stock, salability, URLs,
 * and media are deliberately absent: they are always resolved from Magento at
 * retrieval or display time.
 */
interface ProductDocumentInterface
{
    /** Regex a valid SHA-256 hex digest must match. */
    public const HASH_PATTERN = '/^[a-f0-9]{64}$/';

    /**
     * Schema version of this document (see ProductDocumentSchema::VERSION).
     *
     * @throws CatalogException
     */
    public function schemaVersion(): int;

    /**
     * Stable document id, derived from store id and entity id.
     *
     * @throws CatalogException
     */
    public function documentId(): string;

    /**
     * Positive Magento product entity id.
     *
     * @throws CatalogException
     */
    public function entityId(): int;

    /**
     * Non-empty validated SKU.
     *
     * @throws CatalogException
     */
    public function sku(): string;

    /**
     * Positive Magento store view id.
     *
     * @throws CatalogException
     */
    public function storeId(): int;

    /**
     * Non-empty list of positive website ids, sorted ascending.
     *
     * @return list<int>
     *
     * @throws CatalogException
     */
    public function websiteIds(): array;

    /**
     * Non-empty Magento product type.
     *
     * @throws CatalogException
     */
    public function productType(): string;

    /**
     * Sanitized, non-empty product name.
     *
     * @throws CatalogException
     */
    public function name(): string;

    /**
     * Sanitized short description (may be empty).
     */
    public function shortDescription(): string;

    /**
     * Sanitized long description (may be empty).
     */
    public function longDescription(): string;

    /**
     * True when the product status attribute is enabled.
     */
    public function isEnabled(): bool;

    /**
     * Magento visibility flag at index time.
     */
    public function visibility(): int;

    /**
     * @return list<CategoryReferenceInterface>
     */
    public function categories(): array;

    /**
     * @return list<SearchableAttributeInterface>
     */
    public function attributes(): array;

    /**
     * Pre-assembled searchable text used for keyword retrieval.
     *
     * @throws CatalogException
     */
    public function searchableText(): string;

    /**
     * SHA-256 of the embedding payload only. Changes when searchable content
     * changes but not when status, scope, or audit fields change.
     *
     * @throws CatalogException
     */
    public function embeddingContentHash(): string;

    /**
     * SHA-256 of the complete persisted payload (excluding this field, updatedAt,
     * and any future embedding vectors).
     *
     * @throws CatalogException
     */
    public function completeDocumentHash(): string;

    /**
     * Optional catalog updated-at timestamp forwarded from the snapshot.
     */
    public function updatedAt(): ?string;

    /**
     * This product's own average rating (0-5 scale), forwarded from the
     * snapshot. Never used directly for ranking or shown to the shopper —
     * RatingSignal blends it with reviewCount() and catalogRatingAverage()
     * into a Bayesian-weighted score at ranking time.
     */
    public function ratingAverage(): float;

    /**
     * Number of approved reviews backing ratingAverage(), forwarded from
     * the snapshot.
     */
    public function reviewCount(): int;

    /**
     * The store-wide mean rating (0-5 scale) at the time this document was
     * indexed, forwarded from the snapshot.
     */
    public function catalogRatingAverage(): float;
}
