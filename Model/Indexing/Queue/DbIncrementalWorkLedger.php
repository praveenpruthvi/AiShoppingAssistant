<?php

declare(strict_types=1);

namespace Aavirbhava\AiShoppingAssistant\Model\Indexing\Queue;

use Aavirbhava\AiShoppingAssistant\Api\Indexing\IncrementalWorkClaimInterface;
use Aavirbhava\AiShoppingAssistant\Api\Indexing\IncrementalWorkLedgerInterface;
use Aavirbhava\AiShoppingAssistant\Model\Indexing\Clock\ClockInterface;
use Aavirbhava\AiShoppingAssistant\Model\Indexing\Exception\IncrementalLedgerPersistenceException;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Adapter\AdapterInterface;
use Zend_Db_Expr;

final class DbIncrementalWorkLedger implements IncrementalWorkLedgerInterface
{
    private const TABLE = 'aavirbhava_ai_incremental_product_work';
    private const MAX_ATTEMPTS = 5;
    private const LEASE_SECONDS = 300;
    private const EXPIRED_LEASE_ERROR = 'lease_expired';

    public function __construct(
        private readonly ResourceConnection $resource,
        private readonly ClockInterface $clock,
        private readonly LeaseTokenGeneratorInterface $tokenGenerator
    ) {
    }

    public function recordProductChanges(array $productIds): void
    {
        $now = $this->now();

        foreach ($this->normalizeIds($productIds) as $productId) {
            $this->wrap(
                function (AdapterInterface $connection) use ($productId, $now): void {
                    $connection->insertOnDuplicate(
                        $this->table(),
                        [
                            'product_id' => $productId,
                            'generation' => 1,
                            'state' => IncrementalWorkState::PENDING,
                            'attempts' => 0,
                            'next_attempt_at' => $now,
                            'lease_token' => null,
                            'lease_expires_at' => null,
                            'last_error_code' => null,
                            'created_at' => $now,
                            'updated_at' => $now,
                        ],
                        [
                            'generation' => new Zend_Db_Expr('generation + 1'),
                            'state' => new Zend_Db_Expr($connection->quote(IncrementalWorkState::PENDING)),
                            'attempts' => new Zend_Db_Expr('0'),
                            'next_attempt_at' => new Zend_Db_Expr($connection->quote($now)),
                            'lease_token' => new Zend_Db_Expr('NULL'),
                            'lease_expires_at' => new Zend_Db_Expr('NULL'),
                            'last_error_code' => new Zend_Db_Expr('NULL'),
                            'updated_at' => new Zend_Db_Expr($connection->quote($now)),
                        ]
                    );
                }
            );
        }
    }

    public function claimDueWork(int $productId): ?IncrementalWorkClaimInterface
    {
        if ($productId < 1) {
            return null;
        }

        return $this->wrap(function (AdapterInterface $connection) use ($productId): ?IncrementalWorkClaimInterface {
            $dueCondition = sprintf(
                'state = %s OR (state IN (%s) AND next_attempt_at <= %s)',
                $connection->quote(IncrementalWorkState::QUEUED),
                implode(
                    ',',
                    [
                        $connection->quote(IncrementalWorkState::PENDING),
                        $connection->quote(IncrementalWorkState::RETRY_WAIT),
                    ]
                ),
                $connection->quote($this->now())
            );
            $row = $connection->fetchRow(
                $connection->select()
                    ->from($this->table())
                    ->where('product_id = ?', $productId)
                    ->where('attempts < ?', self::MAX_ATTEMPTS)
                    ->where('(' . $dueCondition . ')')
                    ->limit(1)
            );

            if (!is_array($row)) {
                return null;
            }

            $token = $this->tokenGenerator->generate();
            $generation = (int)$row['generation'];
            $updated = $connection->update(
                $this->table(),
                [
                    'state' => IncrementalWorkState::PROCESSING,
                    'lease_token' => $token,
                    'lease_expires_at' => $this->future(self::LEASE_SECONDS),
                    'updated_at' => $this->now(),
                ],
                [
                    'product_id = ?' => $productId,
                    'generation = ?' => $generation,
                    'state = ?' => (string)$row['state'],
                ]
            );

            if ((int)$updated !== 1) {
                return null;
            }

            return new IncrementalWorkClaim($productId, $generation, $token);
        });
    }

    public function complete(IncrementalWorkClaimInterface $claim): bool
    {
        return $this->exactClaimUpdate($claim, [
            'state' => IncrementalWorkState::COMPLETE,
            'next_attempt_at' => $this->now(),
            'lease_token' => null,
            'lease_expires_at' => null,
            'last_error_code' => null,
            'updated_at' => $this->now(),
        ]);
    }

    public function recordRetry(IncrementalWorkClaimInterface $claim, string $errorCode, int $delaySeconds): bool
    {
        $row = $this->rowForClaim($claim);
        if ($row === null) {
            return false;
        }

        $attempts = (int)$row['attempts'] + 1;
        $state = $attempts >= self::MAX_ATTEMPTS ? IncrementalWorkState::BLOCKED : IncrementalWorkState::RETRY_WAIT;

        return $this->exactClaimUpdate($claim, [
            'state' => $state,
            'attempts' => $attempts,
            'next_attempt_at' => $state === IncrementalWorkState::BLOCKED ? $this->now() : $this->future($delaySeconds),
            'lease_token' => null,
            'lease_expires_at' => null,
            'last_error_code' => $this->sanitizeCode($errorCode),
            'updated_at' => $this->now(),
        ]);
    }

    public function recordTerminal(IncrementalWorkClaimInterface $claim, string $errorCode): bool
    {
        return $this->exactClaimUpdate($claim, [
            'state' => IncrementalWorkState::BLOCKED,
            'next_attempt_at' => $this->now(),
            'lease_token' => null,
            'lease_expires_at' => null,
            'last_error_code' => $this->sanitizeCode($errorCode),
            'updated_at' => $this->now(),
        ]);
    }

    public function recoverExpiredLeases(int $limit): int
    {
        $ids = $this->expiredLeaseIds($limit);
        $count = 0;

        foreach ($ids as $productId) {
            $count += $this->wrap(
                fn(AdapterInterface $connection): int => (int)$connection->update(
                    $this->table(),
                    [
                        'state' => IncrementalWorkState::RETRY_WAIT,
                        'next_attempt_at' => $this->now(),
                        'lease_token' => null,
                        'lease_expires_at' => null,
                        'last_error_code' => self::EXPIRED_LEASE_ERROR,
                        'updated_at' => $this->now(),
                    ],
                    [
                        'product_id = ?' => $productId,
                        'state = ?' => IncrementalWorkState::PROCESSING,
                        'lease_expires_at <= ?' => $this->now(),
                    ]
                )
            );
        }

        return $count;
    }

    public function dueProductIds(int $limit): array
    {
        if ($limit < 1) {
            return [];
        }

        return $this->wrap(function (AdapterInterface $connection) use ($limit): array {
            $rows = $connection->fetchCol(
                $connection->select()
                    ->from($this->table(), ['product_id'])
                    ->where('attempts < ?', self::MAX_ATTEMPTS)
                    ->where('state IN (?)', IncrementalWorkState::dueStates())
                    ->where('next_attempt_at <= ?', $this->now())
                    ->order(['next_attempt_at ASC', 'product_id ASC'])
                    ->limit($limit)
            );

            return array_map('intval', $rows);
        });
    }

    public function markQueuedForWakeup(int $productId, int $visibilityTimeoutSeconds): ?IncrementalWorkClaimInterface
    {
        if ($productId < 1 || $visibilityTimeoutSeconds < 1) {
            return null;
        }

        return $this->wrap(function (AdapterInterface $connection) use (
            $productId,
            $visibilityTimeoutSeconds
        ): ?IncrementalWorkClaimInterface {
            $row = $connection->fetchRow(
                $connection->select()
                    ->from($this->table())
                    ->where('product_id = ?', $productId)
                    ->where('attempts < ?', self::MAX_ATTEMPTS)
                    ->where('state IN (?)', IncrementalWorkState::dueStates())
                    ->where('next_attempt_at <= ?', $this->now())
                    ->limit(1)
            );

            if (!is_array($row)) {
                return null;
            }

            $generation = (int)$row['generation'];
            $token = $this->tokenGenerator->generate();
            $updated = $connection->update(
                $this->table(),
                [
                    'state' => IncrementalWorkState::QUEUED,
                    'next_attempt_at' => $this->future($visibilityTimeoutSeconds),
                    'lease_token' => $token,
                    'lease_expires_at' => null,
                    'updated_at' => $this->now(),
                ],
                [
                    'product_id = ?' => $productId,
                    'generation = ?' => $generation,
                    'state = ?' => (string)$row['state'],
                    'next_attempt_at <= ?' => $this->now(),
                ]
            );

            if ((int)$updated !== 1) {
                return null;
            }

            return new IncrementalWorkClaim($productId, $generation, $token);
        });
    }

    public function releaseQueuedWakeup(IncrementalWorkClaimInterface $claim): bool
    {
        return $this->wrap(
            fn(AdapterInterface $connection): bool => (int)$connection->update(
                $this->table(),
                [
                    'state' => IncrementalWorkState::PENDING,
                    'next_attempt_at' => $this->now(),
                    'lease_token' => null,
                    'updated_at' => $this->now(),
                ],
                [
                    'product_id = ?' => $claim->productId(),
                    'generation = ?' => $claim->generation(),
                    'lease_token = ?' => $claim->leaseToken(),
                    'state = ?' => IncrementalWorkState::QUEUED,
                ]
            ) === 1
        );
    }

    /**
     * @param array<mixed> $productIds
     *
     * @return list<int>
     */
    private function normalizeIds(array $productIds): array
    {
        if ($productIds === []) {
            return [];
        }

        $ids = [];
        foreach ($productIds as $productId) {
            if (!is_int($productId) || $productId < 1) {
                throw new \InvalidArgumentException('Invalid product id.');
            }
            $ids[] = $productId;
        }

        $ids = array_values(array_unique($ids));
        sort($ids);

        return $ids;
    }

    /**
     * @param array<string, mixed> $data
     */
    private function exactClaimUpdate(IncrementalWorkClaimInterface $claim, array $data): bool
    {
        return $this->wrap(
            fn(AdapterInterface $connection): bool => (int)$connection->update(
                $this->table(),
                $data,
                [
                    'product_id = ?' => $claim->productId(),
                    'generation = ?' => $claim->generation(),
                    'lease_token = ?' => $claim->leaseToken(),
                    'state = ?' => IncrementalWorkState::PROCESSING,
                ]
            ) === 1
        );
    }

    /**
     * @return array<string, mixed>|null
     */
    private function rowForClaim(IncrementalWorkClaimInterface $claim): ?array
    {
        return $this->wrap(function (AdapterInterface $connection) use ($claim): ?array {
            $row = $connection->fetchRow(
                $connection->select()
                    ->from($this->table())
                    ->where('product_id = ?', $claim->productId())
                    ->where('generation = ?', $claim->generation())
                    ->where('lease_token = ?', $claim->leaseToken())
                    ->where('state = ?', IncrementalWorkState::PROCESSING)
                    ->limit(1)
            );

            return is_array($row) ? $row : null;
        });
    }

    /**
     * @return list<int>
     */
    private function expiredLeaseIds(int $limit): array
    {
        if ($limit < 1) {
            return [];
        }

        return $this->wrap(function (AdapterInterface $connection) use ($limit): array {
            $rows = $connection->fetchCol(
                $connection->select()
                    ->from($this->table(), ['product_id'])
                    ->where('state = ?', IncrementalWorkState::PROCESSING)
                    ->where('lease_expires_at <= ?', $this->now())
                    ->order(['lease_expires_at ASC', 'product_id ASC'])
                    ->limit($limit)
            );

            return array_map('intval', $rows);
        });
    }

    private function sanitizeCode(string $errorCode): string
    {
        return preg_match('/^[a-z][a-z0-9_]{0,63}$/', $errorCode) ? $errorCode : 'unknown';
    }

    private function table(): string
    {
        return $this->resource->getTableName(self::TABLE);
    }

    private function now(): string
    {
        return $this->clock->now()->format('Y-m-d H:i:s');
    }

    private function future(int $seconds): string
    {
        return $this->clock->now()->modify('+' . max(1, $seconds) . ' seconds')->format('Y-m-d H:i:s');
    }

    /**
     * @template T
     * @param callable(AdapterInterface): T $callback
     * @return T
     */
    private function wrap(callable $callback): mixed
    {
        try {
            return $callback($this->resource->getConnection());
        } catch (IncrementalLedgerPersistenceException $exception) {
            throw $exception;
        } catch (\Throwable $throwable) {
            throw new IncrementalLedgerPersistenceException();
        }
    }
}
