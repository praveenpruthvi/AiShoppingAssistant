<?php

declare(strict_types=1);

namespace Aavirbhava\AiShoppingAssistant\Model\Indexing;

use Aavirbhava\AiShoppingAssistant\Model\Indexing\Exception\IncrementalIndexTargetInvalidException;

/**
 * Exact, non-secret physical target validated for one incremental operation.
 */
final class IncrementalIndexTarget
{
    public function __construct(
        private readonly string $alias,
        private readonly string $physicalIndex,
        private readonly int $storeId,
        private readonly int $websiteId,
        private readonly string $runId,
        private readonly string $runToken,
        private readonly int $schemaVersion,
        private readonly int $mappingVersion,
        private readonly int $embeddingDimensions,
        private readonly string $embeddingFingerprint,
        private readonly string $embeddingBaseUrlHash
    ) {
        if ($alias === ''
            || $physicalIndex === ''
            || $storeId < 1
            || $websiteId < 1
            || $runId === ''
            || $runToken === ''
            || $schemaVersion < 1
            || $mappingVersion < 1
            || $embeddingDimensions < 1
            || preg_match('/^[a-f0-9]{64}$/', $embeddingFingerprint) !== 1
            || preg_match('/^[a-f0-9]{64}$/', $embeddingBaseUrlHash) !== 1
        ) {
            throw new IncrementalIndexTargetInvalidException();
        }
    }

    public function alias(): string
    {
        return $this->alias;
    }

    public function physicalIndex(): string
    {
        return $this->physicalIndex;
    }

    public function storeId(): int
    {
        return $this->storeId;
    }

    public function websiteId(): int
    {
        return $this->websiteId;
    }

    public function runId(): string
    {
        return $this->runId;
    }

    public function runToken(): string
    {
        return $this->runToken;
    }

    public function schemaVersion(): int
    {
        return $this->schemaVersion;
    }

    public function mappingVersion(): int
    {
        return $this->mappingVersion;
    }

    public function embeddingDimensions(): int
    {
        return $this->embeddingDimensions;
    }

    public function embeddingFingerprint(): string
    {
        return $this->embeddingFingerprint;
    }

    public function embeddingBaseUrlHash(): string
    {
        return $this->embeddingBaseUrlHash;
    }

    public function samePhysicalTarget(self $other): bool
    {
        return $this->alias === $other->alias()
            && $this->physicalIndex === $other->physicalIndex()
            && $this->storeId === $other->storeId()
            && $this->websiteId === $other->websiteId()
            && $this->runId === $other->runId()
            && $this->runToken === $other->runToken()
            && $this->schemaVersion === $other->schemaVersion()
            && $this->mappingVersion === $other->mappingVersion()
            && $this->embeddingDimensions === $other->embeddingDimensions()
            && $this->embeddingFingerprint === $other->embeddingFingerprint()
            && $this->embeddingBaseUrlHash === $other->embeddingBaseUrlHash();
    }
}
