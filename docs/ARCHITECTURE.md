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
    public function embed(EmbeddingRequestInterface $request): EmbeddingResultInterface;
    public function dimensions(): int;
    public function fingerprint(): string;
    public function capabilities(): ProviderCapabilities;
}
```

Changing the embedding fingerprint or dimensions invalidates the assistant index.

Embedding adapters are stateless and store-scoped: every call receives a fully validated `EmbeddingRequest` carrying the resolved model, base URL, API key (`SecretValue`), timeout, and expected dimensions for one store. Adapters never retain config, secrets, or raw responses, and never perform network requests during construction.

Initial adapters:

- `OpenAiEmbeddingProvider` — official `https://api.openai.com/v1` endpoint, mandatory Bearer API key.
- `VoyageEmbeddingProvider` — official `https://api.voyageai.com/v1` endpoint, mandatory Bearer API key, sends the explicit `input_type` (`document`/`query`).
- `LocalOpenAiCompatibleEmbeddingProvider` — explicit base URL required (HTTP allowed), API key optional; no Authorization header is sent when the key is empty.

### Embedding request and result boundaries

`EmbeddingInputType` restricts input to `document`/`query`; `EmbeddingInput` pairs a normalized non-empty text with a deterministic positional identifier. `EmbeddingRequest` is immutable and validates store id, inputs, model, timeout (1–300s), and dimensions (1–16384). `EmbeddingVector` rejects empty, non-numeric, and non-finite values and enforces the declared dimension. `EmbeddingUsage` holds non-negative token counts. `EmbeddingResult` is immutable and requires non-empty, unique identifiers matching the vectors one-to-one.

### Embedding generation service

`EmbeddingGenerationService` is the only supported entry point for callers: it activates and scopes to a store view, reads store-scoped embedding configuration, resolves exactly one provider (never a fallback), reads the store-scoped API key, validates inputs through `EmbeddingInputValidator` (batch size ≤ 100, per-text ≤ 8000 bytes, combined ≤ 200000 bytes, valid UTF-8, never silently truncates), builds the request, and validates the returned `EmbeddingResult` against the expected identifiers and configured dimensions. Configuration, provider-resolution, and secret failures surface as sanitized embedding exceptions. The service writes nothing.

### Provider endpoint policy

Cloud providers (OpenAI, Voyage) may only use their official HTTPS base URL: a configured override is rejected fail-closed unless it exactly equals the official default. The local OpenAI-compatible provider requires an explicit base URL and may use HTTP or HTTPS, but never embedded credentials or fragments. The inspected URL is never placed in an exception message.

### HTTP transport boundary

`ProviderHttpTransport` (Magento `LaminasClient` with the Curl adapter) enforces: URL sanity (scheme, host, no credentials/fragments), mandatory TLS verification that can never be disabled, `maxredirects => 0`, a bounded timeout in seconds, JSON content headers, and a bounded 10 MB response body. HTTP status mapping: 401/403 → authentication, 429 → rate limit, 408/504 → timeout, 5xx → unavailable, other 4xx/3xx → invalid response. Timeouts and transport failures are detected by message markers and curl errno and surface as sanitized exceptions; raw URLs, headers, bodies, and credentials never enter messages.

### Embedding response verification

Before any vector is accepted, `AbstractEmbeddingProvider` requires exactly one distinct index per input position (0..n-1); missing, duplicate, unknown, or malformed indexes are rejected, while a complete distinct permutation is safely restored to input order via `ksort`. Vectors are validated for the configured dimension and numeric finiteness, and `EmbeddingResultValidator` re-checks identifier correlation and dimensions at the service boundary.

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

Identifiers are lowercase ASCII, start with a letter, and contain only letters, numbers, and underscores, up to 64 characters: `^[a-z][a-z0-9_]{0,63}$`. `ProviderIdentifiers` centralizes the built-in identifiers as constants (`openai`, `anthropic`, `xai`, `openai_compatible` for LLM; `openai`, `voyage`, `local_openai_compatible` for embeddings) and validates syntax. These constants are reference values, not an exhaustive allowlist: a third-party identifier such as `acme_local_llm` is permitted once registered through DI and once it satisfies the syntax rule. Invalid identifiers produce a sanitized configuration exception that never echoes the invalid value.

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

## Store-scoped catalogue loading

The loading layer (Milestone 2B1) turns the Magento catalogue into raw `ProductSnapshot` DTOs, strictly scoped by store view and website, with bounded memory and no N+1 queries. It performs no indexing: the custom indexer, queue consumers, and the assistant search index remain separate concerns.

### Store scope

`StoreScopeProviderInterface::activeStores()` returns every active frontend store view as an immutable `StoreScopeInterface` (store id, website id, store code, optional locale), sorted by store id. The admin store (id 0) is never a scope. `requireActive(int $storeId)` resolves a single active scope and throws a sanitized `StoreScopeException` otherwise. No assistant query may run without a `StoreScopeInterface`.

### Indexing configuration

`ConfigurationReaderInterface::readIndexing(int $storeId)` returns the immutable `IndexingConfigInterface`: batch size (10–500, default 100), an explicit lowercase attribute-code allowlist (blank means no custom attributes), short/long description flags, `maxAttributeValuesPerProduct` (1–500, default 100), and `aggregateConfigurableVariants` (always disabled). Codes are validated with the `SearchableAttribute` syntax rule; policy-denied codes are dropped before sorting and slicing to 50. Configurable-variant aggregation is rejected at configuration time with a sanitized `ConfigurationException` until a variant aggregator exists.

### Product id batching

`ProductIdBatchProviderInterface::batches(scope, batchSize)` yields ascending, disjoint product entity-id lists using a keyset over `entity_id` (`> lastId`, ascending order, explicit page size, `getAllIds`), limited to products assigned to the scope's website. The generator terminates cleanly on an empty catalogue. Batch sizes outside 1–1000 throw `InvalidArgumentException`.

### Snapshot loading

`ProductSnapshotProviderInterface::load(scope, config, ids)` loads one bounded product collection per call: store-view and website filters, an id filter, only the needed fields (`name`, `status`, `visibility`, descriptions when enabled, configured attribute codes), category ids via `addCategoryIds()` (which must run after `load()`), ascending order, and a page size equal to the requested id count. Category references are resolved once per batch through `CategoryReferenceResolverInterface`, and store-view attribute values once per product through `SearchableAttributeValueResolverInterface`. Requested ids that cannot be loaded are returned as `missingProductIds`, never as an error.

### Category references

`CategoryReferenceResolverInterface::resolve(scope, ids)` loads the requested categories in one bounded, store-scoped batch and missing ancestors from their `path` segments in a second batch. Global (id 1) and the store root category are excluded from paths, inactive and empty-name categories are skipped, and references are returned sorted by category id.

### Attribute values

`SearchableAttributeValueResolverInterface::resolve(scope, config, product)` resolves configured, policy-allowed attributes to store-view labels. Option-based attributes (select, multiselect, boolean) map option ids to store-view option labels; other attributes pass through scalar values. Empty values are removed, results are sorted by code, and the per-product value budget is shared across attributes.

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

## Full rebuild orchestration

The custom indexer action (`ai_product_rag`) delegates full rebuilds to `FullProductReindexerInterface::rebuild()`. Memory stays bounded: only one batch of ids, snapshots, and documents exists at a time.

Algorithm:

1. Resolve active store scopes (`StoreScopeProviderInterface`) and each store's explicit `IndexingConfigInterface`.
2. Skip stores where the assistant is disabled. If none remain, return a safe no-op `RebuildResult` without ever touching the document writer.
3. Build an immutable `RebuildRunContext` (server-generated UUID v4 run id, schema version, store-id-sorted scopes, start time).
4. Open the run in the `ProductDocumentWriterInterface`, then for each enabled store prepare the store, stream keyset id batches -> `ProductSnapshot`s -> eligibility-normalized `ProductDocument`s, and write only eligible documents.
5. Only after every enabled store finished, `activateRun()`.

### Document writer contract

`ProductDocumentWriterInterface` is the two-phase boundary between orchestration and a future index backend:

```php
interface ProductDocumentWriterInterface
{
    public function beginRun(RebuildRunContextInterface $context): void;
    public function beginStore(StoreScopeInterface $scope): void;
    public function writeBatch(array $documents): void;
    public function finishStore(): void;
    public function activateRun(): void;
    public function abortRun(): void;
}
```

The default `UnavailableProductDocumentWriter` never fails open: every lifecycle call throws a sanitized `backend_unavailable` exception, and `abortRun()` is a safe idempotent no-op. A future OpenSearch writer replaces it through DI. `IncrementalProductIndexSchedulerInterface` receives row/list updates; the default validates positive ids (never silently discarding any) and refuses explicitly with `incremental_scheduler_unavailable` until the queue/consumer pipeline exists.

### Failure semantics

On any failure after the run began: no new batches are started, `abortRun()` is called exactly once, `activateRun()` is never called, and a sanitized `ProductIndexingException` (stable `errorCode()`: `backend_unavailable`, `invalid_entity_ids`, `run_init_failed`, `store_prep_failed`, `batch_normalization_failed`, `batch_write_failed`, `activation_failed`, `abort_failed`, `incremental_scheduler_unavailable`, `invalid_metrics`, `invalid_result`) is thrown carrying the aborted-run metrics. Messages are generic and customer-safe.

### Index invalidation

`Model\Config\Backend\InvalidateProductIndex` (a `Magento\Framework\App\Config\Value` subclass) invalidates `ai_product_rag` through `Magento\Framework\Indexer\IndexerRegistry` only when a content-affecting indexing setting changes: searchable attribute codes, description flags, variant aggregation, or the attribute value budget. `batch_size` never invalidates the index. No indexing or embedding work happens during the config save.

### Indexer and mview wiring

`etc/indexer.xml` registers the indexer with the action class `Model\Indexer\ProductIndexer` (implements both `Magento\Framework\Indexer\ActionInterface` and `Magento\Framework\Mview\ActionInterface`). `etc/mview.xml` declares the matching view with **no subscriptions** — the mview schema requires at least one `table` inside `subscriptions`, so the element is omitted entirely. The inert view satisfies `Magento\Framework\Mview\View::load()` (which `reindexAll()` calls unconditionally) without creating a changelog or enabling incremental processing. The action class and the config backend model are not `final` because Magento generates interceptors for `ActionInterface` implementers and `Config\Value` subclasses.

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

- Register a custom indexer named `ai_product_rag` (registered in Milestone 2B2).
- Full reindex processes products in configurable batches.
- Product/category changes enqueue affected entity IDs.
- Consumers normalize, hash, embed, and upsert documents.
- Unchanged content hashes skip embedding generation.
- Disabled, deleted, invisible, or unassigned products are removed for the relevant store scope.
- A scheduled reconciliation detects missed events.

Queue consumers, embedding generation, and the search-index backend are not yet implemented; until then the document writer and incremental scheduler refuse explicitly rather than failing open.

## Failure policy

- Retry transient provider failures with bounded exponential backoff.
- Open a circuit breaker after the configured threshold.
- Use the configured local fallback only for availability failures.
- Do not use fallback to override safety refusals, invalid tool calls, authorization failures, or response-validation failures.
- If generation remains unavailable, return deterministic Magento search results with a concise notice.
