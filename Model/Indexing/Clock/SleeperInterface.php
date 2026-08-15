<?php

declare(strict_types=1);

namespace Aavirbhava\AiShoppingAssistant\Model\Indexing\Clock;

interface SleeperInterface
{
    public function sleep(int $seconds): void;
}

