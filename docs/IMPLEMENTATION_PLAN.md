# Implementation Plan

## Milestone 0 — Decisions and skeleton

- Use the confirmed `Aavirbhava_AiShoppingAssistant` module identity and `aavirbhava/module-ai-shopping-assistant` Composer package.
- Declare supported Magento, PHP, OpenSearch, and deployment versions.
- Create Composer package and Magento registration.
- Add CI, coding standards, static analysis, and test commands.
- Add safe default configuration and ACL structure.

Exit condition: module installs, enables, upgrades, disables, and uninstalls cleanly in a test environment.

Current status: standalone skeleton and configuration are implemented. Executable Magento installation and compilation verification remain pending; see `docs/STATUS.md`.

## Milestone 1 — Provider foundation

- Define provider-neutral request/response DTOs.
- Implement encrypted provider configuration.
- Add OpenAI, Anthropic, xAI, and OpenAI-compatible adapters.
- Implement test-connection actions with sanitized errors.
- Add bounded timeouts, retries, circuit breaker, and fallback policy.

Exit condition: contract tests pass for every enabled provider without domain logic knowing provider-specific formats.

## Milestone 2 — Index and retrieval

- Define normalized product document and store-scoped index mapping.
- Implement embedding interface and first provider.
- Register `ai_product_rag` indexer.
- Implement full indexing, incremental queue, hashes, retry, and reconciliation.
- Implement filters, BM25, vector retrieval, rank fusion, and optional reranking.
- Add Admin Index Management and Retrieval Playground.

Exit condition: evaluation queries retrieve expected products and no store-scoping leaks occur.

Current status: Milestones 0, 1, and 2B1 are closed. Milestone 2B2 closed the `ai_product_rag` indexer registration and full-rebuild orchestration (run context, two-phase writer contract, safe unavailable defaults, sanitized failure taxonomy, config-driven invalidation, inert mview view). Remaining Milestone 2 work (Milestone 2C): queue consumers with content-hash skipping, the dedicated store-scoped OpenSearch index, embedding provider adapters, and hybrid retrieval.

## Milestone 3 — Guardrails and read-only assistant

- Implement fixed intent allowlist and classifier schema.
- Add deterministic block rules and rate limits.
- Add read-only Magento commerce tools.
- Add store-content source allowlist.
- Implement structured grounded response and server-side verification.
- Add deterministic refusal and non-AI fallback.

Exit condition: adversarial suite passes and unsupported requests cannot use the primary model as a general chatbot.

## Milestone 4 — Storefront experience

- Add configurable chat/search entry point.
- Build accessible conversation UI and Magento-backed product cards.
- Support clarification, comparison, and safe follow-up state.
- Add loading, timeout, refusal, empty-result, and AI-unavailable states.
- Ensure theme-safe integration.

Exit condition: end-to-end search and comparison work on desktop/mobile and remain usable when AI is unavailable.

## Milestone 5 — Cart operations

- Implement cart read with ownership validation.
- Add exact-action confirmation records.
- Add idempotent add/remove operations.
- Revalidate SKU/options/quantity/price/salability before mutation.
- Add audit events without storing sensitive content.

Exit condition: mutation tests cover guest/customer carts, replay, changed stock/price, authorization, and provider failure.

## Milestone 6 — Production hardening

- Add merchant-visible metrics and redacted diagnostics.
- Add load, latency, queue recovery, and index alias-swap tests.
- Add retention configuration and privacy documentation.
- Establish upgrade/rollback procedure.
- Package and test installation on a clean supported Magento instance.

Exit condition: Phase 1 acceptance criteria in `PROJECT_SCOPE.md` pass.

## Phase 2 — Merchandising foundation

- Implement ranking-signal plugins for campaigns and product/category/brand boosts.
- Add start/end scheduling and store scoping.
- Label promoted results in API and UI.
- Keep an organic score for auditability.
- Add impression, click, cart, and conversion attribution.
- Add A/B evaluation without weakening guardrails.

Exit condition: a merchant can explain why an item was recommended and whether promotion affected its position.
