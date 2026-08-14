<?php

declare(strict_types=1);

namespace Aavirbhava\AiShoppingAssistant\Model\Config;

use Aavirbhava\AiShoppingAssistant\Api\Config\FallbackConfigInterface;
use InvalidArgumentException;

final readonly class FallbackConfig implements FallbackConfigInterface
{
    public function __construct(
        private bool $enabled,
        private string $provider,
        private string $model,
        private string $baseUrl,
        private int $timeoutSeconds,
        private int $failureThreshold,
        private int $cooldownSeconds
    ) {
        if ($timeoutSeconds < 1) {
            throw new InvalidArgumentException('Fallback timeout must be greater than zero.');
        }

        if ($failureThreshold < 1) {
            throw new InvalidArgumentException('Fallback failure threshold must be greater than zero.');
        }

        if ($cooldownSeconds < 1) {
            throw new InvalidArgumentException('Fallback cooldown must be greater than zero.');
        }
    }

    public function isEnabled(): bool
    {
        return $this->enabled;
    }

    public function provider(): string
    {
        return $this->provider;
    }

    public function model(): string
    {
        return $this->model;
    }

    public function baseUrl(): string
    {
        return $this->baseUrl;
    }

    public function timeoutSeconds(): int
    {
        return $this->timeoutSeconds;
    }

    public function failureThreshold(): int
    {
        return $this->failureThreshold;
    }

    public function cooldownSeconds(): int
    {
        return $this->cooldownSeconds;
    }
}