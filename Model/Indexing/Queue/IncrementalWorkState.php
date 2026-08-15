<?php

declare(strict_types=1);

namespace Aavirbhava\AiShoppingAssistant\Model\Indexing\Queue;

final class IncrementalWorkState
{
    public const PENDING = 'pending';
    public const QUEUED = 'queued';
    public const PROCESSING = 'processing';
    public const RETRY_WAIT = 'retry_wait';
    public const COMPLETE = 'complete';
    public const BLOCKED = 'blocked';

    /**
     * @return list<string>
     */
    public static function dueStates(): array
    {
        return [self::PENDING, self::QUEUED, self::RETRY_WAIT];
    }
}
