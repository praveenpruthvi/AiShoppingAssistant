<?php

declare(strict_types=1);

namespace Aavirbhava\AiShoppingAssistant\Model\Config;

use Aavirbhava\AiShoppingAssistant\Api\Config\GuardrailConfigInterface;
use InvalidArgumentException;

final readonly class GuardrailConfig implements GuardrailConfigInterface
{
    public function __construct(
        private int $maxInputCharacters,
        private int $maxToolCalls,
        private bool $cartMutationsEnabled,
        private bool $blockExternalUrls,
        private bool $blockCodeGeneration,
        private string $outOfScopeMessage
    ) {
        if ($maxInputCharacters < 1) {
            throw new InvalidArgumentException('Maximum input characters must be greater than zero.');
        }

        if ($maxToolCalls < 1) {
            throw new InvalidArgumentException('Maximum tool calls must be greater than zero.');
        }
    }

    public function maxInputCharacters(): int
    {
        return $this->maxInputCharacters;
    }

    public function maxToolCalls(): int
    {
        return $this->maxToolCalls;
    }

    public function areCartMutationsEnabled(): bool
    {
        return $this->cartMutationsEnabled;
    }

    public function blocksExternalUrls(): bool
    {
        return $this->blockExternalUrls;
    }

    public function blocksCodeGeneration(): bool
    {
        return $this->blockCodeGeneration;
    }

    public function outOfScopeMessage(): string
    {
        return $this->outOfScopeMessage;
    }
}
