<?php

declare(strict_types=1);

namespace Aavirbhava\AiShoppingAssistant\Model\Chat;

use InvalidArgumentException;

/**
 * A fixed, non-AI response returned when the pipeline short-circuits before
 * any provider call: out-of-scope messages, or the assistant disabled for
 * the store. Deliberately minimal — this is not the structured response
 * contract (products, follow-up questions, actions, metadata), which is a
 * later task.
 */
final readonly class SafeResponse
{
    public function __construct(
        public string $message,
        public string $reasonCode
    ) {
        if ($message === '') {
            throw new InvalidArgumentException('A safe response requires a non-empty message.');
        }

        if ($reasonCode === '') {
            throw new InvalidArgumentException('A safe response requires a reason code.');
        }
    }
}
