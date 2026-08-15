<?php

declare(strict_types=1);

namespace Aavirbhava\AiShoppingAssistant\Api\Config;

interface FallbackConfigInterface
{
    public function isEnabled(): bool;

    public function provider(): string;

    public function model(): string;

    public function baseUrl(): string;

    public function timeoutSeconds(): int;

    public function failureThreshold(): int;

    public function cooldownSeconds(): int;
}
