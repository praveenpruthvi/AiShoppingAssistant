<?php

declare(strict_types=1);

namespace Aavirbhava\AiShoppingAssistant\Model\Config;

use JsonSerializable;

/**
 * Immutable secret value for provider adapters.
 *
 * The secret is stored privately and can only be revealed through the explicit
 * reveal() method. It intentionally has no __toString() implementation so that
 * accidental string interpolation or exception formatting cannot leak it.
 */
final readonly class SecretValue implements JsonSerializable
{
    private const REDACTED = '********';

    public function __construct(
        private string $value
    ) {
    }

    public static function empty(): self
    {
        return new self('');
    }

    /**
     * Intended for provider adapters only.
     */
    public function reveal(): string
    {
        return $this->value;
    }

    public function isEmpty(): bool
    {
        return $this->value === '';
    }

    public function jsonSerialize(): string
    {
        return self::REDACTED;
    }

    public function __debugInfo(): array
    {
        return ['value' => self::REDACTED];
    }
}
