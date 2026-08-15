<?php

declare(strict_types=1);

namespace Aavirbhava\AiShoppingAssistant\Model\Indexing\Clock;

interface ClockInterface
{
    public function now(): \DateTimeImmutable;
}
