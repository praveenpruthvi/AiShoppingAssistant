<?php

declare(strict_types=1);

namespace Aavirbhava\AiShoppingAssistant\Api\Chat;

use Aavirbhava\AiShoppingAssistant\Model\Chat\ScopeClassification;

/**
 * Deterministic, non-AI first pass on an incoming customer message.
 *
 * Implementations must never call an LLM provider: every request, including
 * malicious or out-of-scope ones, would otherwise burn a real provider call
 * before any trust decision is made. This is a cheap gate, not a precise
 * intent classifier — the LLM's own store-only system prompt and the future
 * Output Validator remain the deeper enforcement layers.
 */
interface CommerceScopeClassifierInterface
{
    public function classify(int $storeId, string $message): ScopeClassification;
}
