<?php

declare(strict_types=1);

namespace Aavirbhava\AiShoppingAssistant\Model\Indexing\Queue;

interface LeaseTokenGeneratorInterface
{
    public function generate(): string;
}
