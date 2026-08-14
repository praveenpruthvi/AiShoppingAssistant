# Development Status

## Current milestone

Milestones 0–2B1 are closed. Milestone 2B2 — the `ai_product_rag` indexer and full-rebuild orchestration — is implemented and verified: the custom indexer action, immutable rebuild run context, two-phase document-writer contract, safe unavailable writer/scheduler defaults, sanitized failure taxonomy, and configuration-driven index invalidation. Queue consumers, embeddings, and the assistant search index remain out of scope (Milestone 2C+).

## Completed

Milestone 0:

- Permanent package identity set to `aavirbhava/module-ai-shopping-assistant`.
- Magento module registered as `Aavirbhava_AiShoppingAssistant`.
- Compatibility policy declared for Magento 2.4.7–2.4.9, PHP 8.2–8.5, and OpenSearch 2/3.
- Composer package metadata and PSR-4 autoloading created.
- Magento module declaration, dependency sequence, ACL, defaults, and Admin system configuration created.
- Primary LLM, fallback LLM, embedding, retrieval, and guardrail settings added.
- API-key fields use Magento encrypted configuration backend models.
- Module and mutating capabilities default to disabled; strict guardrails default to enabled.
- Provider-neutral LLM and embedding contracts added.
- Immutable boundary DTOs and focused unit-test cases added.
- Standalone structural validator and CI workflow added.

Milestone 1A:

- Configuration section interfaces and immutable DTOs for general, LLM, fallback, embedding, retrieval, and guardrail configuration.
- Store-scoped `ConfigurationReaderInterface` backed by `ScopeConfigInterface` and `ScopeInterface::SCOPE_STORE` with explicit store-ID arguments.
- Safe numeric bounds enforced in PHP, fail-closed security booleans, and sanitized `ConfigurationException` for missing required provider/model values.
- Separate `SecretReaderInterface`/`SecretReader` using `EncryptorInterface` with explicit store scoping.
- Immutable `SecretValue` with private storage, explicit `reveal()`, no `__toString()`, and redacted `__debugInfo()` and JSON serialization.
- Interface-to-implementation DI preferences registered in `etc/di.xml`.

Milestone 1B:

- Hard safety ceilings tightened in `ConfigurationReader`: max output tokens `8192`, max input characters `10000`, max tool calls `10`, final products `20`. Defaults (`1200`, `1000`, `4`, `8`) stay within ceilings.
- `ProviderIdentifiers` centralizes built-in identifier constants and validates identifier syntax (`^[a-z][a-z0-9_]{0,63}$`); it is no longer a closed allowlist.
- `identifier()` added to `LlmProviderInterface` and `EmbeddingProviderInterface`.
- `LlmProviderRegistry`/`EmbeddingProviderRegistry` DI-backed registries that ARE the runtime allowlist; they validate DI key syntax, provider `identifier()` syntax, key/identifier equality, and interface conformance, and fail closed with sanitized `ProviderNotFoundException` for unregistered identifiers.
- Third-party providers (for example `acme_local_llm`, `acme_embeddings`) are registerable through DI; configuration stores only identifiers, never class names, and no class is instantiated dynamically from configuration.
- `ProviderCapabilities` — immutable capability metadata, all capabilities default to false.
- `ConfiguredProviderResolver` maps store-scoped config to registered providers (primary LLM, nullable fallback LLM, embedding) with no secret or Object Manager dependency.
- Sanitized provider exception hierarchy: abstract `ProviderException` with `errorCode()` plus ten final classes covering configuration, authentication, rate limit, timeout, transport, unavailable, invalid response, refusal, policy violation, and not-found.
- `FallbackEligibilityPolicy` — fallback eligible only for transient availability failures; safety/validation failures and unknown exceptions fail closed.
- `ProviderLabelRegistry`, `ProviderOption`, and `ProviderOptionService` supply trusted DI metadata labels and deterministic options; Admin source models for LLM and embedding providers derive options from the registries.
- Fake providers (`Test/Unit/Fake/`) and contract tests for identifiers, registries, label registry, option sources, resolver, fallback policy, and the exception taxonomy.
- DI preferences, empty provider-array extension points, and built-in provider labels registered in `etc/di.xml`.

Duplicate DI-key detection is intentionally not implemented: Magento DI merges array arguments across modules before the registry constructor runs, so duplicates have already been collapsed and cannot be observed inside the registry.

Milestone 2A:

- `Api/Catalog/` service contracts and immutable `Model/Catalog/` DTOs: `ProductSnapshot`, `ProductEligibilityContext/Result`, `ProductDocument`, `ProductNormalizationResult`, `CategoryReference`, `SearchableAttribute`; all constructors validate and throw sanitized `CatalogException`.
- `ProductIndexEligibilityPolicy` — deterministic store/website scope and search-visibility gate using Magento's real `Visibility` constants; reason codes `invalid_identity`, `store_mismatch`, `website_not_assigned`, `disabled`, `not_search_visible`, `eligible`.
- `UntrustedContentSanitizer` — DOM path with `LIBXML_NONET` (no entity expansion) plus a plain entity-decode path; removes blocked tags, hidden content, comments, event handlers, control characters, and entity-encoded scripts after decoding; collapses whitespace; documented per-field length caps.
- `ProductAttributePolicy` — fails closed: lowercase valid codes only, explicit internal/credential denylist plus obfuscation-resistant substring checks; sorted filtering.
- `ContentHashService` — canonical SHA-256 (recursive key sort, list order preserved); `embeddingContentHash` excludes status/scope/audit fields so scope-only changes skip re-embedding; `completeDocumentHash` covers the full persisted document.
- `ProductDocumentNormalizer` — eligibility gate, sanitization, attribute policy, empty-value pruning, deterministic ordering, fixed-order searchable-text assembly, and hashes; deterministic and idempotent; empty SKU/name after sanitization fail with `CatalogException`.
- `ProductDocumentSchema::VERSION = 1` centralizes schema versioning for index invalidation.
- DI preferences, `Magento_Catalog` module dependency, and `magento/module-catalog >=104.0 <105.0` composer constraint added.

Milestone 2B1:

- `ConfigurationReaderInterface::readIndexing` plus the immutable `IndexingConfigInterface`/`IndexingConfig`: batch size (10–500, default 100), explicit lowercase attribute-code allowlist (blank = no custom attributes, policy-denied codes dropped, sorted, capped at 50), description flags, `maxAttributeValuesPerProduct` (1–500, default 100), and `aggregate_configurable_variants` (always disabled; enabling is rejected with a sanitized `ConfigurationException`). Defaults registered in `etc/config.xml` and Admin system configuration under the `indexing` group.
- `StoreScopeInterface`/`StoreScope` and `StoreScopeProviderInterface`/`StoreScopeProvider` built on `StoreManagerInterface`: active frontend store views only, admin store (id 0) excluded, deterministic store-id ordering, optional locale via store config, sanitized `StoreScopeException`.
- `ProductIdBatchProviderInterface`/`ProductIdBatchProvider` — ascending, disjoint keyset batches over `entity_id` with explicit website filtering; batch sizes outside 1–1000 throw `InvalidArgumentException`; empty catalogues terminate cleanly.
- `ProductSnapshotProviderInterface`/`ProductSnapshotProvider` — one bounded, store-scoped product collection per batch with selective field loading and `addCategoryIds()` applied after `load()`; produces `ProductSnapshot` DTOs with store-scoped attributes and per-batch category references; requested-but-unloaded ids returned as `missingProductIds`, never an error.
- `ProductSnapshotBatch` immutable batch DTO (snapshots + missing ids, validated).
- `CategoryReferenceResolverInterface`/`CategoryReferenceResolver` — two bounded, store-scoped category batches (requested ids, then missing ancestors from `path` segments); excludes global/root categories, skips inactive/empty-name categories, store-relative paths, sorted by id.
- `SearchableAttributeValueResolverInterface`/`SearchableAttributeValueResolver` — resolves configured, policy-allowed attributes to store-view labels; select/multiselect/boolean option ids map to store-view option labels via a cloned store-scoped attribute source; scalar values otherwise; empty values removed, sorted by code, shared per-product value budget.
- Five new DI preferences; `StoreScope` never depends on store emulation and no direct SQL or writes are used.

Milestone 2B2:

- `Api/Indexing/` contracts: `FullProductReindexerInterface::rebuild()`, `ProductDocumentWriterInterface` (two-phase contract: `beginRun`, `beginStore`, `writeBatch`, `finishStore`, `activateRun`, `abortRun`), immutable `RebuildRunContextInterface` (server-generated UUID v4 run id, schema version, store-id-sorted scopes, start time) with `RebuildRunContextFactoryInterface`, `RebuildMetricsInterface`, `RebuildResultInterface` (activated/no-op/aborted), and `IncrementalProductIndexSchedulerInterface::schedule/scheduleMany`.
- `FullProductReindexer` — bounded-memory rebuild orchestration: resolve active store scopes and per-store indexing config, skip disabled stores (no-op without touching the writer), generate a run context, then per store stream keyset id batches -> snapshots -> eligibility-normalized `ProductDocument`s to the writer, activating the run only after every enabled store finished. On failure: no new batches, `abortRun` exactly once if the run began, `activateRun` never called, sanitized exception carrying the aborted-run result.
- `RebuildMetrics` validates counters, reason-code keys (from `ProductEligibilityResultInterface::REASON_*`), and duration; `RebuildResult` validates the outcome value. Both immutable.
- Sanitized exception taxonomy rooted at `ProductIndexingException` (extends `LocalizedException`) with stable error codes: `backend_unavailable`, `invalid_entity_ids`, `run_init_failed`, `store_prep_failed`, `batch_normalization_failed`, `batch_write_failed`, `activation_failed`, `abort_failed`, `incremental_scheduler_unavailable`, `invalid_metrics`, `invalid_result`; optional aborted-run result attached; generic customer-safe messages.
- `UnavailableProductDocumentWriter` (default) — indexing never fails open: every lifecycle call throws `backend_unavailable`; `abortRun` is a safe idempotent no-op. `UnavailableIncrementalProductIndexScheduler` (default) — validates positive ids (never silently discards), then refuses with `incremental_scheduler_unavailable` because queues are not implemented.
- `Model\Indexer\ProductIndexer` implements both `Magento\Framework\Indexer\ActionInterface` and `Magento\Framework\Mview\ActionInterface`: `executeFull` rebuilds; `executeRow/executeList/execute` forward to the scheduler. Not final because Magento generates interceptors for every `ActionInterface` implementer.
- `etc/indexer.xml` registers indexer `ai_product_rag` (view id + action class). `etc/mview.xml` declares the matching view with no subscriptions — the schema requires a `table` element if `subscriptions` is present, so it is omitted entirely; the inert view satisfies `Magento\Framework\Mview\View::load()` during reindex and never activates a changelog.
- `Model\Config\Backend\InvalidateProductIndex` extends `Magento\Framework\App\Config\Value` (not final because Magento intercepts `Value` subclasses) and invalidates `ai_product_rag` only when a content-affecting indexing setting changes (`searchable_attribute_codes`, description flags, variant aggregation, attribute value budget); `batch_size` never invalidates. Attached via `backend_model` in `system.xml`; registry failures become sanitized `ConfigurationException`.
- `magento/module-indexer >=100.4 <101.0` declared in `composer.json` and `Magento_Indexer` sequenced in `module.xml` (framework `IndexerRegistry` lives in `magento/framework`, which was already required).

## Verified in the current workspace (executed results)

- Module `Aavirbhava_AiShoppingAssistant` is enabled (`module:status`).
- `setup:upgrade` passed.
- `setup:di:compile` passed (before and after the Milestone 1A changes).
- `cache:flush` passed.
- `composer.json` validation passed (`composer validate --strict` inside the Magento container).
- PHP syntax checks passed for 141 PHP files.
- Five Magento XML files are well formed (`acl.xml`, `config.xml`, `di.xml`, `module.xml`, `adminhtml/system.xml`).
- PHPUnit: 248 tests, 708 assertions passed (Milestone 2A baseline: 200 tests, 582 assertions). Executed with the workspace root's PHPUnit 9.5.24; the module requires `^10.5 || ^11.0`, so CI runs a newer runner.
- Milestone 2A coverage includes eligibility paths (including the `invalid_identity` reason via a stub snapshot), sanitization (script, entity-encoded script, hidden content, comment, and external-entity non-expansion cases), attribute policy obfuscation, hash canonicalization and non-UTF-8 rejection, document validation, and full normalizer pipeline tests (determinism, scope independence of `embeddingContentHash`, injection stripping, cross-store ineligibility).
- `ProductIndexEligibilityPolicy` resolves Magento's `Magento\Catalog\Model\Product\Visibility` constants at runtime through the root autoload.
- Default configuration loaded correctly for all 29 module config paths through `ScopeConfigInterface`; the intentionally empty `llm/base_url` default resolves to an empty string.
- Dependency-injection resolution verified inside Magento: `ConfigurationReaderInterface` -> `ConfigurationReader`, `SecretReaderInterface` -> `SecretReader`.
- `setup:di:compile` validates the new registry, resolver, and policy preferences together with their empty provider-array arguments.
- All three API-key fields (`llm/api_key`, `fallback/api_key`, `embedding/api_key`) use `Magento\Config\Model\Config\Backend\Encrypted`.
- Stored API-key values decrypt through `EncryptorInterface`; empty stored values return an empty `SecretValue` so local providers can operate without a key.
- Standalone structure validator passes.
- Milestone 2B1 coverage includes indexing-config parsing/clamping/fail-closed cases (malformed code, policy-denied codes, enabled variant aggregation), store-scope DTO validation, active-store resolution excluding the admin store, keyset batching (ascending disjoint batches, empty catalogue, out-of-range batch size), snapshot batch validation, snapshot loading with category/attribute resolution and missing-id handling, category reference resolution (store-relative paths, ancestor backfill, inactive/missing skips, dedup), and attribute value resolution (store-view labels, multiselect, scalars, empty values, policy denial, shared budget, code ordering).
- In-Magento smoke test passed against the sample data (store 1 / website 1): exactly one active store scope with the admin store excluded, default indexing config, deterministic first product batch, full snapshot loading for the batch, store-view category references (e.g. `Gear`), store-view attribute labels (e.g. `color -> Yellow`, `size -> S`, `material -> Organic Cotton`), missing-id and empty-batch handling. Bounded to a single product batch.
- Milestone 2B2 coverage includes run-context validation (UUID v4, schema version, scopes), run-context factory (server-generated ids, dedup/sort, empty rejection), metrics counter/reason-code validation, result outcome validation, the full failure taxonomy (stable error codes, cause preservation, aborted-result attachment, sanitized messages), unavailable writer/scheduler behavior (explicit refusal, never silent discard, safe idempotent abort), and `FullProductReindexer` scenarios: disabled-store no-op without touching the writer, single-batch activation, batch-size pass-through, ineligible/missing counting by reason, empty-batch activation, snapshot-load failure, backend-unavailable beginRun, write-batch failure, activation failure, abort-failure preserving the primary, run-context failure, config-read failure, and distinct run ids across consecutive rebuilds.
- In-Magento smoke test passed for 2B2: `setup:upgrade`, `setup:di:compile`, and `cache:flush` succeed; `indexer:info` lists `ai_product_rag`; `indexer:reindex ai_product_rag` completes successfully in 00:00:00 as a no-op because `general/enabled` defaults to 0 and the unavailable writer would refuse if a store required indexing; `indexer:status` shows Ready. The inert mview view (no subscriptions) loads during reindex without creating a changelog.

## Operational note

Start Magento with `bin/start` from the repository root. Plain `docker compose up` can reproduce a transient database startup race.

## Not verified

- Browser-based Admin rendering of the configuration section has not been verified. Admin form rendering, scoped save/load through the UI, and per-store overrides remain to be tested.
- Because no real provider adapters are registered yet, the Admin provider dropdowns render an empty option list in production until adapters are contributed through DI; this is expected and tested.
- Real provider HTTP adapters, queue consumers, the assistant search index, embedding generation, and hybrid retrieval (Milestone 2C+) are not implemented.
- The `indexing` Admin group is registered in `system.xml` and compiles, but its browser rendering and per-store save/load have not been exercised through the Admin UI.
- The `InvalidateProductIndex` backend model is unit-tested but has not been exercised through an actual Admin config save.
- Two-store-view catalogue isolation is covered by unit mocks only; the current sample environment has a single frontend store view.

## Next implementation slice

Milestone 2C: asynchronous queue consumers with content-hash skipping, the dedicated store-scoped assistant search index (OpenSearch), embedding provider adapters, and hybrid retrieval.