<?php

declare(strict_types=1);

namespace Aavirbhava\AiShoppingAssistant\Model\Config;

use Aavirbhava\AiShoppingAssistant\Api\Config\LlmConfigInterface;
use InvalidArgumentException;

final readonly class LlmConfig implements LlmConfigInterface
{
    public function __construct(
        private string $provider,
        private string $model,
        private string $baseUrl,
        private int $timeoutSeconds,
        private int $maxOutputTokens
    ) {
        if ($timeoutSeconds < 1) {
            throw new InvalidArgumentException('LLM timeout must be greater than zero.');
        }

        if ($maxOutputTokens < 1) {
            throw new InvalidArgumentException('Maximum output tokens must be greater than zero.');
        }
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

    public function maxOutputTokens(): int
    {
        return $this->maxOutputTokens;
    }
}