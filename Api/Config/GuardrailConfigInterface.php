<?php

declare(strict_types=1);

namespace Aavirbhava\AiShoppingAssistant\Api\Config;

interface GuardrailConfigInterface
{
    public function maxInputCharacters(): int;

    public function maxToolCalls(): int;

    public function areCartMutationsEnabled(): bool;

    /**
     * Whether add_to_cart/remove_from_cart must return a
     * confirmation_required result on their first call for a given
     * proposed change, rather than executing immediately.
     */
    public function requiresCartConfirmation(): bool;

    public function blocksExternalUrls(): bool;

    public function blocksCodeGeneration(): bool;

    public function outOfScopeMessage(): string;

    /**
     * Shown for a provider failure expected to be momentary — the customer
     * can reasonably keep chatting after seeing it.
     */
    public function assistantUnavailableMessage(): string;

    /**
     * Shown for a provider failure that will keep recurring identically
     * (an exhausted quota, an invalid/revoked API key) — after this
     * message, the storefront chat stops accepting further input for the
     * rest of the visit rather than inviting a customer to keep typing
     * into a conversation that cannot proceed.
     */
    public function assistantDownMessage(): string;
}
