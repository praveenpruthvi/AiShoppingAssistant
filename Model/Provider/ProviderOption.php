<?php

declare(strict_types=1);

namespace Aavirbhava\AiShoppingAssistant\Model\Provider;

/**
 * Immutable option entry for Admin dropdowns.
 *
 * Carries only an identifier and a trusted label. Never carries provider
 * instances, credentials, configuration values, or secrets.
 */
final readonly class ProviderOption
{
    public function __construct(
        private string $identifier,
        private string $label
    ) {
    }

    public function identifier(): string
    {
        return $this->identifier;
    }

    public function label(): string
    {
        return $this->label;
    }
}
