<?php

declare(strict_types=1);

namespace Aavirbhava\AiShoppingAssistant\Model\Indexing\RebuildFence;

use Aavirbhava\AiShoppingAssistant\Api\Indexing\RebuildFenceInterface;
use Aavirbhava\AiShoppingAssistant\Model\Indexing\Clock\ClockInterface;
use Aavirbhava\AiShoppingAssistant\Model\Indexing\Exception\RebuildFenceException;
use Aavirbhava\AiShoppingAssistant\Model\Indexing\Queue\LeaseTokenGeneratorInterface;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Adapter\AdapterInterface;

final class DbRebuildFence implements RebuildFenceInterface
{
    public const TABLE = 'aavirbhava_ai_rebuild_fence';
    public const FENCE_ID = 1;

    public function __construct(
        private readonly ResourceConnection $resource,
        private readonly ClockInterface $clock,
        private readonly LeaseTokenGeneratorInterface $tokenGenerator
    ) {
    }

    public function acquire(int $leaseSeconds): string
    {
        $this->assertLeaseSeconds($leaseSeconds);

        return $this->wrap(function (AdapterInterface $connection) use ($leaseSeconds): string {
            $connection->beginTransaction();

            try {
                $this->ensureRow($connection);
                $row = $this->lockedRow($connection);
                $now = $this->now();

                if ($this->active($row, $now)) {
                    throw new RebuildFenceException();
                }

                $token = $this->tokenGenerator->generate();
                $this->assertOwnerToken($token);

                $updated = (int)$connection->update(
                    $this->table(),
                    [
                        'is_active' => 1,
                        'owner_token' => $token,
                        'lease_expires_at' => $this->future($leaseSeconds),
                        'updated_at' => $now,
                    ],
                    [
                        'fence_id = ?' => self::FENCE_ID,
                        'updated_at = ?' => (string)$row['updated_at'],
                    ]
                );

                if ($updated !== 1) {
                    throw new RebuildFenceException();
                }

                $connection->commit();

                return $token;
            } catch (\Throwable $throwable) {
                $connection->rollBack();

                throw $throwable instanceof RebuildFenceException
                    ? $throwable
                    : new RebuildFenceException();
            }
        });
    }

    public function renew(string $ownerToken, int $leaseSeconds): void
    {
        $this->assertOwnerToken($ownerToken);
        $this->assertLeaseSeconds($leaseSeconds);

        $this->wrap(function (AdapterInterface $connection) use ($ownerToken, $leaseSeconds): void {
            $updated = (int)$connection->update(
                $this->table(),
                [
                    'lease_expires_at' => $this->future($leaseSeconds),
                    'updated_at' => $this->now(),
                ],
                [
                    'fence_id = ?' => self::FENCE_ID,
                    'is_active = ?' => 1,
                    'owner_token = ?' => $ownerToken,
                    'lease_expires_at > ?' => $this->now(),
                ]
            );

            if ($updated !== 1) {
                throw new RebuildFenceException();
            }
        });
    }

    public function assertOwned(string $ownerToken): void
    {
        $this->assertOwnerToken($ownerToken);

        $this->wrap(function (AdapterInterface $connection) use ($ownerToken): void {
            $row = $connection->fetchRow(
                $connection->select()
                    ->from($this->table())
                    ->where('fence_id = ?', self::FENCE_ID)
                    ->where('is_active = ?', 1)
                    ->where('owner_token = ?', $ownerToken)
                    ->where('lease_expires_at > ?', $this->now())
                    ->limit(1)
            );

            if (!is_array($row)) {
                throw new RebuildFenceException();
            }
        });
    }

    public function release(string $ownerToken): void
    {
        $this->assertOwnerToken($ownerToken);

        $this->wrap(function (AdapterInterface $connection) use ($ownerToken): void {
            $updated = (int)$connection->update(
                $this->table(),
                [
                    'is_active' => 0,
                    'owner_token' => null,
                    'lease_expires_at' => null,
                    'updated_at' => $this->now(),
                ],
                [
                    'fence_id = ?' => self::FENCE_ID,
                    'is_active = ?' => 1,
                    'owner_token = ?' => $ownerToken,
                ]
            );

            if ($updated !== 1) {
                throw new RebuildFenceException();
            }
        });
    }

    /**
     * @return array<string, mixed>
     */
    private function lockedRow(AdapterInterface $connection): array
    {
        $row = $connection->fetchRow(
            $connection->select()
                ->from($this->table())
                ->where('fence_id = ?', self::FENCE_ID)
                ->limit(1)
                ->forUpdate(true)
        );

        if (!is_array($row)) {
            throw new RebuildFenceException();
        }

        return $row;
    }

    private function ensureRow(AdapterInterface $connection): void
    {
        $connection->insertOnDuplicate(
            $this->table(),
            [
                'fence_id' => self::FENCE_ID,
                'is_active' => 0,
                'owner_token' => null,
                'lease_expires_at' => null,
                'created_at' => $this->now(),
                'updated_at' => $this->now(),
            ],
            []
        );
    }

    /**
     * @param array<string, mixed> $row
     */
    private function active(array $row, string $now): bool
    {
        return (int)$row['is_active'] === 1
            && is_string($row['owner_token'])
            && is_string($row['lease_expires_at'])
            && $row['lease_expires_at'] > $now;
    }

    private function assertOwnerToken(string $token): void
    {
        if (!preg_match('/^[A-Za-z0-9_-]{32,64}$/', $token)) {
            throw new RebuildFenceException();
        }
    }

    private function assertLeaseSeconds(int $leaseSeconds): void
    {
        if ($leaseSeconds < 1 || $leaseSeconds > 3600) {
            throw new RebuildFenceException();
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
        return $this->clock->now()->modify('+' . $seconds . ' seconds')->format('Y-m-d H:i:s');
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
        } catch (RebuildFenceException $exception) {
            throw $exception;
        } catch (\Throwable $throwable) {
            throw new RebuildFenceException();
        }
    }
}

