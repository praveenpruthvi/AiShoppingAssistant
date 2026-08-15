<?php

declare(strict_types=1);

namespace Aavirbhava\AiShoppingAssistant\Api\Indexing;

interface IncrementalWorkRecoveryInterface
{
    public function recover(): int;
}
