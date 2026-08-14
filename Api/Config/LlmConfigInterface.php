<?php

declare(strict_types=1);

namespace Aavirbhava\AiShoppingAssistant\Api\Config;

interface LlmConfigInterface
{
    public function provider(): string;

    public function model(): string;

    public function baseUrl(): string;

    public function timeoutSeconds(): int;

    public function maxOutputTokens(): int;
}