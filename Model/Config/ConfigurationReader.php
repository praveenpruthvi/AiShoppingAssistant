<?php

declare(strict_types=1);

namespace Aavirbhava\AiShoppingAssistant\Model\Config;

use Aavirbhava\AiShoppingAssistant\Api\Config\ConfigurationReaderInterface;
use Aavirbhava\AiShoppingAssistant\Api\Config\EmbeddingConfigInterface;
use Aavirbhava\AiShoppingAssistant\Api\Config\FallbackConfigInterface;
use Aavirbhava\AiShoppingAssistant\Api\Config\GeneralConfigInterface;
use Aavirbhava\AiShoppingAssistant\Api\Config\GuardrailConfigInterface;
use Aavirbhava\AiShoppingAssistant\Api\Config\LlmConfigInterface;
use Aavirbhava\AiShoppingAssistant\Api\Config\RetrievalConfigInterface;
use Aavirbhava\AiShoppingAssistant\Model\Config\Exception\ConfigurationException;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\Phrase;
use Magento\Store\Model\ScopeInterface;

final class ConfigurationReader implements ConfigurationReaderInterface
{
    public const MIN_TIMEOUT_SECONDS = 1;
    public const MAX_TIMEOUT_SECONDS = 300;
    public const DEFAULT_TIMEOUT_SECONDS = 20;

    public const MIN_MAX_OUTPUT_TOKENS = 1;
    public const MAX_MAX_OUTPUT_TOKENS = 32768;
    public const DEFAULT_MAX_OUTPUT_TOKENS = 1200;

    public const MIN_FALLBACK_TIMEOUT_SECONDS = 1;
    public const MAX_FALLBACK_TIMEOUT_SECONDS = 300;
    public const DEFAULT_FALLBACK_TIMEOUT_SECONDS = 30;

    public const MIN_FAILURE_THRESHOLD = 1;
    public const MAX_FAILURE_THRESHOLD = 50;
    public const DEFAULT_FAILURE_THRESHOLD = 3;

    public const MIN_COOLDOWN_SECONDS = 1;
    public const MAX_COOLDOWN_SECONDS = 86400;
    public const DEFAULT_COOLDOWN_SECONDS = 60;

    public const MIN_EMBEDDING_DIMENSIONS = 1;
    public const MAX_EMBEDDING_DIMENSIONS = 16384;
    public const DEFAULT_EMBEDDING_DIMENSIONS = 1024;

    public const MIN_KEYWORD_CANDIDATES = 1;
    public const MAX_KEYWORD_CANDIDATES = 500;
    public const DEFAULT_KEYWORD_CANDIDATES = 50;

    public const MIN_VECTOR_CANDIDATES = 1;
    public const MAX_VECTOR_CANDIDATES = 500;
    public const DEFAULT_VECTOR_CANDIDATES = 50;

    public const MIN_MERGED_CANDIDATES = 1;
    public const MAX_MERGED_CANDIDATES = 200;
    public const DEFAULT_MERGED_CANDIDATES = 30;

    public const MIN_FINAL_PRODUCTS = 1;
    public const MAX_FINAL_PRODUCTS = 50;
    public const DEFAULT_FINAL_PRODUCTS = 8;

    public const MIN_MAX_INPUT_CHARACTERS = 1;
    public const MAX_MAX_INPUT_CHARACTERS = 100000;
    public const DEFAULT_MAX_INPUT_CHARACTERS = 1000;

    public const MIN_MAX_TOOL_CALLS = 1;
    public const MAX_MAX_TOOL_CALLS = 100;
    public const DEFAULT_MAX_TOOL_CALLS = 4;

    public const DEFAULT_OUT_OF_SCOPE_MESSAGE = 'I can help you search, compare, and learn about products and services available on this store. What are you looking for?';

    public function __construct(
        private readonly ScopeConfigInterface $scopeConfig
    ) {
    }

    public function readGeneral(int $storeId): GeneralConfigInterface
    {
        return new GeneralConfig(
            $this->readBool(Path::GENERAL_ENABLED, $storeId, false),
            $this->readBool(Path::GENERAL_STRICT_STORE_ONLY, $storeId, true)
        );
    }

    public function readLlm(int $storeId): LlmConfigInterface
    {
        return new LlmConfig(
            $this->readRequiredString(Path::LLM_PROVIDER, $storeId, 'LLM provider'),
            $this->readRequiredString(Path::LLM_MODEL, $storeId, 'LLM model'),
            $this->readString(Path::LLM_BASE_URL, $storeId),
            $this->readInt(
                Path::LLM_TIMEOUT_SECONDS,
                $storeId,
                self::MIN_TIMEOUT_SECONDS,
                self::MAX_TIMEOUT_SECONDS,
                self::DEFAULT_TIMEOUT_SECONDS
            ),
            $this->readInt(
                Path::LLM_MAX_OUTPUT_TOKENS,
                $storeId,
                self::MIN_MAX_OUTPUT_TOKENS,
                self::MAX_MAX_OUTPUT_TOKENS,
                self::DEFAULT_MAX_OUTPUT_TOKENS
            )
        );
    }

    public function readFallback(int $storeId): FallbackConfigInterface
    {
        $enabled = $this->readBool(Path::FALLBACK_ENABLED, $storeId, false);

        return new FallbackConfig(
            $enabled,
            $enabled
                ? $this->readRequiredString(Path::FALLBACK_PROVIDER, $storeId, 'fallback provider')
                : $this->readString(Path::FALLBACK_PROVIDER, $storeId),
            $enabled
                ? $this->readRequiredString(Path::FALLBACK_MODEL, $storeId, 'fallback model')
                : $this->readString(Path::FALLBACK_MODEL, $storeId),
            $this->readString(Path::FALLBACK_BASE_URL, $storeId),
            $this->readInt(
                Path::FALLBACK_TIMEOUT_SECONDS,
                $storeId,
                self::MIN_FALLBACK_TIMEOUT_SECONDS,
                self::MAX_FALLBACK_TIMEOUT_SECONDS,
                self::DEFAULT_FALLBACK_TIMEOUT_SECONDS
            ),
            $this->readInt(
                Path::FALLBACK_FAILURE_THRESHOLD,
                $storeId,
                self::MIN_FAILURE_THRESHOLD,
                self::MAX_FAILURE_THRESHOLD,
                self::DEFAULT_FAILURE_THRESHOLD
            ),
            $this->readInt(
                Path::FALLBACK_COOLDOWN_SECONDS,
                $storeId,
                self::MIN_COOLDOWN_SECONDS,
                self::MAX_COOLDOWN_SECONDS,
                self::DEFAULT_COOLDOWN_SECONDS
            )
        );
    }

    public function readEmbedding(int $storeId): EmbeddingConfigInterface
    {
        return new EmbeddingConfig(
            $this->readRequiredString(Path::EMBEDDING_PROVIDER, $storeId, 'embedding provider'),
            $this->readRequiredString(Path::EMBEDDING_MODEL, $storeId, 'embedding model'),
            $this->readString(Path::EMBEDDING_BASE_URL, $storeId),
            $this->readInt(
                Path::EMBEDDING_DIMENSIONS,
                $storeId,
                self::MIN_EMBEDDING_DIMENSIONS,
                self::MAX_EMBEDDING_DIMENSIONS,
                self::DEFAULT_EMBEDDING_DIMENSIONS
            )
        );
    }

    public function readRetrieval(int $storeId): RetrievalConfigInterface
    {
        return new RetrievalConfig(
            $this->readInt(
                Path::RETRIEVAL_KEYWORD_CANDIDATES,
                $storeId,
                self::MIN_KEYWORD_CANDIDATES,
                self::MAX_KEYWORD_CANDIDATES,
                self::DEFAULT_KEYWORD_CANDIDATES
            ),
            $this->readInt(
                Path::RETRIEVAL_VECTOR_CANDIDATES,
                $storeId,
                self::MIN_VECTOR_CANDIDATES,
                self::MAX_VECTOR_CANDIDATES,
                self::DEFAULT_VECTOR_CANDIDATES
            ),
            $this->readInt(
                Path::RETRIEVAL_MERGED_CANDIDATES,
                $storeId,
                self::MIN_MERGED_CANDIDATES,
                self::MAX_MERGED_CANDIDATES,
                self::DEFAULT_MERGED_CANDIDATES
            ),
            $this->readInt(
                Path::RETRIEVAL_FINAL_PRODUCTS,
                $storeId,
                self::MIN_FINAL_PRODUCTS,
                self::MAX_FINAL_PRODUCTS,
                self::DEFAULT_FINAL_PRODUCTS
            ),
            $this->readBool(Path::RETRIEVAL_RERANKER_ENABLED, $storeId, false)
        );
    }

    public function readGuardrails(int $storeId): GuardrailConfigInterface
    {
        return new GuardrailConfig(
            $this->readInt(
                Path::GUARDRAILS_MAX_INPUT_CHARACTERS,
                $storeId,
                self::MIN_MAX_INPUT_CHARACTERS,
                self::MAX_MAX_INPUT_CHARACTERS,
                self::DEFAULT_MAX_INPUT_CHARACTERS
            ),
            $this->readInt(
                Path::GUARDRAILS_MAX_TOOL_CALLS,
                $storeId,
                self::MIN_MAX_TOOL_CALLS,
                self::MAX_MAX_TOOL_CALLS,
                self::DEFAULT_MAX_TOOL_CALLS
            ),
            $this->readBool(Path::GUARDRAILS_CART_MUTATIONS_ENABLED, $storeId, false),
            $this->readBool(Path::GUARDRAILS_BLOCK_EXTERNAL_URLS, $storeId, true),
            $this->readBool(Path::GUARDRAILS_BLOCK_CODE_GENERATION, $storeId, true),
            $this->readString(Path::GUARDRAILS_OUT_OF_SCOPE_MESSAGE, $storeId, self::DEFAULT_OUT_OF_SCOPE_MESSAGE)
        );
    }

    private function readBool(string $path, int $storeId, bool $failClosed): bool
    {
        $value = $this->readRaw($path, $storeId);

        if ($value === null || $value === '') {
            return $failClosed;
        }

        if (is_bool($value)) {
            return $value;
        }

        return in_array(strtolower(trim((string) $value)), ['1', 'true', 'yes', 'on'], true);
    }

    private function readInt(string $path, int $storeId, int $min, int $max, int $default): int
    {
        $value = $this->readRaw($path, $storeId);

        if ($value === null) {
            return $default;
        }

        $normalized = trim((string) $value);

        if ($normalized === '' || preg_match('/^\d+$/', $normalized) !== 1) {
            return $default;
        }

        $parsed = (int) $normalized;

        if ($parsed < $min) {
            return $min;
        }

        if ($parsed > $max) {
            return $max;
        }

        return $parsed;
    }

    private function readString(string $path, int $storeId, string $default = ''): string
    {
        $value = $this->readRaw($path, $storeId);

        if ($value === null) {
            return $default;
        }

        $trimmed = trim((string) $value);

        return $trimmed === '' ? $default : $trimmed;
    }

    private function readRequiredString(string $path, int $storeId, string $label): string
    {
        $value = $this->readString($path, $storeId);

        if ($value === '') {
            throw new ConfigurationException(
                new Phrase(
                    'The AI Shopping Assistant configuration is incomplete: %1 is not configured for store %2.',
                    [$label, (string) $storeId]
                )
            );
        }

        return $value;
    }

    private function readRaw(string $path, int $storeId): mixed
    {
        return $this->scopeConfig->getValue($path, ScopeInterface::SCOPE_STORE, (string) $storeId);
    }
}