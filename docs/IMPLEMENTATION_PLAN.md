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

Current status: Milestones 0, 1, and 2B1 are closed. Milestone 2B2 closed the `ai_product_rag` indexer registration and full-rebuild orchestration (run context, two-phase writer contract, safe unavailable defaults, sanitized failure taxonomy, config-driven invalidation, inert mview view). Milestone 3A delivered real, testable embedding provider adapters (`openai`, `voyage`, `local_openai_compatible`) plus a store-scoped `EmbeddingGenerationService`, `EmbeddingRequest`/`EmbeddingResult` boundaries, provider endpoint policy, bounded HTTP transport, sanitized embedding exception taxonomy, and config corrections. Milestone 2C1 closed the store-scoped OpenSearch search index and the production `OpenSearchProductDocumentWriter` (two-phase lifecycle, per-store physical index + atomic alias activation, embedding enrichment, bulk writes, safe abort), replacing the unavailable writer as the DI default. The final 2C1 hardening pass added strict `_meta` ownership proofs before alias activation, separate new-index compatibility verification before refresh/swap, interleaved-store rejection, bulk-response and cluster-info validation, deterministic OpenSearch endpoint/auth configuration tests through a builder seam, and `index_abort_failed` cleanup-failure propagation that preserves the primary rebuild failure. Milestone 2C2A adds the transport-independent incremental product-indexing core with exact alias-to-physical target binding, exact catalogue reconciliation, missing/ineligible deletes, complete-document no-op, embedding-content vector reuse, and fresh embedding fallback when reuse cannot be proven. Milestone 2C2B1 delivers Magento queue declarations, a product-id-only publisher, and a validating consumer. Milestone 2C2B2A adds the durable incremental work ledger with separate latest and claimed generations, row-locked lease-proven transitions, per-product live-worker fencing, bounded retry classification, lease-expiry attempt accounting, and cron recovery. Milestone 2C2B2B1 adds the durable full-rebuild fence, cross-process rebuild gate, lease-bound processing-claim drain, batch/store heartbeat, strict fence-row validation, exact-token release, and post-cutover incremental recovery wake-up. Milestone 2C2B2B2 activates durable incremental scheduling with post-commit update-on-save capture, product-id-only mview subscriptions, bounded reconciliation for missed/indirect catalogue changes, and a durable cursor checkpoint. Remaining Milestone 2 work: hybrid retrieval (BM25 + vector + rank fusion) and optional reranking. Chat (primary LLM) generation, storefront UI, and guardrail classification remain out of scope.

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
