<?php

declare(strict_types=1);

namespace Aavirbhava\AiShoppingAssistant\Model\Indexing\Clock;

final class SystemSleeper implements SleeperInterface
{
    public function sleep(int $seconds): void
    {
        sleep(max(1, $seconds));
    }
}

