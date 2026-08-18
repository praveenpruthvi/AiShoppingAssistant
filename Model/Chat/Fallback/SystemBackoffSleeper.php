<?php

declare(strict_types=1);

namespace Aavirbhava\AiShoppingAssistant\Model\Chat\Fallback;

final class SystemBackoffSleeper implements BackoffSleeperInterface
{
    public function sleepMilliseconds(int $milliseconds): void
    {
        usleep(max(0, $milliseconds) * 1000);
    }
}
