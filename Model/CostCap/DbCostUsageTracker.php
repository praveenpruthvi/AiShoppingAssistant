<?php

declare(strict_types=1);

namespace Aavirbhava\AiShoppingAssistant\Model\CostCap;

use Aavirbhava\AiShoppingAssistant\Api\CostCap\CostUsageSnapshotInterface;
use Aavirbhava\AiShoppingAssistant\Api\CostCap\CostUsageTrackerInterface;
use Aavirbhava\AiShoppingAssistant\Model\CostCap\Exception\CostCapException;
use Aavirbhava\AiShoppingAssistant\Model\Indexing\Clock\ClockInterface;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Adapter\AdapterInterface;
use Magento\Framework\Phrase;
use Zend_Db_Expr;

/**
 * Real, concurrency-safe atomic-increment persistence, mirroring
 * Model\Indexing\Queue\DbIncrementalWorkLedger/Model\Indexing\RebuildFence\
 * DbRebuildFence's own ResourceConnection-direct, insertOnDuplicate/
 * Zend_Db_Expr-arithmetic style. Simpler than either of those: a single
 * insertOnDuplicate covers the whole increment (no read-then-write, no
 * transaction needed), and threshold-notification dedup is a single
 * compare-and-swap UPDATE — no lease/generation machinery required since
 * there is nothing here that needs to be "claimed" for exclusive work, only
 * incremented and, once, notified.
 */
final class DbCostUsageTracker implements CostUsageTrackerInterface
{
    private const TABLE = 'aavirbhava_ai_cost_cap_usage';

    public function __construct(
        private readonly ResourceConnection $resource,
        private readonly ClockInterface $clock
    ) {
    }

    public function recordUsage(
        string $periodKey,
        string $periodType,
        int $inputTokens,
        int $outputTokens,
        float $costAmount
    ): void {
        if ($periodKey === '' || $inputTokens < 0 || $outputTokens < 0 || $costAmount < 0.0) {
            throw new CostCapException(new Phrase('Invalid cost usage data.'));
        }

        $now = $this->now();

        $this->wrap(function (AdapterInterface $connection) use (
            $periodKey,
            $periodType,
            $inputTokens,
            $outputTokens,
            $costAmount,
            $now
        ): void {
            $connection->insertOnDuplicate(
                $this->table(),
                [
                    'period_key' => $periodKey,
                    'period_type' => $periodType,
                    'input_tokens' => $inputTokens,
                    'output_tokens' => $outputTokens,
                    'cost_amount' => $costAmount,
                    'notified_threshold_rank' => CostCapThreshold::NONE,
                    'created_at' => $now,
                    'updated_at' => $now,
                ],
                [
                    'input_tokens' => new Zend_Db_Expr('input_tokens + ' . $inputTokens),
                    'output_tokens' => new Zend_Db_Expr('output_tokens + ' . $outputTokens),
                    'cost_amount' => new Zend_Db_Expr('cost_amount + ' . sprintf('%.6F', $costAmount)),
                    'updated_at' => new Zend_Db_Expr($connection->quote($now)),
                ]
            );
        });
    }

    public function currentUsage(string $periodKey): CostUsageSnapshotInterface
    {
        return $this->wrap(function (AdapterInterface $connection) use ($periodKey): CostUsageSnapshotInterface {
            $row = $connection->fetchRow(
                $connection->select()
                    ->from($this->table())
                    ->where('period_key = ?', $periodKey)
                    ->limit(1)
            );

            if (!is_array($row)) {
                return new CostUsageSnapshot(false, 0.0, CostCapThreshold::NONE);
            }

            return new CostUsageSnapshot(true, (float) $row['cost_amount'], (int) $row['notified_threshold_rank']);
        });
    }

    public function claimThresholdNotification(string $periodKey, int $thresholdRank): bool
    {
        if ($thresholdRank < CostCapThreshold::WARNING || $thresholdRank > CostCapThreshold::CAP) {
            throw new CostCapException(new Phrase('Invalid cost cap threshold rank.'));
        }

        return $this->wrap(function (AdapterInterface $connection) use ($periodKey, $thresholdRank): bool {
            $updated = (int) $connection->update(
                $this->table(),
                [
                    'notified_threshold_rank' => $thresholdRank,
                    'updated_at' => $this->now(),
                ],
                [
                    'period_key = ?' => $periodKey,
                    'notified_threshold_rank < ?' => $thresholdRank,
                ]
            );

            return $updated === 1;
        });
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
        } catch (CostCapException $exception) {
            throw $exception;
        } catch (\Throwable $throwable) {
            throw new CostCapException(
                new Phrase('Cost cap usage tracking failed.'),
                $throwable instanceof \Exception ? $throwable : null
            );
        }
    }
}
