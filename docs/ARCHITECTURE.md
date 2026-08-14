# Architecture

## Runtime boundaries

The Magento module owns authorization, configuration, retrieval orchestration, tool execution, validation, session handling, and storefront presentation.

External inference servers are optional dependencies reached through HTTP adapters. Phase 1 must not require a separate custom Python application. Local Ollama, llama.cpp, or vLLM deployments may expose an OpenAI-compatible API.

## Request pipeline

1. Validate request size, session, rate limits, and store context.
2. Normalize input without destroying meaningful product terms.
3. Classify the request into an allowlisted commerce intent.
4. Return a fixed Magento response immediately for out-of-scope requests.
5. Extract filters and requested product attributes into a validated schema.
6. Run keyword retrieval, attribute filters, and vector retrieval.
7. Merge candidates and optionally rerank them.
8. Fetch current Magento facts for final candidates.
9. Allow the LLM to select tools or compose a grounded response.
10. Validate every returned SKU, claim, action, URL, price, and stock statement.
11. Render product cards from Magento data, not model-authored HTML.
12. Record redacted metrics and evaluation signals.

## Primary interfaces

### LLM provider

```php
interface LlmProviderInterface
{
    public function chat(ChatRequest $request): ChatResponse;
    public function testConnection(): ConnectionResult;
    public function capabilities(): ProviderCapabilities;
}
```

Expected adapters:

- `OpenAiProvider`
- `AnthropicProvider`
- `XAiProvider`
- `OpenAiCompatibleProvider`

### Embedding provider

```php
interface EmbeddingProviderInterface
{
    public function embed(array $texts): EmbeddingBatch;
    public function dimensions(): int;
    public function fingerprint(): string;
    public function capabilities(): ProviderCapabilities;
}
```

Changing the embedding fingerprint or dimensions invalidates the assistant index.

### Provider capabilities

`ProviderCapabilities` is an immutable value object that declares what a provider supports (chat generation, embeddings, tool calling, structured output, streaming, optional API key, configurable base URL). Every capability defaults to `false`; providers must declare support explicitly. It carries no secrets and no provider instances.

### Provider registries

`LlmProviderRegistryInterface` and `EmbeddingProviderRegistryInterface` hold providers contributed through Magento DI as an array keyed by allowlisted identifiers from `ProviderIdentifiers`. Unknown or unregistered identifiers fail closed with a sanitized `ProviderNotFoundException`; identifiers are never turned into class names.

### Configured provider resolution

`ConfiguredProviderResolverInterface` maps store-scoped configuration to registered providers: `primaryLlmProvider(int $storeId)`, `fallbackLlmProvider(int $storeId): ?LlmProviderInterface` (null when disabled or unset), and `embeddingProvider(int $storeId)`. It depends only on the configuration reader and the registries, never on secrets or dynamic class resolution.

### Fallback eligibility policy

`FallbackEligibilityPolicyInterface::isEligible(Throwable)` permits fallback only for transient availability failures (`ProviderTimeoutException`, `ProviderRateLimitException`, `ProviderTransportException`, `ProviderUnavailableException`). Configuration, authentication, invalid-response, refusal, and policy failures are never eligible, and unknown exceptions fail closed.

### Provider exceptions

All provider failures are represented by a sanitized hierarchy rooted at `ProviderException` with a stable `errorCode()`. Messages are generic and customer-safe; raw request/response bodies and secrets never reach exception messages or logs.

### Commerce tool

```php
interface CommerceToolInterface
{
    public function name(): string;
    public function inputSchema(): array;
    public function authorize(ToolContext $context): void;
    public function execute(ToolContext $context, array $input): ToolResult;
}
```

Initial allowlist:

- `search_products`
- `get_product_details`
- `compare_products`
- `check_price`
- `check_inventory`
- `search_store_content`
- `get_cart`
- `add_to_cart`
- `remove_from_cart`

### Ranking signal

```php
interface RankingSignalInterface
{
    public function apply(SearchContext $context, CandidateCollection $items): CandidateCollection;
}
```

Phase 1 signals:

- Keyword relevance
- Vector similarity
- Attribute match
- Availability

Reserved Phase 2 signals:

- Merchant promotion
- Campaign
- Popularity
- New arrival
- Margin
- Clearance
- Consent-based personalization

## Search index

Use a dedicated versioned alias and physical index:

```text
Alias:    magento_ai_products_<store_id>
Physical: magento_ai_products_<store_id>_<schema_version>_<timestamp>
```

Rebuild into a new physical index and atomically move the alias after successful validation.

Indexed fields may include identifiers, store scope, visibility, normalized attributes, categories, searchable text, content hash, retrieval-time filter fields, and embeddings.

Price and stock fields may help candidate retrieval but must be revalidated through Magento services before display or action.

## Indexing

- Register a custom indexer named `ai_product_rag`.
- Full reindex processes products in configurable batches.
- Product/category changes enqueue affected entity IDs.
- Consumers normalize, hash, embed, and upsert documents.
- Unchanged content hashes skip embedding generation.
- Disabled, deleted, invisible, or unassigned products are removed for the relevant store scope.
- A scheduled reconciliation detects missed events.

## Failure policy

- Retry transient provider failures with bounded exponential backoff.
- Open a circuit breaker after the configured threshold.
- Use the configured local fallback only for availability failures.
- Do not use fallback to override safety refusals, invalid tool calls, authorization failures, or response-validation failures.
- If generation remains unavailable, return deterministic Magento search results with a concise notice.
