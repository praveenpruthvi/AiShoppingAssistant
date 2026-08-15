<?php

declare(strict_types=1);

namespace Aavirbhava\AiShoppingAssistant\Model\Indexing\Queue;

use Aavirbhava\AiShoppingAssistant\Model\Indexing\Exception\OpenSearchBackendUnavailableException;
use Aavirbhava\AiShoppingAssistant\Model\Indexing\Exception\ProductIndexingException;

final class IncrementalFailureDispositionPolicy implements IncrementalFailureDispositionPolicyInterface
{
    public const MAX_ATTEMPTS = 5;
    public const BASE_DELAY_SECONDS = 60;
    public const MAX_DELAY_SECONDS = 3600;

    public function classify(\Throwable $throwable, int $attempts): IncrementalFailureDisposition
    {
        $errorCode = $throwable instanceof ProductIndexingException ? $throwable->errorCode() : 'unknown';
        $nextAttempt = $attempts + 1;
        $retryable = $throwable instanceof OpenSearchBackendUnavailableException
            && $nextAttempt < self::MAX_ATTEMPTS;

        if (!$retryable) {
            return new IncrementalFailureDisposition(false, $errorCode, 0);
        }

        return new IncrementalFailureDisposition(true, $errorCode, $this->delay($nextAttempt));
    }

    private function delay(int $attempt): int
    {
        $delay = self::BASE_DELAY_SECONDS * (2 ** max(0, $attempt - 1));

        return min(self::MAX_DELAY_SECONDS, $delay);
    }
}
