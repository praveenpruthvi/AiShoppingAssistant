<?php

declare(strict_types=1);

namespace Aavirbhava\AiShoppingAssistant\Model\Dto;

final readonly class ConnectionResult
{
    private function __construct(
        public bool $successful,
        public string $message,
        public ?string $sanitizedErrorCode
    ) {
    }

    public static function success(string $message = 'Connection successful.'): self
    {
        return new self(true, $message, null);
    }

    public static function failure(string $message, string $sanitizedErrorCode): self
    {
        return new self(false, $message, $sanitizedErrorCode);
    }
}
