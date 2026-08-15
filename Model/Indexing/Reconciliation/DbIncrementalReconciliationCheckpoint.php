<?php

declare(strict_types=1);

namespace Aavirbhava\AiShoppingAssistant\Model\Indexing\Reconciliation;

use Aavirbhava\AiShoppingAssistant\Model\Indexing\Clock\ClockInterface;
use Aavirbhava\AiShoppingAssistant\Model\Indexing\Exception\IncrementalReconciliationException;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Adapter\AdapterInterface;
use Zend_Db_Expr;

final class DbIncrementalReconciliationCheckpoint implements IncrementalReconciliationCheckpointInterface
{
    public const TABLE = 'aavirbhava_ai_incremental_reconciliation';
    public const CURSOR_ID = 1;

    public function __construct(
        private readonly ResourceConnection $resource,
        private readonly ClockInterface $clock
    ) {
    }

    public function current(): IncrementalReconciliationCheckpoint
    {
        return $this->wrap(function (AdapterInterface $connection): IncrementalReconciliationCheckpoint {
            $this->ensureRow($connection);
            $row = $connection->fetchRow(
                $connection->select()
                    ->from($this->table())
                    ->where('cursor_id = ?', self::CURSOR_ID)
                    ->limit(1)
            );

            if (!is_array($row) || !array_key_exists('last_product_id', $row)) {
                throw new IncrementalReconciliationException();
            }

            $lastProductId = filter_var(
                $row['last_product_id'],
                FILTER_VALIDATE_INT,
                ['options' => ['min_range' => 0, 'max_range' => PHP_INT_MAX]]
            );
            if (!is_int($lastProductId)) {
                throw new IncrementalReconciliationException();
            }

            return new IncrementalReconciliationCheckpoint($lastProductId);
        });
    }

    public function advance(int $lastProductId): void
    {
        if ($lastProductId < 1) {
            throw new IncrementalReconciliationException();
        }

        $this->updateCursor($lastProductId, null);
    }

    public function completePass(): void
    {
        $this->updateCursor(0, $this->now());
    }

    public function requestFullPass(): void
    {
        $this->updateCursor(0, null);
    }

    private function updateCursor(int $lastProductId, ?string $completedAt): void
    {
        $this->wrap(function (AdapterInterface $connection) use ($lastProductId, $completedAt): void {
            $this->ensureRow($connection);
            $updated = $connection->update(
                $this->table(),
                [
                    'last_product_id' => $lastProductId,
                    'pass_started_at' => $completedAt === null ? $this->now() : null,
                    'completed_at' => $completedAt,
                    'updated_at' => $this->now(),
                ],
                ['cursor_id = ?' => self::CURSOR_ID]
            );

            if ((int)$updated !== 1) {
                throw new IncrementalReconciliationException();
            }
        });
    }

    private function ensureRow(AdapterInterface $connection): void
    {
        $now = $this->now();
        $connection->insertOnDuplicate(
            $this->table(),
            [
                'cursor_id' => self::CURSOR_ID,
                'last_product_id' => 0,
                'pass_started_at' => null,
                'completed_at' => null,
                'created_at' => $now,
                'updated_at' => $now,
            ],
            ['cursor_id' => new Zend_Db_Expr('cursor_id')]
        );
    }

    private function table(): string
    {
        return $this->resource->getTableName(self::TABLE);
    }

    private function now(): string
    {
        return $this->clock->now()->format('Y-m-d H:i:s');
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
        } catch (IncrementalReconciliationException $exception) {
            throw $exception;
        } catch (\Throwable) {
            throw new IncrementalReconciliationException();
        }
    }
}
