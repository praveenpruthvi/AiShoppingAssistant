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

    /**
     * Default No: the product context sent to the LLM is exactly today's
     * full context, byte-for-byte unchanged. Yes opts into a SEPARATE,
     * growing set of cost-over-accuracy trimming techniques (currently:
     * dropping category names from ProductContextFormatter's output,
     * since the LLM response schema never requires them) — never the
     * default, since it can genuinely reduce recommendation quality for
     * category-sensitive queries. Distinct from provider-native prompt
     * caching, which is unconditional infrastructure with no quality
     * tradeoff and is never gated behind this toggle.
     */
    public function isTokenOptimizationEnabled(): bool;
}
