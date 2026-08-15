<?php

declare(strict_types=1);

namespace Aavirbhava\AiShoppingAssistant\Model\Indexing\Naming;

use Aavirbhava\AiShoppingAssistant\Api\Indexing\IndexNamingServiceInterface;
use Aavirbhava\AiShoppingAssistant\Api\Indexing\RebuildRunContextInterface;
use Aavirbhava\AiShoppingAssistant\Api\Store\StoreScopeInterface;
use Aavirbhava\AiShoppingAssistant\Model\Indexing\Exception\ProductIndexNameInvalidException;

/**
 * Deterministic naming of store-scoped assistant aliases and physical indexes.
 *
 * The prefix is validated per call; invalid or oversize values fail closed with
 * a generic exception and are never echoed. Physical run indexes embed a
 * sanitized, lowercase alphanumeric token derived from the server-generated run
 * id so every run is globally unique and audit-friendly.
 */
final class IndexNamingService implements IndexNamingServiceInterface
{
    public function readAlias(string $prefix, StoreScopeInterface $scope): string
    {
        $prefix = $this->requirePrefix($prefix);

        return sprintf('%s_store_%d_%s', $prefix, $scope->storeId(), self::READ_ALIAS_SUFFIX);
    }

    public function physicalIndex(
        string $prefix,
        StoreScopeInterface $scope,
        RebuildRunContextInterface $context
    ): string {
        $prefix = $this->requirePrefix($prefix);

        return sprintf(
            '%s_store_%d_%s_%s',
            $prefix,
            $scope->storeId(),
            self::RUN_SEGMENT,
            $this->runToken($context->runId())
        );
    }

    public function isAssistantOwnedIndex(string $prefix, string $indexName): bool
    {
        return $this->parseAssistantIndex($prefix, $indexName) !== null;
    }

    public function parseAssistantIndex(string $prefix, string $indexName): ?array
    {
        if (!$this->isPrefixValid($prefix)) {
            return null;
        }

        $pattern = sprintf('/^%s_store_(\d+)_run_([a-z0-9]{1,%d})$/', preg_quote($prefix, '/'), self::MAX_RUN_TOKEN_LENGTH);

        if (preg_match($pattern, $indexName, $matches) !== 1) {
            return null;
        }

        $storeId = (int)$matches[1];
        if ($storeId < 1) {
            return null;
        }

        return ['store_id' => $storeId, 'run_token' => $matches[2]];
    }

    public function isPrefixValid(string $prefix): bool
    {
        return preg_match(self::PREFIX_PATTERN, $prefix) === 1;
    }

    private function requirePrefix(string $prefix): string
    {
        if (!$this->isPrefixValid($prefix)) {
            throw new ProductIndexNameInvalidException();
        }

        return $prefix;
    }

    /**
     * Converts a UUID run id into a lowercase alphanumeric token, trimmed to the
     * configured maximum length. The token is deterministic and collision-safe
     * because the input is a server-generated UUID v4.
     */
    private function runToken(string $runId): string
    {
        $token = preg_replace('/[^a-zA-Z0-9]/', '', $runId) ?? '';
        $token = strtolower($token);
        $token = substr($token, 0, self::MAX_RUN_TOKEN_LENGTH);

        if ($token === '') {
            throw new ProductIndexNameInvalidException();
        }

        return $token;
    }
}
