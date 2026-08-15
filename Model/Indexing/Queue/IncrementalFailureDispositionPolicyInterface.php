<?php

declare(strict_types=1);

namespace Aavirbhava\AiShoppingAssistant\Model\Indexing\Queue;

interface IncrementalFailureDispositionPolicyInterface
{
    public function classify(\Throwable $throwable, int $attempts): IncrementalFailureDisposition;
}
