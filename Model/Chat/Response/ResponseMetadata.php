<?php

declare(strict_types=1);

namespace Aavirbhava\AiShoppingAssistant\Model\Chat\Response;

use InvalidArgumentException;

/**
 * Non-customer-facing diagnostic metadata: which provider/model produced
 * the response and whether fallback was used. Populated from
 * ChatResponse::usedFallback by OutputValidator — true whenever
 * FallbackChatGenerationService actually served the response from the
 * configured fallback provider instead of the primary one.
 */
final readonly class ResponseMetadata
{
    public function __construct(
        public string $provider,
        public string $model,
        public bool $fallbackUsed
    ) {
        if ($provider === '') {
            throw new InvalidArgumentException('Response metadata requires a non-empty provider.');
        }

        if ($model === '') {
            throw new InvalidArgumentException('Response metadata requires a non-empty model.');
        }
    }
}
