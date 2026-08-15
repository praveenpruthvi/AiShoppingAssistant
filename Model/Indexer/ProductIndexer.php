<?php

declare(strict_types=1);

namespace Aavirbhava\AiShoppingAssistant\Model\Indexer;

use Aavirbhava\AiShoppingAssistant\Api\Indexing\FullProductReindexerInterface;
use Aavirbhava\AiShoppingAssistant\Api\Indexing\IncrementalProductIndexSchedulerInterface;
use Aavirbhava\AiShoppingAssistant\Model\Indexing\Exception\InvalidProductIndexEntityIdsException;
use Magento\Framework\Indexer\ActionInterface as IndexerActionInterface;
use Magento\Framework\Mview\ActionInterface as MviewActionInterface;

/**
 * Magento indexer action for the assistant product index.
 *
 * A full reindex runs the complete store-scoped rebuild orchestration. Row/list
 * updates are validated and forwarded to the durable incremental scheduler;
 * nothing is ever indexed or embedded synchronously inside this request.
 *
 * Not final: Magento generates an interceptor for every class implementing
 * ActionInterface (the platform caches after reindex), which requires a
 * non-final class.
 */
class ProductIndexer implements IndexerActionInterface, MviewActionInterface
{
    public function __construct(
        private readonly FullProductReindexerInterface $reindexer,
        private readonly IncrementalProductIndexSchedulerInterface $scheduler
    ) {
    }

    public function executeFull()
    {
        $this->reindexer->rebuild();
    }

    /**
     * @param int[] $ids
     */
    public function executeList(array $ids)
    {
        $this->scheduler->scheduleMany($this->positiveProductIds($ids));
    }

    /**
     * @param int $id
     */
    public function executeRow($id)
    {
        $this->scheduler->schedule($this->positiveProductId($id));
    }

    /**
     * Mview action entry point.
     *
     * @param int|int[] $ids
     */
    public function execute($ids)
    {
        if (is_array($ids)) {
            $this->scheduler->scheduleMany($this->positiveProductIds($ids));
            return;
        }

        $this->scheduler->schedule($this->positiveProductId($ids));
    }

    /**
     * @param array<mixed> $ids
     *
     * @return list<int>
     */
    private function positiveProductIds(array $ids): array
    {
        if ($ids === []) {
            throw new InvalidProductIndexEntityIdsException();
        }

        $normalized = [];
        foreach ($ids as $id) {
            $normalized[] = $this->positiveProductId($id);
        }

        return $normalized;
    }

    private function positiveProductId(mixed $id): int
    {
        if (is_int($id) && $id > 0) {
            return $id;
        }

        if (!is_string($id) || !preg_match('/^[1-9][0-9]*$/', $id)) {
            throw new InvalidProductIndexEntityIdsException();
        }

        $value = filter_var(
            $id,
            FILTER_VALIDATE_INT,
            ['options' => ['min_range' => 1, 'max_range' => PHP_INT_MAX]]
        );

        if (is_int($value)) {
            return $value;
        }

        throw new InvalidProductIndexEntityIdsException();
    }
}
