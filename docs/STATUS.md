# Development Status

## Current milestone

Milestone 0 is closed. Milestone 1A — secure configuration-reading foundations — and Milestone 1B — provider foundations (registries, capabilities, resolver, exceptions, fallback policy) — are implemented and verified. Milestone 1B also corrects the provider-extension design: the DI registry is the runtime allowlist, third-party providers can be registered through DI, and Admin option sources derive from registry metadata. Real provider HTTP adapters, retry/circuit-breaking, and RAG calls remain out of scope (Milestone 1C+).

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

## Verified in the current workspace (executed results)

- Module `Aavirbhava_AiShoppingAssistant` is enabled (`module:status`).
- `setup:upgrade` passed.
- `setup:di:compile` passed (before and after the Milestone 1A changes).
- `cache:flush` passed.
- `composer.json` validation passed (`composer validate --strict` inside the Magento container).
- PHP syntax checks passed for 76 PHP files (Milestone 0 baseline: 17).
- Five Magento XML files are well formed (Milestone 0 baseline: four).
- PHPUnit: 107 tests, 398 assertions passed (Milestone 1B baseline: 76 tests, 329 assertions). Executed with the workspace root's PHPUnit 9.5.24; the module requires `^10.5 || ^11.0`, so CI runs a newer runner.
- Default configuration loaded correctly for all 29 module config paths through `ScopeConfigInterface`; the intentionally empty `llm/base_url` default resolves to an empty string.
- Dependency-injection resolution verified inside Magento: `ConfigurationReaderInterface` -> `ConfigurationReader`, `SecretReaderInterface` -> `SecretReader`.
- `setup:di:compile` validates the new registry, resolver, and policy preferences together with their empty provider-array arguments.
- All three API-key fields (`llm/api_key`, `fallback/api_key`, `embedding/api_key`) use `Magento\Config\Model\Config\Backend\Encrypted`.
- Stored API-key values decrypt through `EncryptorInterface`; empty stored values return an empty `SecretValue` so local providers can operate without a key.
- Standalone structure validator passes.

## Operational note

Start Magento with `bin/start` from the repository root. Plain `docker compose up` can reproduce a transient database startup race.

## Not verified

- Browser-based Admin rendering of the configuration section has not been verified. Admin form rendering, scoped save/load through the UI, and per-store overrides remain to be tested.
- Because no real provider adapters are registered yet, the Admin provider dropdowns render an empty option list in production until adapters are contributed through DI; this is expected and tested.
- Real provider HTTP adapters, embedding and RAG retrieval, and the orchestration pipeline (Milestone 1C+) are not implemented.

## Next implementation slice

Milestone 1C: sanitized test-connection actions, a bounded HTTP client abstraction, retry/circuit-breaker policy, and real vendor API adapters behind the registries.