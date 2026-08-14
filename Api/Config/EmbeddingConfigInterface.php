<?php

declare(strict_types=1);

namespace Aavirbhava\AiShoppingAssistant\Api\Config;

interface EmbeddingConfigInterface
{
    public function provider(): string;

    public function model(): string;

    public function baseUrl(): string;

    public function dimensions(): int;
}