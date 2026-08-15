<?php

declare(strict_types=1);

namespace Aavirbhava\AiShoppingAssistant\Model\Indexer;

use Aavirbhava\AiShoppingAssistant\Api\Indexing\FullProductReindexerInterface;
use Aavirbhava\AiShoppingAssistant\Api\Indexing\IncrementalProductIndexSchedulerInterface;
use Magento\Framework\Indexer\ActionInterface as IndexerActionInterface;
use Magento\Framework\Mview\ActionInterface as MviewActionInterface;

/**
 * Magento indexer action for the assistant product index.
 *
 * A full reindex runs the complete store-scoped rebuild orchestration. Row/list
 * updates are forwarded to the incremental scheduler, which currently refuses
 * explicitly because the queue transport is staged pending durable recovery;
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
        $this->scheduler->scheduleMany($ids);
    }

    /**
     * @param int $id
     */
    public function executeRow($id)
    {
        $this->scheduler->schedule((int) $id);
    }

    /**
     * Mview action entry point. Never called because the view has no
     * subscriptions, but kept consistent with the incremental scheduler.
     *
     * @param int|int[] $ids
     */
    public function execute($ids)
    {
        if (is_array($ids)) {
            $this->scheduler->scheduleMany($ids);
            return;
        }

        $this->scheduler->schedule((int) $ids);
    }
}
