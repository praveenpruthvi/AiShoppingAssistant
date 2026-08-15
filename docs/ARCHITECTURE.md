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

The production default is `OpenSearchProductDocumentWriter` (Milestone 2C1). It is a two-phase store-scoped writer:

- `beginRun()` freezes the run context, snapshots each store's embedding configuration (provider, model, base URL, dimensions, fingerprint, base-URL hash — never secrets) and index prefix, pings the OpenSearch backend and checks vector support (fail closed through `AssistantSearchClientInterface`), creates one physical index per enabled store, and self-cleans any partial index creation on failure.
- `writeBatch()` validates document schema, store scope, and website assignment, re-validates the frozen embedding configuration before and after every provider request, enriches documents through `EmbeddingEnrichmentService` (bounded batches, positional correlation, vector content hashing), and writes bulk payloads in bounded chunks. Storage payloads separate the transport `_id` from the persisted `_source`; bulk responses are verified item-by-item (count, status, per-item error, `_id` order) and any malformed or rejected item fails the whole chunk.
- `activateRun()` verifies the new physical index `_meta` before refresh, then atomically moves each store's read alias to the new physical index in one `updateAliases` call. Every existing alias target must parse as an assistant run index and have `_meta` proving assistant ownership for the same store, website, physical index, valid run id, and matching run token; old target schema/mapping versions are not used for compatibility and legitimate older versions can be removed during upgrades. The new physical index separately proves compatibility through exact current run id/token, schema version, mapping version, embedding dimensions, fingerprint, and base-URL hash. Any foreign or unproven target fails activation closed before the alias changes. After activation the writer returns to the idle state.
- `abortRun()` is idempotent and removes only current-run unaliased indexes whose mapping `_meta` proves assistant ownership, current run id/token, and the frozen embedding base-URL hash. Any failed cleanup is reported as a sanitized `index_abort_failed` error after the run state is reset; later calls are no-ops.

After either activation or abort the writer is reusable for a fresh run. The embedding configuration snapshot is re-validated during enrichment so a mid-run provider/model/dimension change fails the run instead of producing an incompatible index.

`UnavailableProductDocumentWriter` remains as the fail-closed fallback: every lifecycle call throws a sanitized `backend_unavailable` exception and `abortRun()` is a safe idempotent no-op. `IncrementalProductIndexSchedulerInterface` receives row/list/mview updates; the production default is `DurableIncrementalProductIndexScheduler`, which validates positive product ids, records durable ledger generations, and then publishes product-id wake-up messages. `MagentoIncrementalProductIndexScheduler` remains the direct queue publisher transport used by the durable scheduler and recovery.

Milestone 2C2B2A adds a durable Magento database ledger, `aavirbhava_ai_incremental_product_work`, as the source of truth for unfinished incremental product work. Queue messages are wake-up signals only. The ledger stores one coalesced row per positive product entity id with latest requested `generation`, nullable processing `claimed_generation`, `state`, bounded `attempts`, `next_attempt_at`, opaque `lease_token`, `lease_expires_at`, sanitized `last_error_code`, and timestamps. It never stores product content, snapshots, prompts, embeddings, provider responses, credentials, URLs, headers, customer data, raw exception messages, or traces.

Ledger states are `pending`, `queued`, `processing`, `retry_wait`, `complete`, and `blocked`. Scheduling a product increments the latest requested generation. If no worker is processing, the row becomes fresh `pending` with attempts reset; if a worker is processing, the active state, lease token, lease expiry, claimed generation, and old-generation attempts are preserved so no second claim can start. A consumer can process only after atomically claiming the current due generation and receiving an opaque lease token. Completion, retry, and terminal updates prove the exact product id, claimed generation, and lease. These decision/update transitions use short `SELECT ... FOR UPDATE` row-locking transactions that read the latest generation while locked and include that generation in the update predicate; no transaction is held across embedding or OpenSearch work. If a newer generation was requested while the older claim ran, the older completion/failure clears only the old lease and releases the newest generation as fresh `pending` work with attempts reset; it never marks the newer generation complete, blocked, or retried.

The durable scheduler validates the entire id list before mutation, deduplicates and sorts ids, records every id in the ledger, and only then publishes product-id wake-ups. If publishing fails after recording, the ledger remains due for cron recovery. `UnavailableIncrementalProductIndexScheduler` remains directly constructible as an explicit fail-closed fallback, but it is no longer the production preference.

The staged consumer validates one product id, acquires a non-blocking Magento product worker lock, claims due ledger work, invokes `ProductIncrementalIndexerInterface::process()` exactly once for a successful claim, then completes only the claimed generation/lease while still holding the lock. If the product lock is unavailable, the consumer returns before claiming; durable recovery can wake the product later. Lock-management failures are sanitized, and unlock failures never replace a primary indexing or ledger failure. Only indexing failures are classified. Ledger persistence failures during completion, retry, or terminal recording propagate as sanitized ledger failures and are never converted into terminal product work. Known retryable failures are recorded as `retry_wait`; safety, validation, configuration, incompatible-index, malformed-data, provider/refusal-like, and unknown failures become `blocked`. The allowlist currently treats only `OpenSearchBackendUnavailableException` as transient. Retry uses the claim's real attempt count and is bounded to five attempts with deterministic exponential backoff from 60 seconds up to 3600 seconds. Failure text is never persisted.

`IncrementalWorkRecovery` is wired through cron (`aavirbhava_ai_incremental_work_recovery`, every five minutes). It takes a Magento lock, recovers expired leases, selects at most 50 due product ids in deterministic order, atomically marks each as queued with a wake-up visibility timeout, and republishes product-id wake-ups. Expired leases count as failed attempts for the claimed generation and follow the same bounded retry/backoff; if a newer generation was requested during the expired processing attempt, recovery releases the newer generation as fresh `pending` work instead of charging attempts to it. Recovery does not index or embed, and it remains transport-neutral across AMQP, STOMP, and database queue backends.

Milestone 2C2B2B1 adds a durable singleton full-rebuild fence, `aavirbhava_ai_rebuild_fence`, containing only active state, an opaque owner token, lease expiry, and timestamps. Full rebuilds acquire a fixed Magento rebuild gate before acquiring the durable fence, then drain already-processing ledger claims through bounded expired-lease recovery and processing-count checks until the incremental processing lease plus a safety margin expires. The drain uses injected clock/sleeper services, renews the fence during polling, and does not busy-loop. Rebuilds renew the fence around store preparation/finalization and each product batch, verify exact ownership immediately before alias activation, and hold the rebuild gate across ownership verification, `activateRun()`, and exact-token durable fence release. Incremental consumers keep their per-product lock, then acquire the same rebuild gate only around fence-check and ledger claim; they release the gate before indexing or embedding. Incremental product changes continue to record ledger generations while the fence is active, and consumed queued wake-ups that encounter a valid fence are converted back to immediately due `pending` work for post-cutover recovery. Malformed fence rows fail closed through sanitized rebuild/ledger exceptions. No database transaction is held across catalogue loading, embedding, OpenSearch writes, alias activation, or incremental processing.

### Incremental indexing core

`ProductIncrementalIndexerInterface::process(int $productId)` is the transport-independent Milestone 2C2A core for one explicitly validated positive product entity id. It has no HTTP, session, customer, current-store, ObjectManager, observer, cron, or queue dependency; the Milestone 2C2B1 consumer validates one positive id from Magento's queue and invokes the core once. Indexing/provider/OpenSearch failures propagate from the handler, but Magento's default consumer rejects ordinary handler exceptions with `requeue=false`; propagation prevents acknowledgement but does not guarantee broker retry. Each call resolves active frontend store scopes, reads store-scoped general/indexing configuration, skips disabled stores without touching OpenSearch or embeddings, validates the exact store read alias, reloads the current Magento snapshot, and reconciles one deterministic store document id (`<store_id>_<entity_id>`).

Incremental writes never use wildcards. For each enabled store the core resolves the canonical read alias (`<prefix>_store_<store_id>_current`), requires exactly one physical target, parses the target as an assistant run index, reads exact `_meta`, and proves assistant marker, store id, website id, physical index, valid run id/token, current `ProductDocumentSchema::VERSION`, current `ProductIndexMappingInterface::MAPPING_VERSION`, and frozen embedding dimensions/fingerprint/base-URL hash before reading or writing a document. Missing, mixed, foreign, malformed, or incompatible aliases fail closed with `incremental_target_invalid` and are retried or repaired by a full rebuild.

Per-store product decisions are idempotent:

- Missing or ineligible product: delete the store-scoped document id through the validated alias. Delete-not-found is success.
- Eligible product with existing compatible state and unchanged `completeDocumentHash`: no OpenSearch write and no embedding call.
- Eligible product whose `completeDocumentHash` changed but whose `embeddingContentHash`, embedding fingerprint, and validated vector are unchanged: reuse the existing vector and write the updated document without an embedding call.
- Eligible product with changed embedding content, absent state, incompatible fingerprint, or unproven/malformed/non-finite/wrong-dimension vector: generate a fresh document embedding through the frozen store-scoped embedding boundary and write the complete document.

`embeddingHash` remains integrity/diagnostics only and is never used as a skip key. Duplicate delivery is harmless because every attempt reloads current Magento data, current alias metadata, and current indexed state; provider or OpenSearch failures are not recorded as success, and work is only complete after the delete/write succeeds.

### Failure semantics

On any failure after the run began: no new batches are started, `abortRun()` is called exactly once, `activateRun()` is never called, and a sanitized `ProductIndexingException` (stable `errorCode()`: `backend_unavailable`, `invalid_entity_ids`, `run_init_failed`, `store_prep_failed`, `batch_normalization_failed`, `batch_write_failed`, `activation_failed`, `abort_failed`, `index_abort_failed`, `incremental_scheduler_unavailable`, `invalid_metrics`, `invalid_result`) is thrown carrying the aborted-run metrics. If abort cleanup also fails, `index_abort_failed` is surfaced with the primary rebuild failure preserved in the exception chain. Messages are generic and customer-safe.

### Index invalidation

`Model\Config\Backend\InvalidateProductIndex` (a `Magento\Framework\App\Config\Value` subclass) invalidates `ai_product_rag` through `Magento\Framework\Indexer\IndexerRegistry` only when a content-affecting indexing setting changes: searchable attribute codes, description flags, variant aggregation, or the attribute value budget. `batch_size` never invalidates the index. No indexing or embedding work happens during the config save.

### Catalogue change capture and reconciliation

`etc/indexer.xml` registers the indexer with the action class `Model\Indexer\ProductIndexer` (implements both `Magento\Framework\Indexer\ActionInterface` and `Magento\Framework\Mview\ActionInterface`). Magento's update-on-save product model invokes core indexers from DB commit callbacks, but it does not automatically invoke arbitrary custom product indexers. The module therefore captures update-on-save product changes through post-commit observers/plugins that schedule only validated product ids when the assistant indexer is not in scheduled mode. Scheduled mode is captured through `etc/mview.xml`; subscriptions are limited to tables whose changelog entity is the product id (`catalog_product_entity*` via `entity_id`, `catalog_product_website` via `product_id`, and `catalog_category_product` via `product_id`). The module does not subscribe category or option tables whose IDs are not product IDs.

Indirect catalogue dependencies such as category names/paths and product attribute option labels are handled by `IncrementalProductReconciliationInterface`. Category and attribute commit events request a fresh bounded reconciliation pass by resetting a singleton cursor; they do not fan out product IDs, load products, embed, or touch OpenSearch. A cron job (`aavirbhava_ai_incremental_product_reconciliation`, every ten minutes) takes a Magento lock, reads a small keyset batch of product entity ids after the checkpoint, schedules those ids through the durable scheduler, advances the checkpoint only after scheduling succeeds, and resets the cursor after a complete pass. The checkpoint stores only cursor metadata and timestamps.

## Search index

The dedicated assistant index uses a versioned per-store alias and physical index:

```text
Alias:    <prefix>_store_<store_id>_current
Physical: <prefix>_store_<store_id>_run_<safe_run_token>
```

The prefix is store-scoped configuration (`ai_shopping_assistant/indexing/index_prefix`, default `aavirbhava_ai_product_rag`) validated as `^[a-z][a-z0-9_-]{0,63}$`. `IndexNamingService` builds both names; the safe run token is a lowercase alphanumeric, max 32 characters derived from the UUID run id. Rebuilds write a new physical index and atomically move the alias to it after successful validation. Activation first proves the new index `_meta` for compatibility (assistant marker, store/website ids, current run id, physical index, schema/mapping versions, embedding dimensions, embedding fingerprint, and base-URL hash), then separately proves every existing alias target is owned by this assistant (strict run-shaped name, assistant marker, matching store/website ids, physical index echo, valid run id, and run token matching the parsed name). Existing owned targets may carry older schema/mapping versions so legitimate upgrades can replace them; mixed aliases containing foreign or unproven targets fail closed before any alias update.

The `ProductIndexMapping` (versioned, `dynamic: false`) declares identifier, store scope, visibility, normalized attribute, category, searchable-text, content-hash, retrieval-time filter fields, and a `knn_vector` field with dimension, `cosinesimil` space type, and a Lucene HNSW method block (`ef_construction`, `m`). Index settings enable `index.knn` and use a bounded, non-disabled `refresh_interval`; the writer still refreshes explicitly before alias activation. `_meta` records non-secret provenance: assistant index marker, schema version, mapping version, store/website id, run id, physical index, embedding fingerprint, dimensions, and base-URL hash. Alias/physical names and mapping fields must never expose secrets.

Price and stock fields may help candidate retrieval but must be revalidated through Magento services before display or action.

The client seam is `AssistantSearchClientInterface` (`OpenSearchAssistantClient` in production, `UnavailableAssistantSearchClient` as the fail-closed fallback). The production client builds the Magento OpenSearch client through `OpenSearchClientFactory` from `Magento\Elasticsearch\Model\Config::prepareClientOptions()` with bounded timeouts, no retries, and credentials passed through builder authentication config (never embedded in a host URI). Hosts, credentials, and request/response bodies never leave the client, not even in exception chains: every transport failure is translated into a sanitized `ProductIndexingException` with no raw previous cause. The factory validates the scheme, hostname, and port, preserves bracketed IPv6 literals, rejects embedded credentials, fragments, paths, queries, and embedded ports, and requires non-empty username/password values when authentication is enabled.

## Indexing

- Register a custom indexer named `ai_product_rag` (registered in Milestone 2B2).
- Full reindex processes products in configurable batches through the OpenSearch writer (Milestone 2C1).
- Product/category changes enqueue affected product entity IDs through the durable scheduler. Update-on-save capture runs after commit and suppresses direct observer/plugin scheduling when the assistant indexer is in scheduled mode so mview triggers do not double schedule the same direct change.
- The staged queue consumer invokes the Milestone 2C2A incremental core to normalize, hash, embed only when needed, and upsert/delete documents. The core resolves the store read alias to exactly one compatible physical index, reads state from that physical index, and writes/deletes only that physical index. Before no-op, write, or delete completion it rechecks the frozen embedding config and confirms the alias still points to the same compatible physical target; any change fails closed for later reconciliation.
- The vector content hash is an integrity and diagnostics value only. The Milestone 2C2 consumer skips embedding generation based on the normalized `embeddingContentHash` combined with the frozen embedding fingerprint and schema compatibility — not on the vector hash alone.
- Disabled, deleted, invisible, or unassigned products are removed for the relevant store scope.
- A scheduled bounded reconciliation detects missed events and indirect catalogue changes without storing product content in its checkpoint.

Hybrid retrieval and reranking are not yet implemented. The embedding fingerprint/dimension invalidation path is in place (a model or endpoint change that alters the fingerprint or dimensions invalidates the assistant index). Milestone 2C2A narrows local alias races for one incremental execution, Milestone 2C2B1 adds Magento queue transport for product ids only, Milestone 2C2B2A adds durable ledger recovery, Milestone 2C2B2B1 fences full rebuilds against concurrent incremental claims, and Milestone 2C2B2B2 activates durable catalogue-change scheduling plus bounded reconciliation.

## Failure policy

- Retry transient provider failures with bounded exponential backoff.
- Open a circuit breaker after the configured threshold.
- Use the configured local fallback only for availability failures.
- Do not use fallback to override safety refusals, invalid tool calls, authorization failures, or response-validation failures.
- If generation remains unavailable, return deterministic Magento search results with a concise notice.
