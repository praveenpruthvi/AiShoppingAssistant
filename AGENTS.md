# AI Shopping Assistant — Agent Instructions

These instructions apply to every file and task in this repository. They are the project constitution. If a task conflicts with this document, stop and request an explicit decision before continuing.

## Product goal

Build a reusable Magento 2 module that provides store-only conversational search, product discovery, comparison, recommendations, and safe cart assistance.

The module must be installable on different Magento Open Source or Adobe Commerce installations through Composer. It must not be coupled to one merchant, catalogue, theme, LLM provider, language, currency, or store view.

Permanent module identity:

- Composer package: `aavirbhava/module-ai-shopping-assistant`
- Magento module: `Aavirbhava_AiShoppingAssistant`
- PHP namespace: `Aavirbhava\AiShoppingAssistant`

Do not rename these inconsistently. Any future rename must change all identifiers together.

## Non-negotiable architecture

1. Magento remains the source of truth for product existence, visibility, price, stock, salability, customer-group pricing, cart state, URLs, and media.
2. Retrieval finds candidates. It must never be treated as live transactional truth.
3. The LLM is an untrusted planner and language generator. PHP application code authorizes tools and validates output.
4. Cloud and local LLMs use a shared provider interface.
5. Chat generation and embedding generation use separate provider interfaces.
6. Product indexing is asynchronous. Never generate embeddings inside a product-save HTTP request.
7. Use a dedicated assistant search index. Avoid risky changes to Magento's core catalogue-search mapping.
8. All features must be scoped by website and store view. Customer-specific data must also be scoped by authenticated customer/cart context.
9. Store-only guardrails are enabled by default and cannot depend on a system prompt alone.
10. Phase 1 must expose extension points for later merchandising, promotion, personalization, analytics, and additional tools without implementing all of them immediately.

## Required implementation order

1. Module skeleton, configuration, ACL, encrypted secrets, and interfaces.
2. Catalogue normalization and custom indexer.
3. Embedding provider and hybrid retrieval.
4. Scope classifier and guardrail pipeline.
5. Read-only Magento tools: search, details, price, inventory, policy, compare.
6. LLM orchestration and structured response validation.
7. Storefront chat/search UI.
8. Mutating cart tools with explicit confirmation.
9. Admin playground, diagnostics, logs, and evaluation suite.
10. Phase 2 ranking and promotion rules only after Phase 1 acceptance criteria pass.

## Fixed safety rules

- Never expose API keys, system prompts, configuration values, internal exception traces, admin data, or other customers' data.
- Never provide general-purpose coding, homework, news, politics, roleplay, or unrelated assistance.
- Never add web browsing, arbitrary HTTP, shell, filesystem, SQL, code execution, or unrestricted admin tools to the customer-facing agent.
- Never execute a mutating action without server-side validation and the required customer confirmation.
- Never let retrieved catalogue, CMS, review, or imported content override application instructions.
- Never invent a SKU, product, attribute, price, stock value, discount, policy, URL, review, or delivery promise.
- Never silently substitute a promoted product for the best organic match. Promotion must be labelled.
- Never treat a provider refusal or validation error as a reason to bypass safeguards through a fallback model.

## Engineering rules

- Target supported Magento 2 and PHP versions declared in `composer.json`; do not use undeclared runtime assumptions.
- Follow Magento dependency injection and service contracts. Avoid direct use of the Object Manager.
- Prefer interfaces, immutable DTOs/value objects, typed properties, strict types, and small services.
- Business logic belongs in services, not controllers, blocks, observers, templates, or UI components.
- Use Magento configuration scopes correctly: default, website, and store view.
- Store secrets with Magento's encrypted configuration backend and never return them to browser clients.
- Validate all LLM/tool JSON against explicit schemas.
- Use queues/consumers for indexing. Observers publish entity identifiers only.
- Make indexing idempotent with content hashes and safe retries.
- Log identifiers and timing, but redact prompts, customer PII, keys, authorization headers, and sensitive cart/order data.
- All new behavior requires unit or integration tests appropriate to its boundary.

## Definition of done

A task is not complete until:

- Its success and failure paths are tested.
- Store/website/customer isolation is verified where applicable.
- Guardrail impact is considered.
- Logs and errors contain no secrets or PII.
- Configuration has safe defaults.
- Magento coding standards and static analysis pass.
- Relevant documentation is updated.

Read the files under `docs/` before implementing a subsystem.
