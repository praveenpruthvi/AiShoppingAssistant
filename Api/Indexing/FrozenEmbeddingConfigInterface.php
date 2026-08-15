<?php

declare(strict_types=1);

namespace Aavirbhava\AiShoppingAssistant\Api\Indexing;

/**
 * Immutable snapshot of the embedding configuration frozen for one indexing run.
 *
 * The snapshot is captured once at run start and re-validated during embedding
 * generation so a mid-run configuration change cannot silently produce an index
 * incompatible with the current store configuration. It carries derived hashes
 * only: provider, model, base URL, and dimensions are the live comparison
 * fields, while fingerprint and baseUrlHash are deterministic hashes that never
 * contain the base URL itself or any secret.
 */
interface FrozenEmbeddingConfigInterface
{
    public function storeId(): int;

    public function provider(): string;

    public function model(): string;

    public function baseUrl(): string;

    public function dimensions(): int;

    /**
     * Content-hash fingerprint of the provider/model/baseUrl/dimensions tuple.
     */
    public function fingerprint(): string;

    /**
     * Content-hash of the base URL. Never the URL itself.
     */
    public function baseUrlHash(): string;
}
