<?php

declare(strict_types=1);

namespace Aavirbhava\AiShoppingAssistant\Api\Config;

interface ConfigurationReaderInterface
{
    public function readGeneral(int $storeId): GeneralConfigInterface;

    public function readLlm(int $storeId): LlmConfigInterface;

    public function readFallback(int $storeId): FallbackConfigInterface;

    public function readEmbedding(int $storeId): EmbeddingConfigInterface;

    public function readRetrieval(int $storeId): RetrievalConfigInterface;

    public function readGuardrails(int $storeId): GuardrailConfigInterface;

    public function readIndexing(int $storeId): IndexingConfigInterface;
}
