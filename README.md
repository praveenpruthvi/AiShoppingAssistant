# Aavirbhava AI Shopping Assistant

A Magento 2 module for store-grounded conversational product search, comparison, recommendations, and controlled commerce assistance — a RAG (retrieval-augmented generation) chat assistant that answers only from a store's own real, live catalogue data.

## Status

Phase 1 of the project's roadmap is functionally complete and under active, live-tested hardening. The module is disabled by default and still has known, disclosed gaps (see `references/progress-log.md` and the reports under `docs/status-reports/`) — treat it as pre-production, not yet suitable for an unattended storefront.

The core pipeline is real and end-to-end: hybrid (BM25 + vector) retrieval against a dedicated OpenSearch index, live re-verification of every price/stock/URL fact against Magento itself before it ever reaches a customer, a bounded tool-calling loop over commerce tools, and a strict, validated JSON response contract. Every fix in the module's history has been driven by live testing against a real local Magento install with a real OpenSearch cluster and a real LLM provider (Ollama/OpenAI-compatible), not simulation.

## Features

- **Conversational product search** — natural-language queries answered from real, live-revalidated catalogue data; multi-turn conversation memory carries prior-turn context (including price constraints and previously shown products) into follow-up questions.
- **Nine commerce tools** — search products, get product details, compare products, check price, check inventory, search store content (CMS/blog), and cart operations (get/add/remove, with a confirmation gate on mutations).
- **Strict grounding** — the model is instructed, and the response is independently validated, to never invent a product, price, SKU, URL, stock status, or attribute; a response naming a real product but omitting it from the structured result is detected and corrected, not silently shipped incomplete.
- **Storefront widget** — a persistent chat panel for both default/Luma and Hyva themes, resizable, minimizable, with admin-configurable appearance (colors auto-contrast for readability), markdown-formatted replies, product cards with live images/prices, and a transcript that survives a page reload.
- **Admin configuration** — provider selection (OpenAI, Voyage, or any local OpenAI-compatible endpoint for embeddings; OpenAI or a local/Ollama-compatible endpoint for chat, with an automatic fallback provider), guardrails, retrieval tuning, and an Admin Playground for issuing a query and inspecting every stage of the pipeline (parsed intent, retrieval candidates, ranking signals, tool calls, and the final validated response).
- **Operational diagnostics** — an `aavirbhava:ai-shopping-assistant:index-coverage` CLI command comparing the real catalogue against the live OpenSearch index and listing any drift, plus a dedicated, always-on debug log (`var/log/aavirbhava_ai_shopping_assistant_chat.log`, isolated from `system.log`) tracing every real chat request: message, scope decision, retrieval candidates and scores, the live-availability filter's before/after counts, and the final response.
- **Asynchronous indexing** — a durable, queue-backed incremental indexer (no synchronous embedding calls on product save) plus a full-rebuild path with atomic alias activation, so the index never partially updates or blocks a storefront save.

## Compatibility target

- Magento Open Source / Adobe Commerce 2.4.7–2.4.9
- PHP 8.2–8.5, subject to the installed Magento release
- OpenSearch 2 or 3, subject to the installed Magento release
- Composer installation

## Package identity

- Composer: `aavirbhava/module-ai-shopping-assistant`
- Magento: `Aavirbhava_AiShoppingAssistant`
- Namespace: `Aavirbhava\AiShoppingAssistant`

## Development principles

- Magento is the source of truth for catalogue and transactional facts.
- Retrieval produces candidates; live Magento services verify final facts.
- LLM providers are interchangeable and untrusted.
- Customer-facing capabilities are deny-by-default and store-only.
- Indexing is asynchronous and isolated from product-save requests.
- Every fix is driven by real, reproduced behavior — a real debug-log trace or a real live request — not assumption.

See `AGENTS.md` and the files under `docs/` before contributing.

## Installation preview

The package is not published yet. Once available through a Composer repository:

```bash
composer require aavirbhava/module-ai-shopping-assistant
bin/magento module:enable Aavirbhava_AiShoppingAssistant
bin/magento setup:upgrade
bin/magento cache:flush
```

Keep the module disabled in Admin until provider, retrieval, and guardrail diagnostics pass, and until an initial `indexer:reindex ai_product_rag` has completed against the target catalogue.

## Diagnostics

```bash
# Compare the real catalogue against the live OpenSearch index
bin/magento aavirbhava:ai-shopping-assistant:index-coverage

# Trace every real chat request (message, retrieval, filters, final response)
tail -f var/log/aavirbhava_ai_shopping_assistant_chat.log
```

## Standalone validation

```bash
python3 tools/validate_structure.py
composer validate --strict
composer install
composer lint
composer test
```

Full Magento installation and integration checks will be added to a dedicated test environment.
