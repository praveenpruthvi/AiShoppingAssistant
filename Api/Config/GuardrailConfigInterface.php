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
}
