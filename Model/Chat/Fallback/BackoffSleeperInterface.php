<?php

declare(strict_types=1);

namespace Aavirbhava\AiShoppingAssistant\Model\Chat\Fallback;

/**
 * Millisecond-granular sleep for synchronous, in-request retry backoff.
 *
 * Deliberately not the existing Model\Indexing\Clock\SleeperInterface: that
 * one clamps to a 1-second minimum, which fits its async queue-recovery
 * use case but is far too coarse here — a customer is waiting on this HTTP
 * response, so backoff between retries needs to stay in the hundreds-of-
 * milliseconds range, not whole seconds.
 */
interface BackoffSleeperInterface
{
    public function sleepMilliseconds(int $milliseconds): void;
}
