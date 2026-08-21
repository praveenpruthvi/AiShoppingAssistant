<?php

declare(strict_types=1);

namespace Aavirbhava\AiShoppingAssistant\Model\Config;

use Aavirbhava\AiShoppingAssistant\Api\Catalog\ProductAttributePolicyInterface;
use Aavirbhava\AiShoppingAssistant\Api\Config\AppearanceConfigInterface;
use Aavirbhava\AiShoppingAssistant\Api\Config\CapabilitiesConfigInterface;
use Aavirbhava\AiShoppingAssistant\Api\Config\ConfigurationReaderInterface;
use Aavirbhava\AiShoppingAssistant\Api\Config\CostCapConfigInterface;
use Aavirbhava\AiShoppingAssistant\Api\Config\EmbeddingConfigInterface;
use Aavirbhava\AiShoppingAssistant\Api\Config\FallbackConfigInterface;
use Aavirbhava\AiShoppingAssistant\Api\Config\GeneralConfigInterface;
use Aavirbhava\AiShoppingAssistant\Api\Config\GuardrailConfigInterface;
use Aavirbhava\AiShoppingAssistant\Api\Config\IndexingConfigInterface;
use Aavirbhava\AiShoppingAssistant\Api\Config\LlmConfigInterface;
use Aavirbhava\AiShoppingAssistant\Api\Config\ProviderCostConfigInterface;
use Aavirbhava\AiShoppingAssistant\Api\Config\RetrievalConfigInterface;
use Aavirbhava\AiShoppingAssistant\Model\Config\Exception\ConfigurationException;
use Aavirbhava\AiShoppingAssistant\Model\Config\Source\CapPeriod;
use Aavirbhava\AiShoppingAssistant\Model\Provider\ProviderIdentifiers;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\Phrase;
use Magento\Store\Model\ScopeInterface;

final class ConfigurationReader implements ConfigurationReaderInterface
{
    public const MIN_TIMEOUT_SECONDS = 1;
    public const MAX_TIMEOUT_SECONDS = 300;
    public const DEFAULT_TIMEOUT_SECONDS = 20;

    public const MIN_MAX_OUTPUT_TOKENS = 1;
    public const MAX_MAX_OUTPUT_TOKENS = 8192;
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
    public const MAX_FINAL_PRODUCTS = 20;
    public const DEFAULT_FINAL_PRODUCTS = 8;

    /**
     * How much RatingSignal's Bayesian-weighted rating score contributes to
     * a candidate's running rank score, out of the roughly 0-1-per-signal
     * range TextRelevanceSignal/VectorSimilaritySignal/AttributeMatchSignal
     * already contribute. Kept deliberately conservative by default — 0.1
     * is a nudge relative to the ~1.0 a strong text/vector match already
     * contributes, not a dominant factor: a well-matching low-rated product
     * should still generally outrank a well-rated but irrelevant one.
     * Bounded at 1.0 so even a merchant who wants ratings to matter a lot
     * cannot make this signal alone outweigh every other signal combined.
     */
    public const MIN_RATING_SIGNAL_WEIGHT = 0.0;
    public const MAX_RATING_SIGNAL_WEIGHT = 1.0;
    public const DEFAULT_RATING_SIGNAL_WEIGHT = 0.1;

    public const MIN_MAX_INPUT_CHARACTERS = 1;
    public const MAX_MAX_INPUT_CHARACTERS = 10000;
    public const DEFAULT_MAX_INPUT_CHARACTERS = 1000;

    public const MIN_MAX_TOOL_CALLS = 1;
    public const MAX_MAX_TOOL_CALLS = 10;

    /**
     * Tried raising this from 4 to 6 during Task 23, reasoning that a
     * wasted round (e.g. a hallucinated call to a nonexistent tool) left
     * too little slack before the model gets force-answered with no
     * tools offered. Reverted after the broad live-query test that same
     * task ran: each `converse()` attempt already costs up to
     * maxToolCalls+1 real provider calls, and ChatEntryPipeline's own
     * retry budget (MAX_STRUCTURED_OUTPUT_ATTEMPTS) can invoke
     * `converse()` twice — six rounds pushed the theoretical worst case
     * to 14 real calls (~280s at the 20s default LLM timeout), and this
     * environment's nginx has a default ~60s fastcgi_read_timeout with no
     * override found — a real, hittable ceiling, not a theoretical one.
     * The test data itself didn't show extra rounds converting genuinely
     * ambiguous queries into successes, just making them take longer
     * before still needing to give up. Kept at 4 (the original, already-
     * proven default) — the prompt fix warning against the hallucinated
     * "product_skus" tool call and the retry-on-invalid-response recovery
     * (both also Task 23) address the same underlying failure without
     * raising the worst-case latency ceiling at all.
     */
    public const DEFAULT_MAX_TOOL_CALLS = 4;

    public const MIN_MAX_CONVERSATION_MESSAGES = 2;
    public const MAX_MAX_CONVERSATION_MESSAGES = 200;
    public const DEFAULT_MAX_CONVERSATION_MESSAGES = 40;

    public const DEFAULT_OUT_OF_SCOPE_MESSAGE = 'I can help you search, compare, and learn about products and services available on this store. What are you looking for?';

    public const MIN_BATCH_SIZE = 10;
    public const MAX_BATCH_SIZE = 500;
    public const DEFAULT_BATCH_SIZE = 100;

    public const MAX_SEARCHABLE_ATTRIBUTE_CODES = 50;

    public const MIN_MAX_ATTRIBUTE_VALUES = 1;
    public const MAX_MAX_ATTRIBUTE_VALUES = 500;
    public const DEFAULT_MAX_ATTRIBUTE_VALUES = 100;

    public const DEFAULT_INDEX_PREFIX = 'aavirbhava_ai_product_rag';

    /**
     * @var list<string>
     */
    public const DEFAULT_SEARCHABLE_ATTRIBUTE_CODES = ['manufacturer', 'color', 'size', 'material'];

    public const MIN_COST_CAP_AMOUNT = 0.0;
    public const MAX_COST_CAP_AMOUNT = 1000000.0;
    public const DEFAULT_COST_CAP_AMOUNT = 0.0;

    public const MIN_WARNING_THRESHOLD_PERCENT = 1;
    public const MAX_WARNING_THRESHOLD_PERCENT = 99;
    public const DEFAULT_WARNING_THRESHOLD_PERCENT = 80;

    public const DEFAULT_COST_CAP_PERIOD = CapPeriod::DAILY;

    public const MIN_PROVIDER_PRICE_PER_1K_TOKENS = 0.0;
    public const MAX_PROVIDER_PRICE_PER_1K_TOKENS = 1000.0;
    public const DEFAULT_PROVIDER_PRICE_PER_1K_TOKENS = 0.0;

    public const DEFAULT_PRIMARY_COLOR = '#1979c3';
    public const DEFAULT_MESSAGE_BUBBLE_COLOR = '#f2f2f2';
    public const DEFAULT_MESSAGE_TEXT_COLOR = '#222222';

    public function __construct(
        private readonly ScopeConfigInterface $scopeConfig,
        private readonly ProductAttributePolicyInterface $attributePolicy,
        private readonly ColorContrast $colorContrast
    ) {
    }

    public function readGeneral(int $storeId): GeneralConfigInterface
    {
        return new GeneralConfig(
            $this->readBool(Path::GENERAL_ENABLED, $storeId, false),
            $this->readBool(Path::GENERAL_STRICT_STORE_ONLY, $storeId, true),
            $this->readInt(
                Path::GENERAL_MAX_CONVERSATION_MESSAGES,
                $storeId,
                self::MIN_MAX_CONVERSATION_MESSAGES,
                self::MAX_MAX_CONVERSATION_MESSAGES,
                self::DEFAULT_MAX_CONVERSATION_MESSAGES
            )
        );
    }

    /**
     * The primary color always resolves independently (it has no paired
     * field to auto-contrast against) — but its TEXT color is always
     * computed against whatever primaryColor resolves to, default or
     * explicit, so a light custom primaryColor never silently pairs with
     * unreadable white header text.
     *
     * The bubble background/text pair is resolved together: if only one
     * of the two is explicitly set, the other is auto-computed to stay
     * readable against it (see ColorContrast) rather than falling back to
     * a fixed default that might clash with the one that was set. If
     * neither is set, both fall back to this module's original defaults.
     * If both are set, both are used exactly as configured — manual
     * values always win, even if the merchant's own pair would read
     * poorly together; that is their explicit choice to make, not this
     * reader's to override.
     */
    public function readAppearance(int $storeId): AppearanceConfigInterface
    {
        $primaryColor = $this->readColor(Path::APPEARANCE_PRIMARY_COLOR, $storeId) ?? self::DEFAULT_PRIMARY_COLOR;

        $bubbleColorSet = $this->readColor(Path::APPEARANCE_MESSAGE_BUBBLE_COLOR, $storeId);
        $textColorSet = $this->readColor(Path::APPEARANCE_MESSAGE_TEXT_COLOR, $storeId);

        if ($bubbleColorSet !== null) {
            $bubbleColor = $bubbleColorSet;
            $textColor = $textColorSet ?? $this->colorContrast->readableTextFor($bubbleColorSet);
        } elseif ($textColorSet !== null) {
            $textColor = $textColorSet;
            $bubbleColor = $this->colorContrast->readableBackgroundFor($textColorSet);
        } else {
            $bubbleColor = self::DEFAULT_MESSAGE_BUBBLE_COLOR;
            $textColor = self::DEFAULT_MESSAGE_TEXT_COLOR;
        }

        return new AppearanceConfig(
            $primaryColor,
            $this->colorContrast->readableTextFor($primaryColor),
            $bubbleColor,
            $textColor
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
            $this->readBool(Path::RETRIEVAL_RERANKER_ENABLED, $storeId, false),
            $this->readFloat(
                Path::RETRIEVAL_RATING_SIGNAL_WEIGHT,
                $storeId,
                self::MIN_RATING_SIGNAL_WEIGHT,
                self::MAX_RATING_SIGNAL_WEIGHT,
                self::DEFAULT_RATING_SIGNAL_WEIGHT
            )
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
            $this->readBool(Path::GUARDRAILS_REQUIRE_CART_CONFIRMATION, $storeId, true),
            $this->readBool(Path::GUARDRAILS_BLOCK_EXTERNAL_URLS, $storeId, true),
            $this->readBool(Path::GUARDRAILS_BLOCK_CODE_GENERATION, $storeId, true),
            $this->readString(Path::GUARDRAILS_OUT_OF_SCOPE_MESSAGE, $storeId, self::DEFAULT_OUT_OF_SCOPE_MESSAGE)
        );
    }

    public function readCapabilities(int $storeId): CapabilitiesConfigInterface
    {
        return new CapabilitiesConfig(
            $this->readBool(Path::CAPABILITIES_PRODUCT_DISCOVERY_ENABLED, $storeId, true),
            $this->readBool(Path::CAPABILITIES_PRODUCT_DETAILS_ENABLED, $storeId, true),
            $this->readBool(Path::CAPABILITIES_COMPARISON_ENABLED, $storeId, true),
            $this->readBool(Path::CAPABILITIES_PRICE_CHECKING_ENABLED, $storeId, true),
            $this->readBool(Path::CAPABILITIES_STOCK_CHECKING_ENABLED, $storeId, true),
            $this->readBool(Path::CAPABILITIES_POLICY_SEARCH_ENABLED, $storeId, true),
            $this->readBool(Path::CAPABILITIES_PROMOTION_AWARENESS_ENABLED, $storeId, true)
        );
    }

    public function readCostCap(int $storeId): CostCapConfigInterface
    {
        return new CostCapConfig(
            $this->readFloat(
                Path::COST_CAP_AMOUNT,
                $storeId,
                self::MIN_COST_CAP_AMOUNT,
                self::MAX_COST_CAP_AMOUNT,
                self::DEFAULT_COST_CAP_AMOUNT
            ),
            $this->readCapPeriod($storeId),
            $this->readInt(
                Path::COST_CAP_WARNING_THRESHOLD_PERCENT,
                $storeId,
                self::MIN_WARNING_THRESHOLD_PERCENT,
                self::MAX_WARNING_THRESHOLD_PERCENT,
                self::DEFAULT_WARNING_THRESHOLD_PERCENT
            ),
            $this->readBool(Path::COST_CAP_ALLOW_OVERRIDE, $storeId, false),
            $this->readEmailList(Path::COST_CAP_NOTIFICATION_EMAILS, $storeId)
        );
    }

    public function readProviderCost(int $storeId): ProviderCostConfigInterface
    {
        return new ProviderCostConfig([
            ProviderIdentifiers::LLM_OPENAI => [
                'input' => $this->readProviderPrice(Path::PROVIDER_COST_OPENAI_PRICE_PER_1K_INPUT_TOKENS, $storeId),
                'output' => $this->readProviderPrice(Path::PROVIDER_COST_OPENAI_PRICE_PER_1K_OUTPUT_TOKENS, $storeId),
            ],
            ProviderIdentifiers::LLM_OPENAI_COMPATIBLE => [
                'input' => $this->readProviderPrice(Path::PROVIDER_COST_OPENAI_COMPATIBLE_PRICE_PER_1K_INPUT_TOKENS, $storeId),
                'output' => $this->readProviderPrice(Path::PROVIDER_COST_OPENAI_COMPATIBLE_PRICE_PER_1K_OUTPUT_TOKENS, $storeId),
            ],
        ]);
    }

    public function readIndexing(int $storeId): IndexingConfigInterface
    {
        $codes = $this->readAttributeCodeList(
            Path::INDEXING_SEARCHABLE_ATTRIBUTE_CODES,
            $storeId,
            self::DEFAULT_SEARCHABLE_ATTRIBUTE_CODES
        );

        $aggregate = $this->readBool(Path::INDEXING_AGGREGATE_CONFIGURABLE_VARIANTS, $storeId, false);

        if ($aggregate) {
            throw new ConfigurationException(
                new Phrase('Configurable variant aggregation is not available in this version. Disable "aggregate configurable variants".')
            );
        }

        return new IndexingConfig(
            $this->readInt(
                Path::INDEXING_BATCH_SIZE,
                $storeId,
                self::MIN_BATCH_SIZE,
                self::MAX_BATCH_SIZE,
                self::DEFAULT_BATCH_SIZE
            ),
            $codes,
            $this->readBool(Path::INDEXING_INCLUDE_SHORT_DESCRIPTION, $storeId, true),
            $this->readBool(Path::INDEXING_INCLUDE_LONG_DESCRIPTION, $storeId, true),
            false,
            $this->readInt(
                Path::INDEXING_MAX_ATTRIBUTE_VALUES_PER_PRODUCT,
                $storeId,
                self::MIN_MAX_ATTRIBUTE_VALUES,
                self::MAX_MAX_ATTRIBUTE_VALUES,
                self::DEFAULT_MAX_ATTRIBUTE_VALUES
            ),
            $this->readString(Path::INDEXING_INDEX_PREFIX, $storeId, self::DEFAULT_INDEX_PREFIX)
        );
    }

    /**
     * Reads the explicit searchable attribute allowlist and validates it fail closed.
     *
     * - An empty or null value resolves to the default list.
     * - A raw empty string (explicit blank) resolves to an empty explicit allowlist.
     * - Invalid tokens (bad attribute-code format) throw a sanitized ConfigurationException.
     * - Policy-denied codes are silently dropped (fail closed) before sorting and slicing.
     *
     * @param list<string> $defaults
     *
     * @return list<string>
     */
    private function readAttributeCodeList(string $path, int $storeId, array $defaults): array
    {
        $raw = $this->scopeConfig->getValue($path, ScopeInterface::SCOPE_STORE, (string) $storeId);

        if ($raw === null) {
            return $defaults;
        }

        $rawString = trim((string) $raw);

        if ($rawString === '') {
            return [];
        }

        $codes = [];
        foreach (explode(',', $rawString) as $token) {
            $code = strtolower(trim($token));
            if ($code === '') {
                continue;
            }
            if (preg_match('/^[a-z][a-z0-9_]{0,63}$/', $code) !== 1) {
                throw new ConfigurationException(
                    new Phrase('The searchable attribute list contains an invalid attribute code.')
                );
            }
            $codes[] = $code;
        }

        $allowed = [];
        foreach ($codes as $code) {
            if ($this->attributePolicy->isAllowed($code)) {
                $allowed[] = $code;
            }
        }

        $allowed = array_values(array_unique($allowed));
        sort($allowed);

        return array_slice($allowed, 0, self::MAX_SEARCHABLE_ATTRIBUTE_CODES);
    }

    private function readProviderPrice(string $path, int $storeId): float
    {
        return $this->readFloat(
            $path,
            $storeId,
            self::MIN_PROVIDER_PRICE_PER_1K_TOKENS,
            self::MAX_PROVIDER_PRICE_PER_1K_TOKENS,
            self::DEFAULT_PROVIDER_PRICE_PER_1K_TOKENS
        );
    }

    /**
     * An unrecognized/blank stored value fails closed to the daily period
     * rather than throwing — the cap feature degrading to its narrowest,
     * safest period is preferable to a broken store-wide config read.
     */
    private function readCapPeriod(int $storeId): string
    {
        $value = $this->readString(Path::COST_CAP_PERIOD, $storeId, self::DEFAULT_COST_CAP_PERIOD);

        return in_array($value, [CapPeriod::DAILY, CapPeriod::WEEKLY, CapPeriod::MONTHLY], true)
            ? $value
            : self::DEFAULT_COST_CAP_PERIOD;
    }

    /**
     * Comma-separated list of notification email addresses, following the
     * same normalize-and-validate convention as readAttributeCodeList()
     * (trim each token, drop blanks) but for email syntax instead of
     * attribute-code syntax. A structurally invalid address is dropped
     * rather than failing the whole config read — one merchant typo
     * should not also silence every other correctly-entered address.
     *
     * @return list<string>
     */
    private function readEmailList(string $path, int $storeId): array
    {
        $raw = $this->readString($path, $storeId);

        if ($raw === '') {
            return [];
        }

        $emails = [];
        foreach (explode(',', $raw) as $token) {
            $email = trim($token);
            if ($email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL) !== false) {
                $emails[] = $email;
            }
        }

        return array_values(array_unique($emails));
    }

    /**
     * A merchant-entered color is never trusted as trailing CSS to be
     * emitted verbatim — only a strict `#rgb`/`#rrggbb` hex literal is
     * accepted; anything else (blank, malformed, or an attempted CSS
     * injection like `red; } body { display: none` typed into the admin
     * field) is dropped and the widget falls back to its hard-coded
     * default color, the same fail-safe behavior as an unset field.
     */
    private function readColor(string $path, int $storeId): ?string
    {
        $value = $this->readRaw($path, $storeId);

        if ($value === null) {
            return null;
        }

        $trimmed = trim((string) $value);

        return preg_match('/^#(?:[0-9a-fA-F]{3}|[0-9a-fA-F]{6})$/', $trimmed) === 1 ? $trimmed : null;
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

    private function readFloat(string $path, int $storeId, float $min, float $max, float $default): float
    {
        $value = $this->readRaw($path, $storeId);

        if ($value === null) {
            return $default;
        }

        $normalized = trim((string) $value);

        if ($normalized === '' || preg_match('/^\d+(\.\d+)?$/', $normalized) !== 1) {
            return $default;
        }

        $parsed = (float) $normalized;

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
