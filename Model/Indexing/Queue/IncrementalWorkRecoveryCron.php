<?php

declare(strict_types=1);

namespace Aavirbhava\AiShoppingAssistant\Model\Indexing\Queue;

use Aavirbhava\AiShoppingAssistant\Api\Indexing\IncrementalWorkRecoveryInterface;

final class IncrementalWorkRecoveryCron
{
    public function __construct(
        private readonly IncrementalWorkRecoveryInterface $recovery
    ) {
    }

    public function execute(): void
    {
        $this->recovery->recover();
    }
}
