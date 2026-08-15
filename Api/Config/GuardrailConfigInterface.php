<?php

declare(strict_types=1);

namespace Aavirbhava\AiShoppingAssistant\Api\Config;

interface GuardrailConfigInterface
{
    public function maxInputCharacters(): int;

    public function maxToolCalls(): int;

    public function areCartMutationsEnabled(): bool;

    public function blocksExternalUrls(): bool;

    public function blocksCodeGeneration(): bool;

    public function outOfScopeMessage(): string;
}
