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
    public function identifier(): string;
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
    public function identifier(): string;
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

`LlmProviderRegistryInterface` and `EmbeddingProviderRegistryInterface` hold provider instances contributed by installed Magento modules through Magento DI as an array keyed by provider identifier. The registry **is** the runtime allowlist: only registered identifiers can be resolved, and unknown or unregistered identifiers fail closed with a sanitized `ProviderNotFoundException`.

Registration rules enforced by each registry:

- The DI array key must be a syntactically valid identifier.
- The provider's `identifier()` must be syntactically valid and must exactly equal the DI key.
- The injected instance must implement the expected provider interface (a provider only implementing `LlmProviderInterface` cannot appear in the embedding registry, and vice versa).

Installed Magento modules are trusted application code; their DI contributions define what may be selected. Configuration never contains a class name and no class is ever instantiated dynamically from configuration. A configured identifier is always treated as a registry key, so a class-name-like value simply fails closed.

Magento DI merges array arguments across modules before the registry constructor runs, so duplicate keys have already been collapsed into a single entry. Duplicate-contribution detection is therefore not possible inside the registry.

### Provider identifiers

Identifiers are lowercase ASCII, start with a letter, and contain only letters, numbers, and underscores, up to 64 characters: `^[a-z][a-z0-9_]{0,63}$`. `ProviderIdentifiers` centralizes the built-in identifiers as constants (`openai`, `anthropic`, `xai`, `openai_compatible` for LLM; `openai`, `voyage`, `openai_compatible` for embeddings) and validates syntax. These constants are reference values, not an exhaustive allowlist: a third-party identifier such as `acme_local_llm` is permitted once registered through DI and once it satisfies the syntax rule. Invalid identifiers produce a sanitized configuration exception that never echoes the invalid value.

### Third-party provider extension

A third-party module contributes a provider by implementing the provider interface and adding DI items to the registry argument:

```xml
<type name="Aavirbhava\AiShoppingAssistant\Model\Provider\LlmProviderRegistry">
    <arguments>
        <argument name="providers" xsi:type="array">
            <item name="acme_local_llm" xsi:type="object">Acme\Module\Model\Provider\AcmeLocalLlm</item>
        </argument>
    </arguments>
</type>
```

The item name must match `identifier()`. The same pattern applies to the embedding registry. Merchant configuration then stores only the identifier `acme_local_llm`.

### Provider labels and Admin option sources

`ProviderLabelRegistryInterface` supplies trusted display labels contributed through DI; labels are static metadata, never customer input. `ProviderOptionService` builds deterministic (identifier-sorted) option lists from a registry's `all()`. The Admin source models `Model\Config\Source\Provider` (primary and fallback LLM fields) and `Model\Config\Source\EmbeddingProvider` render options from the registries, so registered third-party providers appear automatically. Option lists carry only identifiers and labels; provider instances, capabilities, credentials, and configuration values never reach the Admin UI. Empty registries render an empty option list safely, and a saved provider that later becomes unavailable fails closed during resolution.

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

## Catalogue normalization

The assistant index is built from deterministic, sanitized product documents, never from raw catalogue content.

Pipeline stages (`ProductDocumentNormalizerInterface::normalize`):

1. Eligibility gate (`ProductIndexEligibilityPolicyInterface`): a snapshot must match the requested store view, be assigned to the requested website, be enabled, and be visible in search (`Visibility::VISIBILITY_IN_SEARCH` or `VISIBILITY_BOTH`). Failures return a reason code (`store_mismatch`, `website_not_assigned`, `disabled`, `not_search_visible`, `invalid_identity`) and never reach sanitization or embedding.
2. Sanitization (`UntrustedContentSanitizerInterface`): untrusted text is treated as data, never instructions. Scripts, styles, hidden content, comments, event handlers, forms, and iframes are removed with a DOM pass (with `LIBXML_NONET`, no entity expansion) or a plain entity-decode pass; control characters are stripped and whitespace is collapsed. Entity-encoded attacks such as `&lt;script&gt;` are removed after decoding. External entities are never resolved.
3. Attribute policy (`ProductAttributePolicyInterface`): fails closed. Only lowercase valid attribute codes not on the internal/secret denylist survive; `cost`, admin/internal notes, and credential-like codes (including obfuscated substrings such as `secret_key_2`) are excluded.
4. Deterministic normalization: empty values are pruned, categories are sorted by id, attributes are sorted by code, and duplicate values are collapsed.
5. Searchable text is assembled in a fixed order (name, short description, long description, category names, category paths, attribute labels, attribute values) with exact-duplicate parts removed.
6. Content hashing (`ContentHashServiceInterface`): canonical SHA-256 digests. `embeddingContentHash` covers only the embedding payload (name, descriptions, categories, attributes, searchable text) so status/scope-only changes skip re-embedding. `completeDocumentHash` covers the whole persisted document for idempotent, retry-safe index writes. `ProductDocumentSchema::VERSION` centralizes the schema version and invalidates the index when bumped.

The normalized document deliberately excludes price, stock, salability, URLs, media, and customer-group data. Those facts are always resolved from Magento services at retrieval or display time.

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
