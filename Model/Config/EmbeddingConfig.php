<?php

declare(strict_types=1);

namespace Aavirbhava\AiShoppingAssistant\Model\Config;

use Aavirbhava\AiShoppingAssistant\Api\Config\EmbeddingConfigInterface;
use InvalidArgumentException;

final readonly class EmbeddingConfig implements EmbeddingConfigInterface
{
    public function __construct(
        private string $provider,
        private string $model,
        private string $baseUrl,
        private int $dimensions
    ) {
        if ($dimensions < 1) {
            throw new InvalidArgumentException('Embedding dimensions must be greater than zero.');
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

    public function dimensions(): int
    {
        return $this->dimensions;
    }
}