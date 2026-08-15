<?php

declare(strict_types=1);

namespace Aavirbhava\AiShoppingAssistant\Model\Indexing\Queue;

use Aavirbhava\AiShoppingAssistant\Api\Indexing\IncrementalWorkClaimInterface;
use Aavirbhava\AiShoppingAssistant\Api\Indexing\IncrementalWorkLedgerInterface;
use Aavirbhava\AiShoppingAssistant\Model\Indexing\Clock\ClockInterface;
use Aavirbhava\AiShoppingAssistant\Model\Indexing\Exception\IncrementalLedgerPersistenceException;
use Aavirbhava\AiShoppingAssistant\Model\Indexing\Exception\InvalidProductIndexEntityIdsException;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Adapter\AdapterInterface;
use Zend_Db_Expr;

final class DbIncrementalWorkLedger implements IncrementalWorkLedgerInterface
{
    private const TABLE = 'aavirbhava_ai_incremental_product_work';
    private const MAX_ATTEMPTS = IncrementalFailureDispositionPolicy::MAX_ATTEMPTS;
    private const BASE_DELAY_SECONDS = IncrementalFailureDispositionPolicy::BASE_DELAY_SECONDS;
    private const MAX_DELAY_SECONDS = IncrementalFailureDispositionPolicy::MAX_DELAY_SECONDS;
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
                            'claimed_generation' => null,
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
                            'claimed_generation' => new Zend_Db_Expr(
                                'IF(state = ' . $connection->quote(IncrementalWorkState::PROCESSING)
                                . ', claimed_generation, NULL)'
                            ),
                            'state' => new Zend_Db_Expr(
                                'IF(state = ' . $connection->quote(IncrementalWorkState::PROCESSING)
                                . ', state, ' . $connection->quote(IncrementalWorkState::PENDING) . ')'
                            ),
                            'attempts' => new Zend_Db_Expr(
                                'IF(state = ' . $connection->quote(IncrementalWorkState::PROCESSING)
                                . ', attempts, 0)'
                            ),
                            'next_attempt_at' => new Zend_Db_Expr(
                                'IF(state = ' . $connection->quote(IncrementalWorkState::PROCESSING)
                                . ', next_attempt_at, ' . $connection->quote($now) . ')'
                            ),
                            'lease_token' => new Zend_Db_Expr(
                                'IF(state = ' . $connection->quote(IncrementalWorkState::PROCESSING)
                                . ', lease_token, NULL)'
                            ),
                            'lease_expires_at' => new Zend_Db_Expr(
                                'IF(state = ' . $connection->quote(IncrementalWorkState::PROCESSING)
                                . ', lease_expires_at, NULL)'
                            ),
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
            $this->assertLeaseToken($token);
            $generation = (int)$row['generation'];
            $attempts = (int)$row['attempts'];
            $updated = $connection->update(
                $this->table(),
                [
                    'state' => IncrementalWorkState::PROCESSING,
                    'claimed_generation' => $generation,
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

            return new IncrementalWorkClaim($productId, $generation, $attempts, $token);
        });
    }

    public function complete(IncrementalWorkClaimInterface $claim): bool
    {
        return $this->finalizeClaim(
            $claim,
            fn(array $row): array => (int)$row['generation'] === $claim->generation()
                ? [
                    'state' => IncrementalWorkState::COMPLETE,
                    'claimed_generation' => null,
                    'attempts' => 0,
                    'next_attempt_at' => $this->now(),
                    'lease_token' => null,
                    'lease_expires_at' => null,
                    'last_error_code' => null,
                    'updated_at' => $this->now(),
                ]
                : $this->newerGenerationPendingData()
        );
    }

    public function recordRetry(IncrementalWorkClaimInterface $claim, string $errorCode, int $delaySeconds): bool
    {
        $this->assertRetryDelay($delaySeconds);

        return $this->finalizeClaim(
            $claim,
            function (array $row) use ($claim, $errorCode, $delaySeconds): array {
                if ((int)$row['generation'] > $claim->generation()) {
                    return $this->newerGenerationPendingData();
                }

                $attempts = (int)$row['attempts'] + 1;
                $state = $attempts >= self::MAX_ATTEMPTS
                    ? IncrementalWorkState::BLOCKED
                    : IncrementalWorkState::RETRY_WAIT;

                return [
                    'state' => $state,
                    'claimed_generation' => null,
                    'attempts' => $attempts,
                    'next_attempt_at' => $state === IncrementalWorkState::BLOCKED
                        ? $this->now()
                        : $this->future($delaySeconds),
                    'lease_token' => null,
                    'lease_expires_at' => null,
                    'last_error_code' => $this->sanitizeCode($errorCode),
                    'updated_at' => $this->now(),
                ];
            }
        );
    }

    public function recordTerminal(IncrementalWorkClaimInterface $claim, string $errorCode): bool
    {
        return $this->finalizeClaim(
            $claim,
            fn(array $row): array => (int)$row['generation'] > $claim->generation()
                ? $this->newerGenerationPendingData()
                : [
                    'state' => IncrementalWorkState::BLOCKED,
                    'claimed_generation' => null,
                    'next_attempt_at' => $this->now(),
                    'lease_token' => null,
                    'lease_expires_at' => null,
                    'last_error_code' => $this->sanitizeCode($errorCode),
                    'updated_at' => $this->now(),
                ]
        );
    }

    public function recoverExpiredLeases(int $limit): int
    {
        $ids = $this->expiredLeaseIds($limit);
        $count = 0;

        foreach ($ids as $productId) {
            $count += $this->wrap(
                function (AdapterInterface $connection) use ($productId): int {
                    $connection->beginTransaction();

                    try {
                        $row = $connection->fetchRow(
                            $connection->select()
                                ->from($this->table())
                                ->where('product_id = ?', $productId)
                                ->where('state = ?', IncrementalWorkState::PROCESSING)
                                ->where('lease_expires_at <= ?', $this->now())
                                ->limit(1)
                                ->forUpdate(true)
                        );

                        if (!is_array($row)) {
                            $connection->commit();

                            return 0;
                        }

                        $data = (int)$row['generation'] > (int)$row['claimed_generation']
                            ? $this->newerGenerationPendingData()
                            : $this->expiredLeaseData((int)$row['attempts']);

                        $updated = (int)$connection->update(
                            $this->table(),
                            $data,
                            [
                                'product_id = ?' => $productId,
                                'generation = ?' => (int)$row['generation'],
                                'state = ?' => IncrementalWorkState::PROCESSING,
                                'claimed_generation = ?' => (int)$row['claimed_generation'],
                                'lease_token = ?' => (string)$row['lease_token'],
                                'lease_expires_at <= ?' => $this->now(),
                            ]
                        );

                        if ($updated !== 1) {
                            throw new IncrementalLedgerPersistenceException();
                        }

                        $connection->commit();

                        return 1;
                    } catch (\Throwable $throwable) {
                        $connection->rollBack();

                        throw $throwable instanceof IncrementalLedgerPersistenceException
                            ? $throwable
                            : new IncrementalLedgerPersistenceException();
                    }
                }
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
            $this->assertLeaseToken($token);
            $updated = $connection->update(
                $this->table(),
                [
                    'state' => IncrementalWorkState::QUEUED,
                    'claimed_generation' => null,
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

            return new IncrementalWorkClaim($productId, $generation, (int)$row['attempts'], $token);
        });
    }

    public function releaseQueuedWakeup(IncrementalWorkClaimInterface $claim): bool
    {
        return $this->wrap(
            fn(AdapterInterface $connection): bool => (int)$connection->update(
                $this->table(),
                [
                    'state' => IncrementalWorkState::PENDING,
                    'claimed_generation' => null,
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
                throw new InvalidProductIndexEntityIdsException();
            }
            $ids[] = $productId;
        }

        $ids = array_values(array_unique($ids));
        sort($ids);

        return $ids;
    }

    /**
     * @param callable(array<string, mixed>): array<string, mixed> $dataFactory
     */
    private function finalizeClaim(IncrementalWorkClaimInterface $claim, callable $dataFactory): bool
    {
        $this->assertLeaseToken($claim->leaseToken());

        return $this->wrap(function (AdapterInterface $connection) use ($claim, $dataFactory): bool {
            $connection->beginTransaction();

            try {
                $row = $connection->fetchRow(
                    $connection->select()
                        ->from($this->table())
                        ->where('product_id = ?', $claim->productId())
                        ->where('claimed_generation = ?', $claim->generation())
                        ->where('lease_token = ?', $claim->leaseToken())
                        ->where('state = ?', IncrementalWorkState::PROCESSING)
                        ->limit(1)
                        ->forUpdate(true)
                );

                if (!is_array($row)) {
                    $connection->commit();

                    return false;
                }

                $updated = (int)$connection->update(
                    $this->table(),
                    $dataFactory($row),
                    [
                        'product_id = ?' => $claim->productId(),
                        'generation = ?' => (int)$row['generation'],
                        'claimed_generation = ?' => $claim->generation(),
                        'lease_token = ?' => $claim->leaseToken(),
                        'state = ?' => IncrementalWorkState::PROCESSING,
                    ]
                );

                if ($updated !== 1) {
                    throw new IncrementalLedgerPersistenceException();
                }

                $connection->commit();

                return true;
            } catch (\Throwable $throwable) {
                $connection->rollBack();

                throw $throwable instanceof IncrementalLedgerPersistenceException
                    ? $throwable
                    : new IncrementalLedgerPersistenceException();
            }
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

    /**
     * @return array<string, mixed>
     */
    private function newerGenerationPendingData(): array
    {
        return [
            'state' => IncrementalWorkState::PENDING,
            'claimed_generation' => null,
            'attempts' => 0,
            'next_attempt_at' => $this->now(),
            'lease_token' => null,
            'lease_expires_at' => null,
            'last_error_code' => null,
            'updated_at' => $this->now(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function expiredLeaseData(int $attempts): array
    {
        $nextAttempts = $attempts + 1;
        $state = $nextAttempts >= self::MAX_ATTEMPTS ? IncrementalWorkState::BLOCKED : IncrementalWorkState::RETRY_WAIT;

        return [
            'state' => $state,
            'claimed_generation' => null,
            'attempts' => $nextAttempts,
            'next_attempt_at' => $state === IncrementalWorkState::BLOCKED
                ? $this->now()
                : $this->future($this->retryDelay($nextAttempts)),
            'lease_token' => null,
            'lease_expires_at' => null,
            'last_error_code' => self::EXPIRED_LEASE_ERROR,
            'updated_at' => $this->now(),
        ];
    }

    private function retryDelay(int $attempt): int
    {
        $delay = self::BASE_DELAY_SECONDS * (2 ** max(0, $attempt - 1));

        return min(self::MAX_DELAY_SECONDS, $delay);
    }

    private function assertRetryDelay(int $delaySeconds): void
    {
        if ($delaySeconds < 1 || $delaySeconds > self::MAX_DELAY_SECONDS) {
            throw new IncrementalLedgerPersistenceException();
        }
    }

    private function assertLeaseToken(string $token): void
    {
        if (!preg_match('/^[A-Za-z0-9_-]{32,64}$/', $token)) {
            throw new IncrementalLedgerPersistenceException();
        }
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
