# Target Architecture — Aavirbhava_AiShoppingAssistant

A reusable, Composer-installable Magento 2 module implementing a RAG-based
AI shopping assistant. Magento owns config, indexing, and commerce truth;
AI providers (LLM, embedding, reranker) are pluggable adapters behind
interfaces — independently swappable, not tied to one vendor.

## Module structure (target)

```
app/code/<Vendor>/AiShoppingAssistant/
├── Api/                      LlmProviderInterface, EmbeddingProviderInterface, etc.
├── Block/
├── Controller/
│   ├── Adminhtml/            Playground, Index Management, Conversations, Evaluation, Recommendation Rules
│   └── Chat/                 storefront chat endpoint(s)
├── Cron/
├── etc/
│   ├── adminhtml/{routes.xml, system.xml}
│   ├── frontend/routes.xml
│   ├── acl.xml, config.xml, crontab.xml, di.xml, events.xml,
│   │   indexer.xml, module.xml, queue_consumer.xml,
│   │   queue_publisher.xml, webapi.xml
├── Indexer/
├── Model/
│   ├── Chat/                 orchestration, generation service
│   ├── Config/
│   ├── Embedding/
│   ├── Llm/
│   ├── Provider/             {Llm,Embedding}/{OpenAi,Anthropic,...}Provider.php
│   ├── Ranking/               RankingSignalInterface + signal implementations
│   ├── Retrieval/            hybrid BM25 + vector retrieval
│   └── Tool/                 CommerceToolInterface + allowlisted tools
├── Observer/
├── Plugin/
├── Setup/
├── Ui/
├── view/{adminhtml,frontend}/
├── composer.json
└── registration.php
```

Installed via: `composer require .../magento2-ai-shopping-assistant` →
`module:enable` → `setup:upgrade` → `indexer:reindex ai_product_rag` →
`cache:flush`.

## Provider abstraction

```php
interface LlmProviderInterface {
    public function chat(ChatRequest $request): ChatResponse;
    public function testConnection(): ConnectionResult;
}
interface EmbeddingProviderInterface {
    public function embed(array $texts): array;
}
```

Implementations behind each: OpenAI, Anthropic/Claude, xAI/Grok,
OpenAI-compatible (Ollama/vLLM/llama.cpp/LM Studio/local). Chat provider and
embedding provider are independently chosen — e.g. Claude for chat + a
local BGE-M3 model for embeddings + a local reranker.

## Admin configuration (Stores > Configuration > AI Shopping Assistant)

1. **General** — enable module/assistant, name, welcome message,
   store-view instructions, languages, chat position, visibility, max
   turns, debug logging.
2. **LLM Provider** — provider select, encrypted API key
   (`Magento\Config\Model\Config\Backend\Encrypted`), model, base URL,
   timeout, max tokens, reasoning level, temperature, Test Connection
   button. Credentials never returned to frontend APIs.
3. **Fallback Provider** — enable, provider, local server URL/model,
   timeout, failure threshold, circuit-breaker cooldown. Fallback
   sequence: primary call → limited retry → circuit breaker → local
   provider → safe non-AI search response. Product search must keep
   working even if all LLM providers fail.
4. **Embeddings and Retrieval** — provider, model, dimensions, index name,
   enable keyword/vector retrieval, weights, candidate count, reranker,
   min relevance score, final product count, store-view isolation,
   customer-group awareness. Changing embedding model/dimensions must
   invalidate the index and require reindexing.
5. **Catalogue Indexing** — included product types/categories, excluded
   categories, included/searchable/filterable attributes, description
   embedding toggles, batch size, auto incremental indexing, schedule,
   last status, indexed/failed counts, reindex button.
6. **Assistant Capabilities** — per-feature toggles: product discovery,
   comparison, questions, stock/price checking, cart read/add/remove,
   policy/FAQ search, order assistance, require-confirmation-before-cart-
   changes.
7. **Marketing and Recommendations (Phase 2)** — merchandising rules,
   boost products/categories/brands, promote campaign products, exclude
   from AI recs, prefer in-stock/high-margin, new-arrival/bestseller/
   clearance boost, sponsored label, campaign dates, max promoted per
   response. The assistant must distinguish best semantic match vs.
   recommended vs. promoted/sponsored — a promoted product is never
   presented as objectively best unless it genuinely fits.

## Retrieval index

Dedicated OpenSearch index (not modifying core catalog search mapping),
target naming `magento_ai_products_<store_id>_<version>` (an alias +
run-token pattern is an acceptable, arguably superior, deviation). Document
fields: entity_id, sku, store_id, website_ids, name, categories, brand,
search_text, attributes, price_bucket, visibility, status, embedding,
popularity_score, promotion_score, updated_at.

**Price/stock/customer-group-price/visibility are deliberately excluded**
from the index — before any answer is shown, live Magento services must
revalidate price, special price, customer-group price, stock, salability,
variant availability, and website/store visibility. The index is for
retrieval/ranking only, never presentation of truth.

Custom indexer `ai_product_rag` supports standard
`indexer:{status,reindex,reset} ai_product_rag`. Admin buttons: test
provider, test embedding, reindex all, index changed products, clear
index, run sample search, view diagnostics.

## Async indexing

Observers on product save/delete/attribute changes/category
assignment/bulk import/visibility changes publish entity IDs to a queue —
**never generate embeddings synchronously during product save**. Consumer
loads the normalized product, computes a content hash, and only
regenerates the embedding if content changed, then updates OpenSearch.

## Runtime request pipeline (the safety-critical half)

```
Customer Message
  → Input Validation
  → Commerce Scope Classifier
      ├─ Out of scope → Fixed Safe Response (no LLM call)
      └─ Allowed → Retrieve Store Data
                     → Allowlisted Magento Tools
                     → Grounded LLM Response
                     → Output Validator
                          ├─ Invalid → Safe Search Fallback (non-AI)
                          └─ Valid   → Customer Response
```

The Commerce Scope Classifier should be a **deterministic, rule-based
first pass** (allowlist/blocklist pattern matching), not an LLM call —
every request, including malicious/out-of-scope ones, would otherwise burn
a real LLM call before any trust decision is made. It protects both API
spend and attack surface.

The Output Validator gates what reaches the customer — paired with the
structured response contract and live revalidation, so the LLM can never
have its raw output shown directly, and can never cause a fabricated
price/URL/stock claim to reach a customer.

## Fallback chain

Primary call → limited retry → circuit breaker → local/fallback provider →
non-AI safe search response. Must be resilient even if both LLM providers
fail entirely.

## Response contract

The backend never returns bare LLM prose. Structured JSON:

```json
{
  "message": "...",
  "products": [
    {"sku": "...", "reason": "...", "recommendation_type": "organic|recommended|promoted", "verified_at": "..."}
  ],
  "follow_up_questions": ["..."],
  "actions": [{"type": "compare", "skus": ["...", "..."]}],
  "metadata": {"provider": "...", "model": "...", "fallback_used": false}
}
```

Frontend hydrates product cards from SKU/entity_id via live Magento data —
the LLM never generates URLs, formatted prices, or image URLs itself.

## Extensible ranking pipeline

```php
interface RankingSignalInterface {
    public function apply(SearchContext $context, array $candidates): array;
}
```

Phase 1 signals: TextRelevanceSignal, VectorSimilaritySignal,
AttributeMatchSignal, AvailabilitySignal. Designed so Phase 2+ signals
(PromotionSignal, MarginSignal, PopularitySignal, PersonalizationSignal,
InventoryClearanceSignal, CampaignSignal) plug in without rewriting
retrieval.

## Admin diagnostic pages

Under Marketing > AI Shopping Assistant: **Playground** (query in, debug
panels for parsed intent/filters, BM25 results, vector results, combined
ranking, reranker scores, live-data validation, products sent to LLM, raw
tool calls, final response, tokens/cost/latency), **Index Management**,
**Conversations**, **Evaluation**, **Recommendation Rules**.

## Phase roadmap

| Phase | Scope |
|---|---|
| 1 | Module install, admin config, provider adapters, indexing, RAG search, comparison, product cards, runtime safety pipeline |
| 2 | Marketing rules, promoted products, campaign boosting, recommendations, analytics |
| 3 | Personalization (consented browsing/cart history), customer segments, multilingual queries |
| 4 | Order assistance, returns, support escalation, voice/image-based search |
| 5 | A/B testing, conversion attribution, automated ranking optimisation |

## Dependency chain (for sequencing)

LLM adapter → runtime pipeline (entry: input validation + scope
classifier + ChatGenerationService) → hybrid retrieval + ranking pipeline
→ Output Validator + response contract + live revalidation (build
together — the contract is meaningless without revalidation enforcing it)
→ fallback execution (retry/circuit-breaker wired around real calls) →
admin Playground/diagnostics (validates everything end to end) → Phase 2
marketing signals.
