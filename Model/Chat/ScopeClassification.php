<?php

declare(strict_types=1);

namespace Aavirbhava\AiShoppingAssistant\Model\Chat;

/**
 * The Commerce Scope Classifier's decision for one message.
 *
 * Immutable, two-outcome value object (mirrors ConnectionResult): in-scope
 * carries no reason code, out-of-scope always carries a stable reason code
 * so it can be surfaced later in the Admin Playground debug panel.
 */
final readonly class ScopeClassification
{
    private function __construct(
        private bool $inScope,
        private ?string $reasonCode
    ) {
    }

    public static function inScope(): self
    {
        return new self(true, null);
    }

    public static function outOfScope(string $reasonCode): self
    {
        return new self(false, $reasonCode);
    }

    public function isInScope(): bool
    {
        return $this->inScope;
    }

    /**
     * Null when in scope; a stable reason code otherwise.
     */
    public function reasonCode(): ?string
    {
        return $this->reasonCode;
    }
}
