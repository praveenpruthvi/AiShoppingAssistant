<?php

declare(strict_types=1);

namespace Aavirbhava\AiShoppingAssistant\Model\Config;

final class Path
{
    public const PREFIX = 'ai_shopping_assistant/';

    public const GENERAL_ENABLED = self::PREFIX . 'general/enabled';
    public const GENERAL_STRICT_STORE_ONLY = self::PREFIX . 'general/strict_store_only';

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

    public const GUARDRAILS_MAX_INPUT_CHARACTERS = self::PREFIX . 'guardrails/max_input_characters';
    public const GUARDRAILS_MAX_TOOL_CALLS = self::PREFIX . 'guardrails/max_tool_calls';
    public const GUARDRAILS_CART_MUTATIONS_ENABLED = self::PREFIX . 'guardrails/cart_mutations_enabled';
    public const GUARDRAILS_BLOCK_EXTERNAL_URLS = self::PREFIX . 'guardrails/block_external_urls';
    public const GUARDRAILS_BLOCK_CODE_GENERATION = self::PREFIX . 'guardrails/block_code_generation';
    public const GUARDRAILS_OUT_OF_SCOPE_MESSAGE = self::PREFIX . 'guardrails/out_of_scope_message';

    private function __construct()
    {
    }
}
