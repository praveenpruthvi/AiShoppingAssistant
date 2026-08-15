<?php

declare(strict_types=1);

namespace Aavirbhava\AiShoppingAssistant\Model\Indexing\Embedding;

use Aavirbhava\AiShoppingAssistant\Api\Indexing\FrozenEmbeddingConfigInterface;
use Aavirbhava\AiShoppingAssistant\Model\Indexing\Exception\IndexCompatibilityMismatchException;

/**
 * Immutable frozen embedding configuration snapshot.
 *
 * Validation is fail-closed: an empty id segment, empty provider/model/base URL,
 * non-positive dimensions, or a fingerprint/baseUrlHash that is not a 64-char
 * lowercase hex string throws a sanitized exception instead of producing a
 * document that can never match its mapping.
 */
final class FrozenEmbeddingConfig implements FrozenEmbeddingConfigInterface
{
    public function __construct(
        private readonly int $storeId,
        private readonly string $provider,
        private readonly string $model,
        private readonly string $baseUrl,
        private readonly int $dimensions,
        private readonly string $fingerprint,
        private readonly string $baseUrlHash
    ) {
        if ($storeId < 1 || $provider === '' || $model === '' || $baseUrl === '' || $dimensions < 1) {
            throw new IndexCompatibilityMismatchException();
        }
        $this->assertHex($fingerprint, 'fingerprint');
        $this->assertHex($baseUrlHash, 'base-url hash');
    }

    public function storeId(): int
    {
        return $this->storeId;
    }

    public function provider(): string
    {
        return $this->provider;
    }

    public function model(): string
    {
        return $this->model;
    }

    public function baseUrl(): string
    {
        return $this->baseUrl;
    }

    public function dimensions(): int
    {
        return $this->dimensions;
    }

    public function fingerprint(): string
    {
        return $this->fingerprint;
    }

    public function baseUrlHash(): string
    {
        return $this->baseUrlHash;
    }

    private function assertHex(string $value, string $label): void
    {
        if (preg_match('/^[a-f0-9]{64}$/', $value) !== 1) {
            throw new IndexCompatibilityMismatchException();
        }
    }
}