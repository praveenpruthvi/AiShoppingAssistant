<?php

declare(strict_types=1);

namespace Aavirbhava\AiShoppingAssistant\Model\Indexing;

use Aavirbhava\AiShoppingAssistant\Api\Indexing\RebuildRunContextFactoryInterface;
use Aavirbhava\AiShoppingAssistant\Api\Indexing\RebuildRunContextInterface;
use Aavirbhava\AiShoppingAssistant\Api\Store\StoreScopeInterface;
use Aavirbhava\AiShoppingAssistant\Model\Catalog\ProductDocumentSchema;
use Aavirbhava\AiShoppingAssistant\Model\Indexing\Exception\ProductIndexRunInitException;

final class RebuildRunContextFactory implements RebuildRunContextFactoryInterface
{
    /**
     * @param list<StoreScopeInterface> $enabledScopes
     */
    public function create(array $enabledScopes): RebuildRunContextInterface
    {
        foreach ($enabledScopes as $scope) {
            if (!$scope instanceof StoreScopeInterface) {
                throw new ProductIndexRunInitException();
            }
        }

        $unique = [];
        foreach ($enabledScopes as $scope) {
            $unique[$scope->storeId()] = $scope;
        }

        $scopes = array_values($unique);
        usort(
            $scopes,
            static fn (StoreScopeInterface $a, StoreScopeInterface $b): int => $a->storeId() <=> $b->storeId()
        );

        if ($scopes === []) {
            throw new ProductIndexRunInitException();
        }

        try {
            $runId = $this->generateRunId();
        } catch (\Throwable $throwable) {
            throw new ProductIndexRunInitException(
                $throwable instanceof \Exception ? $throwable : null
            );
        }

        return new RebuildRunContext(
            $runId,
            ProductDocumentSchema::VERSION,
            $scopes,
            microtime(true)
        );
    }

    private function generateRunId(): string
    {
        $bytes = random_bytes(16);
        $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
        $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);

        return sprintf(
            '%08s-%04s-%04s-%04s-%012s',
            bin2hex(substr($bytes, 0, 4)),
            bin2hex(substr($bytes, 4, 2)),
            bin2hex(substr($bytes, 6, 2)),
            bin2hex(substr($bytes, 8, 2)),
            bin2hex(substr($bytes, 10, 6))
        );
    }
}
