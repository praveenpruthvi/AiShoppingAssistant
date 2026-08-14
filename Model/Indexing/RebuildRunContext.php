<?php

declare(strict_types=1);

namespace Aavirbhava\AiShoppingAssistant\Model\Indexing;

use Aavirbhava\AiShoppingAssistant\Api\Indexing\RebuildRunContextInterface;
use Aavirbhava\AiShoppingAssistant\Api\Store\StoreScopeInterface;
use Aavirbhava\AiShoppingAssistant\Model\Indexing\Exception\ProductIndexRunInitException;

final readonly class RebuildRunContext implements RebuildRunContextInterface
{
    /**
     * Regex a server-generated UUID v4 run id must match.
     */
    public const RUN_ID_PATTERN = '/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/';

    /**
     * @param string $runId server-generated UUID-compatible run identifier
     * @param int $schemaVersion product document schema version
     * @param list<StoreScopeInterface> $enabledScopes deduplicated, store-id sorted scopes
     * @param float $startedAt monotonic start time in seconds
     */
    public function __construct(
        private string $runId,
        private int $schemaVersion,
        private array $enabledScopes,
        private float $startedAt
    ) {
        if (preg_match(self::RUN_ID_PATTERN, $runId) !== 1) {
            throw new ProductIndexRunInitException();
        }

        if ($schemaVersion < 1) {
            throw new ProductIndexRunInitException();
        }

        if ($enabledScopes === []) {
            throw new ProductIndexRunInitException();
        }

        foreach ($enabledScopes as $scope) {
            if (!$scope instanceof StoreScopeInterface) {
                throw new ProductIndexRunInitException();
            }
        }

        if ($startedAt < 0) {
            throw new ProductIndexRunInitException();
        }
    }

    public function runId(): string
    {
        return $this->runId;
    }

    public function schemaVersion(): int
    {
        return $this->schemaVersion;
    }

    public function enabledScopes(): array
    {
        return $this->enabledScopes;
    }

    public function startedAt(): float
    {
        return $this->startedAt;
    }
}
