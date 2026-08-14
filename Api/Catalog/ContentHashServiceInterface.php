<?php

declare(strict_types=1);

namespace Aavirbhava\AiShoppingAssistant\Api\Catalog;

/**
 * Deterministic SHA-256 hashing of normalized document payloads.
 *
 * The hash makes index writes idempotent: unchanged content produces an
 * identical digest and can safely skip re-embedding. The canonical encoding is
 * recursive key sorting for associative arrays while preserving list order.
 */
interface ContentHashServiceInterface
{
    /**
     * Returns a lowercase 64-character SHA-256 hex digest of the payload.
     *
     * @param array<mixed> $data
     *
     * @throws \Aavirbhava\AiShoppingAssistant\Model\Catalog\Exception\CatalogException
     *     when the payload cannot be encoded (e.g. contains unsupported types)
     */
    public function hash(array $data): string;
}
