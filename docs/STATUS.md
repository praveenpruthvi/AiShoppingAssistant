# Development Status

## Current milestone

Milestone 0 is closed. Milestone 1A — secure configuration-reading foundations — is implemented and verified. Real provider adapters, HTTP, fallback, circuit-breaking, and RAG calls remain out of scope (Milestone 1B+).

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

## Verified in the current workspace (executed results)

- Module `Aavirbhava_AiShoppingAssistant` is enabled (`module:status`).
- `setup:upgrade` passed.
- `setup:di:compile` passed (before and after the Milestone 1A changes).
- `cache:flush` passed.
- `composer.json` validation passed (`composer validate --strict` inside the Magento container).
- PHP syntax checks passed for 38 PHP files (Milestone 0 baseline: 17).
- Five Magento XML files are well formed (Milestone 0 baseline: four).
- PHPUnit: 37 tests, 160 assertions passed (Milestone 0 baseline: 7 tests, 70 assertions). Executed with the workspace root's PHPUnit 9.5.24; the module requires `^10.5 || ^11.0`, so CI runs a newer runner.
- Default configuration loaded correctly for all 29 module config paths through `ScopeConfigInterface`; the intentionally empty `llm/base_url` default resolves to an empty string.
- Dependency-injection resolution verified inside Magento: `ConfigurationReaderInterface` -> `ConfigurationReader`, `SecretReaderInterface` -> `SecretReader`.
- All three API-key fields (`llm/api_key`, `fallback/api_key`, `embedding/api_key`) use `Magento\Config\Model\Config\Backend\Encrypted`.
- Stored API-key values decrypt through `EncryptorInterface`; empty stored values return an empty `SecretValue` so local providers can operate without a key.
- Standalone structure validator passes.

## Operational note

Start Magento with `bin/start` from the repository root. Plain `docker compose up` can reproduce a transient database startup race.

## Not verified

- Browser-based Admin rendering of the configuration section has not been verified. Admin form rendering, scoped save/load through the UI, and per-store overrides remain to be tested.
- Real provider HTTP adapters, embedding and RAG retrieval, and the orchestration pipeline (Milestone 1B+) are not implemented.

## Next implementation slice

Milestone 1B: provider registry, sanitized test-connection actions, a bounded HTTP client abstraction, retry/circuit-breaker policy, and fake-provider contract tests before writing real vendor API adapters.