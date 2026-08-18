<?php

declare(strict_types=1);

namespace Aavirbhava\AiShoppingAssistant\Api\Config;

interface GeneralConfigInterface
{
    public function isEnabled(): bool;

    public function isStrictStoreOnly(): bool;

    /**
     * How many prior conversation messages (not "turns" — every persisted
     * user/assistant/tool message counts individually, since a single
     * customer-visible turn can produce several messages via the tool-call
     * round-trip) are retained and re-threaded into context for a
     * conversation. Bounds both DB storage per conversation and the token
     * cost of every subsequent call.
     */
    public function maxConversationMessages(): int;
}
