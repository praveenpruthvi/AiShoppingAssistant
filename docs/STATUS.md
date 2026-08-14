# Development Status

## Current milestone

Milestones 0–2A are closed. Milestone 2B1 — store-scoped catalogue loading — is implemented and verified: indexing configuration, immutable store scopes, keyset product-id batching, bounded snapshot loading with batch category/attribute resolution, and store-view category and attribute labels. The custom `ai_product_rag` indexer, queue consumers, embeddings, and the assistant search index remain out of scope (Milestone 2B2+).

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

## Operational note

Start Magento with `bin/start` from the repository root. Plain `docker compose up` can reproduce a transient database startup race.

## Not verified

- Browser-based Admin rendering of the configuration section has not been verified. Admin form rendering, scoped save/load through the UI, and per-store overrides remain to be tested.
- Because no real provider adapters are registered yet, the Admin provider dropdowns render an empty option list in production until adapters are contributed through DI; this is expected and tested.
- Real provider HTTP adapters, the `ai_product_rag` custom indexer, queue consumers, the assistant search index, and hybrid retrieval (Milestone 2B2+) are not implemented.
- The `indexing` Admin group is registered in `system.xml` and compiles, but its browser rendering and per-store save/load have not been exercised through the Admin UI.
- Two-store-view catalogue isolation is covered by unit mocks only; the current sample environment has a single frontend store view.

## Next implementation slice

Milestone 2B2: the custom `ai_product_rag` indexer, asynchronous queue consumers with content-hash skipping, the dedicated store-scoped assistant search index, embedding provider adapters, and hybrid retrieval.