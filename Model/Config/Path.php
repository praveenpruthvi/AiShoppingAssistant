<?php

declare(strict_types=1);

namespace Aavirbhava\AiShoppingAssistant\Model\Config;

final class Path
{
    public const PREFIX = 'ai_shopping_assistant/';

    public const GENERAL_ENABLED = self::PREFIX . 'general/enabled';
    public const GENERAL_STRICT_STORE_ONLY = self::PREFIX . 'general/strict_store_only';
    public const GENERAL_MAX_CONVERSATION_MESSAGES = self::PREFIX . 'general/max_conversation_messages';

    public const APPEARANCE_PRIMARY_COLOR = self::PREFIX . 'appearance/primary_color';
    public const APPEARANCE_MESSAGE_BUBBLE_COLOR = self::PREFIX . 'appearance/message_bubble_color';
    public const APPEARANCE_MESSAGE_TEXT_COLOR = self::PREFIX . 'appearance/message_text_color';

    public const LLM_PROVIDER = self::PREFIX . 'llm/provider';
    public const LLM_API_KEY = self::PREFIX . 'llm/api_key';
    public const LLM_MODEL = self::PREFIX . 'llm/model';
    public const LLM_BASE_URL = self::PREFIX . 'llm/base_url';
    public const LLM_TIMEOUT_SECONDS = self::PREFIX . 'llm/timeout_seconds';
    public const LLM_MAX_OUTPUT_TOKENS = self::PREFIX . 'llm/max_output_tokens';

    public const FALLBACK_ENABLED = self::PREFIX . 'fallback/enabled';
    public const FALLBACK_PROVIDER = self::PREFIX . 'fallback/provider';
    public const FALLBACK_API_KEY = self::PREFIX . 'fallback/api_key';
    public const FALLBACK_MODEL = self::PREFIX . 'fallback/model';
    public const FALLBACK_BASE_URL = self::PREFIX . 'fallback/base_url';
    public const FALLBACK_TIMEOUT_SECONDS = self::PREFIX . 'fallback/timeout_seconds';
    public const FALLBACK_FAILURE_THRESHOLD = self::PREFIX . 'fallback/failure_threshold';
    public const FALLBACK_COOLDOWN_SECONDS = self::PREFIX . 'fallback/cooldown_seconds';

    public const EMBEDDING_PROVIDER = self::PREFIX . 'embedding/provider';
    public const EMBEDDING_API_KEY = self::PREFIX . 'embedding/api_key';
    public const EMBEDDING_MODEL = self::PREFIX . 'embedding/model';
    public const EMBEDDING_BASE_URL = self::PREFIX . 'embedding/base_url';
    public const EMBEDDING_DIMENSIONS = self::PREFIX . 'embedding/dimensions';

    public const RETRIEVAL_KEYWORD_CANDIDATES = self::PREFIX . 'retrieval/keyword_candidates';
    public const RETRIEVAL_VECTOR_CANDIDATES = self::PREFIX . 'retrieval/vector_candidates';
    public const RETRIEVAL_MERGED_CANDIDATES = self::PREFIX . 'retrieval/merged_candidates';
    public const RETRIEVAL_FINAL_PRODUCTS = self::PREFIX . 'retrieval/final_products';
    public const RETRIEVAL_RERANKER_ENABLED = self::PREFIX . 'retrieval/reranker_enabled';
    public const RETRIEVAL_RATING_SIGNAL_WEIGHT = self::PREFIX . 'retrieval/rating_signal_weight';

    public const GUARDRAILS_MAX_INPUT_CHARACTERS = self::PREFIX . 'guardrails/max_input_characters';
    public const GUARDRAILS_MAX_TOOL_CALLS = self::PREFIX . 'guardrails/max_tool_calls';
    public const GUARDRAILS_CART_MUTATIONS_ENABLED = self::PREFIX . 'guardrails/cart_mutations_enabled';
    public const GUARDRAILS_REQUIRE_CART_CONFIRMATION = self::PREFIX . 'guardrails/require_cart_confirmation';
    public const GUARDRAILS_BLOCK_EXTERNAL_URLS = self::PREFIX . 'guardrails/block_external_urls';
    public const GUARDRAILS_BLOCK_CODE_GENERATION = self::PREFIX . 'guardrails/block_code_generation';
    public const GUARDRAILS_OUT_OF_SCOPE_MESSAGE = self::PREFIX . 'guardrails/out_of_scope_message';

    public const CAPABILITIES_PRODUCT_DISCOVERY_ENABLED = self::PREFIX . 'capabilities/product_discovery_enabled';
    public const CAPABILITIES_PRODUCT_DETAILS_ENABLED = self::PREFIX . 'capabilities/product_details_enabled';
    public const CAPABILITIES_COMPARISON_ENABLED = self::PREFIX . 'capabilities/comparison_enabled';
    public const CAPABILITIES_PRICE_CHECKING_ENABLED = self::PREFIX . 'capabilities/price_checking_enabled';
    public const CAPABILITIES_STOCK_CHECKING_ENABLED = self::PREFIX . 'capabilities/stock_checking_enabled';
    public const CAPABILITIES_POLICY_SEARCH_ENABLED = self::PREFIX . 'capabilities/policy_search_enabled';
    public const CAPABILITIES_PROMOTION_AWARENESS_ENABLED = self::PREFIX . 'capabilities/promotion_awareness_enabled';

    public const COST_CAP_AMOUNT = self::PREFIX . 'cost_cap/amount';
    public const COST_CAP_PERIOD = self::PREFIX . 'cost_cap/period';
    public const COST_CAP_WARNING_THRESHOLD_PERCENT = self::PREFIX . 'cost_cap/warning_threshold_percent';
    public const COST_CAP_ALLOW_OVERRIDE = self::PREFIX . 'cost_cap/allow_override';
    public const COST_CAP_NOTIFICATION_EMAILS = self::PREFIX . 'cost_cap/notification_emails';

    public const PROVIDER_COST_OPENAI_PRICE_PER_1K_INPUT_TOKENS = self::PREFIX . 'provider_cost/openai_price_per_1k_input_tokens';
    public const PROVIDER_COST_OPENAI_PRICE_PER_1K_OUTPUT_TOKENS = self::PREFIX . 'provider_cost/openai_price_per_1k_output_tokens';
    public const PROVIDER_COST_OPENAI_COMPATIBLE_PRICE_PER_1K_INPUT_TOKENS = self::PREFIX . 'provider_cost/openai_compatible_price_per_1k_input_tokens';
    public const PROVIDER_COST_OPENAI_COMPATIBLE_PRICE_PER_1K_OUTPUT_TOKENS = self::PREFIX . 'provider_cost/openai_compatible_price_per_1k_output_tokens';

    public const INDEXING_BATCH_SIZE = self::PREFIX . 'indexing/batch_size';
    public const INDEXING_SEARCHABLE_ATTRIBUTE_CODES = self::PREFIX . 'indexing/searchable_attribute_codes';
    public const INDEXING_INCLUDE_SHORT_DESCRIPTION = self::PREFIX . 'indexing/include_short_description';
    public const INDEXING_INCLUDE_LONG_DESCRIPTION = self::PREFIX . 'indexing/include_long_description';
    public const INDEXING_AGGREGATE_CONFIGURABLE_VARIANTS = self::PREFIX . 'indexing/aggregate_configurable_variants';
    public const INDEXING_MAX_ATTRIBUTE_VALUES_PER_PRODUCT = self::PREFIX . 'indexing/max_attribute_values_per_product';
    public const INDEXING_INDEX_PREFIX = self::PREFIX . 'indexing/index_prefix';

    private function __construct()
    {
    }
}
