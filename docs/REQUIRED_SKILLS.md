# Required Project Skills

This file defines the knowledge areas a coding agent or contributor must apply. It does not grant permission to expand project scope.

## Magento module engineering

Required knowledge:

- Magento 2 module registration and Composer packaging.
- Dependency injection, virtual types, preferences, plugins, observers, and service contracts.
- Admin system configuration, encrypted backend models, ACL, routes, and UI components.
- Website/store-view/customer-group scoping.
- Catalogue, inventory/MSI, pricing, configurable products, carts, and masked cart IDs.
- Indexers, cron, message queues, cache invalidation, and deployment modes.
- Magento unit, integration, API, and static-analysis workflows.

## Search and RAG

Required knowledge:

- OpenSearch mappings, aliases, filters, BM25, vector search, and bulk indexing.
- Hybrid search and rank fusion.
- Attribute-aware query parsing.
- Embedding model/version management.
- Reranking and score calibration.
- Retrieval evaluation: Recall@K, MRR, NDCG, zero-result rate, and latency.
- Grounding and source attribution.

## LLM integration

Required knowledge:

- Provider-neutral adapters.
- Tool/function calling.
- Strict structured output schemas.
- Streaming without bypassing final validation.
- Token, latency, retry, caching, and cost controls.
- Cloud-to-local failover and circuit breakers.
- Model capability differences without leaking them into domain logic.

## Application security

Required knowledge:

- Prompt injection and indirect prompt injection.
- Deny-by-default intent and tool policies.
- Authentication, authorization, CSRF, rate limits, and session ownership.
- Secret encryption and log redaction.
- Output validation and grounded-claim verification.
- Data isolation across websites, stores, customers, and carts.
- Abuse testing and failure-closed behavior.

## Frontend integration

Required knowledge:

- Magento layout XML, blocks/view models, RequireJS or the selected compatible frontend approach.
- Theme-safe UI insertion and configuration.
- Accessible keyboard and screen-reader behavior.
- Streaming/status presentation without rendering untrusted HTML.
- Product cards sourced from Magento data.
- Graceful fallback to ordinary catalogue search.

## Observability and evaluation

Required knowledge:

- Structured, redacted logs.
- Request correlation identifiers.
- Provider/retrieval/tool latency and failure metrics.
- Token and cost measurement.
- Merchant-visible diagnostics.
- Regression datasets and adversarial suites.

## Skill application rule

Before implementing a subsystem, confirm the contributor understands its Magento boundary, security boundary, failure behavior, tests, and observability. If not, research or isolate the task before coding.
