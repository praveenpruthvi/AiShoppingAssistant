<?php

declare(strict_types=1);

namespace Aavirbhava\AiShoppingAssistant\Model\Indexing\Queue;

final class IncrementalFailureDisposition
{
    public function __construct(
        private readonly bool $retryable,
        private readonly string $errorCode,
        private readonly int $delaySeconds
    ) {
        if ($errorCode === '' || $delaySeconds < 0) {
            throw new \InvalidArgumentException('Invalid incremental failure disposition.');
        }
    }

    public function retryable(): bool
    {
        return $this->retryable;
    }

    public function errorCode(): string
    {
        return $this->errorCode;
    }

    public function delaySeconds(): int
    {
        return $this->delaySeconds;
    }
}
