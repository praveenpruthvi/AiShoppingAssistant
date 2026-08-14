# Project Scope

## Product statement

AI Shopping Assistant is a reusable Magento 2 module that converts natural-language customer requests into grounded catalogue searches and Magento-authorized commerce actions.

It is not a general chatbot. It may only assist with products, categories, store information, store policies, and explicitly enabled Magento commerce operations belonging to the current site.

## Phase 1 scope

### Included

- Composer-installable Magento 2 module.
- Admin configuration with encrypted API keys.
- OpenAI, Anthropic, xAI, and OpenAI-compatible/local provider adapters.
- Independent embedding-provider configuration.
- Full and incremental catalogue indexing.
- Dedicated OpenSearch index with keyword, filter, and vector retrieval.
- Optional reranking endpoint.
- Store-only intent guardrails.
- Product search, product questions, comparison, live price, and inventory checks.
- Site policy and approved CMS retrieval.
- Structured product-card responses.
- Optional read-only cart access.
- Add/remove cart operations only when enabled and explicitly confirmed.
- Admin retrieval playground and diagnostics.
- Anonymous session limits and rate limiting.
- Tests for grounding, tenancy, prompt injection, and tool authorization.

### Excluded

- General web search.
- General knowledge assistance.
- Arbitrary code generation or execution.
- Autonomous checkout, payment, refund, cancellation, or account changes.
- Unlabelled sponsored recommendations.
- Cross-store catalogue leakage.
- Training or fine-tuning on customer conversations by default.
- Personalization without merchant configuration and appropriate customer consent.

## Phase 2 candidates

- Merchant-defined product, category, and brand boosts.
- Scheduled campaigns and promoted products.
- New-arrival, bestseller, margin, and clearance ranking signals.
- Sponsored labels and promotion explanations.
- Conversion attribution and recommendation analytics.
- A/B testing of retrieval and ranking policies.

Phase 2 ranking must extend the Phase 1 ranking pipeline rather than replace retrieval.

## Future candidates

- Consent-based personalization.
- Customer-segment ranking.
- Order-status and support assistance.
- Human-support escalation.
- Image and voice product search.
- Merchant-managed buying guides and curated recommendation journeys.

## Success criteria

For a representative evaluation set, the assistant must:

- Identify commerce intent and structured filters reliably.
- Retrieve suitable products with measurable recall.
- Never fabricate catalogue or transactional facts.
- Refuse unrelated requests without invoking unnecessary expensive generation.
- Preserve store, website, customer, and cart isolation.
- Degrade to ordinary safe search when AI providers are unavailable.
