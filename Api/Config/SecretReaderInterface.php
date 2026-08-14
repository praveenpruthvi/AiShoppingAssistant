<?php

declare(strict_types=1);

namespace Aavirbhava\AiShoppingAssistant\Api\Config;

use Aavirbhava\AiShoppingAssistant\Model\Config\SecretValue;

interface SecretReaderInterface
{
    public function getPrimaryLlmApiKey(int $storeId): SecretValue;

    public function getFallbackLlmApiKey(int $storeId): SecretValue;

    public function getEmbeddingApiKey(int $storeId): SecretValue;
}