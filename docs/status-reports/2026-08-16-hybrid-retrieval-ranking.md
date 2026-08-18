# STATUS REPORT — Hybrid retrieval + ranking pipeline

Task 3 of the Aavirbhava_AiShoppingAssistant build sequence: hybrid
(BM25 + vector) retrieval against the existing OpenSearch index, the
extensible `RankingSignalInterface` pipeline with the four Phase-1
signals, and thin integration into `ChatEntryPipeline`.

## Files created/changed

**Search client (extended, not duplicated):**
- `Api/Indexing/AssistantSearchClientInterface.php` — added `search(string $indexName, array $queryBody): array`; the interface previously had no read/query capability at all, only write-lifecycle methods.
- `Model/Indexing/Client/OpenSearchAssistantClient.php` — implemented `search()`: executes the query, verifies the response shape (`hits.hits` list, each hit has `_id`/`_score`/`_source`), returns raw verified hits.
- `Model/Indexing/Client/UnavailableAssistantSearchClient.php` — `search()` throws the fail-closed `OpenSearchBackendUnavailableException`, matching every other method.
- `Test/Unit/Fake/FakeAssistantSearchClient.php` — added a queued `search()` implementation so the shared test fake stays a valid interface implementer.

**Exceptions (extended the existing taxonomy):**
- `Model/Indexing/Exception/ProductIndexingException.php` — added `ERROR_SEARCH_QUERY_FAILED`/`ERROR_SEARCH_RESPONSE_INVALID` constants.
- `Model/Indexing/Exception/SearchQueryFailedException.php`, `SearchResponseInvalidException.php` — new, mirror the existing `BulkIndexFailedException`/`BulkResponseInvalidException` pair shape.

**Retrieval (new):**
- `Model/Retrieval/SearchCandidate.php` — immutable product candidate (index data only — no price/stock/customer-group data, none exists in the index); `withScore()` wither for the ranking pipeline.
- `Model/Retrieval/SearchQueryBuilder.php` — builds the BM25 (`multi_match` + nested `categories`/`attributes`) and k-NN (Lucene-engine efficient pre-filter) query bodies against the real `ProductIndexMappingInterface` field names.
- `Model/Retrieval/SearchHitParser.php` — parses a verified `_source` into a `SearchCandidate`, failing closed on malformed identity fields.
- `Api/Retrieval/HybridRetrievalServiceInterface.php` / `Model/Retrieval/HybridRetrievalService.php` — store-scoped orchestration: resolves the read alias, runs both queries, embeds the query text via `EmbeddingGenerationServiceInterface` (query type, not document), merges/dedupes by entity id, caps at `merged_candidates`.

**Ranking (new):**
- `Model/Ranking/SearchContext.php` — immutable context (storeId, queryText, rerankerRequested).
- `Api/Ranking/RankingSignalInterface.php` — `apply(SearchContext, array $candidates): array`.
- `Model/Ranking/Signal/{TextRelevanceSignal,VectorSimilaritySignal,AttributeMatchSignal,AvailabilitySignal}.php` — the four Phase-1 signals.
- `Api/Ranking/RankingPipelineInterface.php` / `Model/Ranking/RankingPipeline.php` — runs candidates through every registered signal, sorts, caps at `final_products`.

**Chat integration (thin):**
- `Model/Chat/ProductContextResolver.php` — composes retrieval → ranking.
- `Model/Chat/ProductContextFormatter.php` — formats ranked candidates into a `system` `ChatMessage`.
- `Model/Chat/ChatEntryPipeline.php` — modified: retrieval+ranking now run for in-scope messages, product context (if any) is prepended before the user message.

**Wiring:**
- `etc/di.xml` — new preferences for `HybridRetrievalServiceInterface`/`RankingPipelineInterface`; `RankingPipeline`'s `signals` array registration.

**Tests:** 66 new tests across 13 new/updated test files (`OpenSearchAssistantClientTest` +9, `Retrieval/*` 22, `Ranking/*` 28, `Chat/*` +7).

## Conventions followed

- `HybridRetrievalService` mirrors `EmbeddingGenerationService`/`ChatGenerationService`'s shape exactly: `requireActive()` first, then store-scoped config reads, never writes.
- Query embedding goes through `EmbeddingGenerationServiceInterface` (never a direct provider call), using `EmbeddingInputType::query()` — the same asymmetric document/query distinction Task-1-era embedding adapters (Voyage) already support.
- `SearchQueryBuilder`/`SearchHitParser` use `ProductIndexMappingInterface::FIELD_*` constants exclusively — no magic field-name strings, matching how `IndexedDocumentPayloadBuilder` already builds documents.
- BM25/k-NN queries filter on `store_id`/`is_enabled` even though the store-scoped alias and index-time eligibility policy already guarantee this — belt-and-suspenders, the same redundant-validation convention `AbstractEmbeddingProvider` already uses.
- Exception handling stays on the existing `ProductIndexingException` taxonomy — no parallel hierarchy, continuing the discipline from Tasks 1–2.
- Ranking signal registration mirrors `LlmProviderRegistry`/`EmbeddingProviderRegistry`'s **array-construction mechanism** in di.xml (third-party extensibility via `<item>`), explicitly adapted since the *semantics* differ (see Ranking pipeline design below).
- Test style throughout mirrors `EmbeddingGenerationServiceTest`/`ChatGenerationServiceTest`: mock the collaborator interfaces, use real instances for pure/cheap helper classes (`SearchQueryBuilder`, `SearchHitParser` used as real objects inside `HybridRetrievalServiceTest`, same as `ChatEntryPipelineTest` already used a real `ChatInputValidator`).

## Deviations from existing conventions

1. **Extended `AssistantSearchClientInterface` with a genuinely new capability** (`search()`) rather than a config/DTO field, because the interface literally had no read path at all — every existing method is write-lifecycle. Verified there are exactly three implementers (production, unavailable fallback, test fake) and updated all three.
2. **`SearchCandidate::withScore()` is a new pattern for this codebase** — every other DTO is constructed once and never touched again. It's still fully immutable (returns a new instance), but it's the first "wither" method in the module. Necessary because `RankingSignalInterface::apply()`'s signature (`array $candidates -> array`) requires each pipeline stage to hand back a transformed candidate list, and candidates otherwise have no mutable slot for an accumulating score.
3. **Ranking signal DI registration mirrors the registries' array mechanism but not their semantics.** `LlmProviderRegistry`/`EmbeddingProviderRegistry` are identifier-keyed allowlists where exactly *one* registered item is selected per store via merchant config. `RankingPipeline` has no such selection — every registered signal runs, every time, in registration order. Built it as a validated ordered list instead of a `has()`/`get()` registry, and documented the distinction explicitly in both `etc/di.xml`'s comment and `RankingPipeline`'s docblock so a future reader doesn't assume registry semantics that aren't there.
4. **Did not build a `ChatEndpointPolicy`-style abstraction for query building** — `SearchQueryBuilder` builds both BM25 and k-NN bodies as plain methods on one class rather than splitting into a strategy pattern, since there's exactly one retrieval strategy (unlike the provider-adapter case where multiple vendors genuinely need swappable behavior).
5. **The task prompt's assumptions about existing config and index fields were partly wrong; I verified rather than trusted them, per the audit discipline this project already established:**
   - No `keyword_weight`/`vector_weight` config exists in `system.xml`/`RetrievalConfigInterface` (only `keyword_candidates`/`vector_candidates`/`merged_candidates`/`final_products`/`reranker_enabled`). Used a documented fixed normalized-score-sum for merge-capping only — not invented as new config, per the task's own "note gaps, don't invent config" instruction.
   - No customer-group-aware retrieval config or index field exists anywhere. Flagged as a gap, not invented (see Known gaps below).
   - The task prompt's claimed index field list (`entity_id, sku, store_id, categories, brand, search_text, attributes, price_bucket, visibility, status, embedding, popularity_score, promotion_score`) doesn't match the real mapping. Real fields (from `ProductIndexMappingInterface`) are `entity_id, sku, store_id, website_ids, product_type, name, short_description, long_description, is_enabled, visibility, categories (nested), attributes (nested), searchable_text, embedding, embedding_hash, embedding_fingerprint, embedding_content_hash, complete_document_hash, updated_at, indexed_at`. No `brand`, `price_bucket`, `popularity_score`, or `promotion_score` field exists — `AttributeMatchSignal` works against `categories`/`attributes` (whatever a store's `searchable_attribute_codes` configures, e.g. `brand` as an attribute code) instead of a dedicated `brand` field, and `AvailabilitySignal` uses the real `is_enabled`/`visibility` fields instead of a nonexistent `status` field.

## Retrieval design

**BM25 query** (`SearchQueryBuilder::keyword()`): a `bool` query with `filter: [store_id term, is_enabled term]` and `should` clauses — a boosted `multi_match` across `name^3`, `short_description`, `long_description`, `searchable_text^2`, plus `nested` queries against `categories.name` and `attributes.values` (required because those are `nested`-type fields; a plain field-path match wouldn't work). `minimum_should_match: 1`, `size` from `keyword_candidates`.

**Vector query** (`SearchQueryBuilder::vector()`): an approximate k-NN query on the `embedding` field using OpenSearch's Lucene-engine efficient pre-filter syntax (2.9+) — `filter` nested *inside* the `knn` clause rather than wrapped around it, which is the correct/performant shape for this OpenSearch version rather than a post-filter over the whole candidate set. `k` and `size` both come from `vector_candidates`.

**Merge/dedup** (`HybridRetrievalService::merge()`): both hit sets are combined into one map keyed by `entity_id`; a candidate present in both carries both its real `bm25Score` and `vectorScore`, a candidate present in only one carries `0.0` for the other. The union is ordered by a simple normalized-score-sum (`bm25/maxBm25 + vector/maxVector`) *purely to decide what to keep* when capping at `merged_candidates` — this is not the final ranking, which is entirely `RankingPipeline`'s job over the full, uncapped-by-merge-order candidate data.

**Store-view isolation:** applied twice — implicitly by querying the store's own read alias (`<prefix>_store_<storeId>_current`, each store has a physically separate index), and explicitly via the `store_id` term filter in both queries, as defense-in-depth matching this codebase's established redundant-validation style.

**Customer-group awareness:** **not applied — no config or index field exists for it anywhere in the codebase.** Per the task's explicit instruction to note this as a gap rather than invent new config, this is left for a later task. It arguably belongs with live revalidation (Task 4), since customer-group pricing is entirely a live-Magento-data concern already excluded from the index by design, not a retrieval-time concern.

## Ranking pipeline design

Signals compose by each returning a new candidate list with an updated `score` (via `SearchCandidate::withScore()`), threaded through in the exact order `RankingPipeline` iterates its injected `signals` array:

1. `TextRelevanceSignal` — adds `bm25Score / (bm25Score + 1)` (bounds an unbounded BM25 score into `[0, 1)`).
2. `VectorSimilaritySignal` — adds `vectorScore` directly, clamped to `[0, 1]` (OpenSearch's `cosinesimil` space already returns a score in that range).
3. `AttributeMatchSignal` — tokenizes the raw query text, boosts by `min(0.15 × overlapping-token-count, 0.5)` against the candidate's category names and attribute values.
4. `AvailabilitySignal` — zeroes the score for any candidate that isn't `is_enabled` and search-visible, registered *last* so it's the authoritative gate regardless of what upstream signals scored.

`RankingPipeline` then sorts descending by final score (ties broken by ascending `entity_id` for determinism) and slices to `final_products`.

**DI registration** (`etc/di.xml`): a plain array of `<item name="..." xsi:type="object">SignalClass</item>` entries, injected into `RankingPipeline`'s `signals` constructor argument — the exact array-construction mechanism `LlmProviderRegistry`/`EmbeddingProviderRegistry` use for third-party extensibility. **Confirmed extensible without touching existing classes**: `RankingPipelineTest::testExtraSignalRunsWithoutAnyChangeToExistingSignalsOrThisClass` adds a stand-in "Phase 2" signal purely via the constructor array and proves it runs correctly, with zero changes to `RankingPipeline` or any of the four real signals. A real Phase 2 signal (e.g. `PromotionSignal`) would need only a new class implementing `RankingSignalInterface` plus one new `<item>` in `etc/di.xml`.

One deliberate difference from the provider registries: `RankingPipeline` has no `has($identifier)`/`get($identifier)` lookup, because nothing ever selects *one* signal — every registered signal always runs. The `<item name="...">` keys exist for readability/future Admin Playground diagnostics, not for runtime lookup.

## Container verification

- `bin/cli php -l` on all ~26 new/changed files — clean.
- `bin/magento setup:upgrade` — succeeded.
- `bin/magento setup:di:compile` — succeeded, validating the complete new DI graph (`ChatEntryPipeline → ProductContextResolver → HybridRetrievalService/RankingPipeline → RankingPipeline's 4 injected signals`).
- `bin/magento cache:flush`, `module:status` (enabled), `tools/validate_structure.py` (valid), `git diff --check` (clean).
- **Live retrieval check** against the real OpenSearch 2.12 cluster (via a throwaway bootstrap script, cleaned up after): created a temporary index, wrote 4 synthetic documents (a waterproof jacket, a running shoe, an umbrella with rain-related text, and a **disabled** duplicate jacket), then ran the real `SearchQueryBuilder` + `AssistantSearchClientInterface::search()` + `SearchHitParser` for the query *"waterproof jacket for rain"*:
  - **BM25 results (non-empty):** `JACKET-WP` (score 3.844), `UMBRELLA-01` (score 0.701) — the running shoe correctly did not match; the disabled jacket correctly did not appear (query-time `is_enabled` filter).
  - **Vector results (non-empty):** `JACKET-WP` (1.000, exact), `UMBRELLA-01` (0.997), `SHOE-RUN` (0.500) — matches the expected `cosinesimil` scoring formula exactly for the hand-picked test vectors.
  - **Final ranked list**, through the real DI-resolved `RankingPipeline` (actual four signals): `JACKET-WP` (1.794) → `UMBRELLA-01` (1.409) → `SHOE-RUN` (0.500) — sensible ordering, disabled product excluded throughout.
  - All 5 automated pass/fail checks in the script (disabled-excluded, jacket-present, jacket-ranks-first, BM25-non-empty, vector-non-empty): **PASS**.
- **Real bug found during this live check** (see Known gaps): the pre-existing production `ProductIndexMapping.php` fails to create a real index on this OpenSearch version at all. Confirmed by reproducing its exact `createBody()` output directly against the live cluster before correcting the mapping for this task's own verification index.
- Did not exercise a live embedding provider for the query-embedding call (`HybridRetrievalService::embedQuery()`) — none is configured in this environment, consistent with every prior task in this project. That call path is covered by mocked unit tests instead (`HybridRetrievalServiceTest::testEmbedsQueryTextUsingQueryInputTypeNotDocument`).

## Test results

- New tests alone: 66 tests, across `OpenSearchAssistantClientTest` (+9 `search()` tests), `Test/Unit/Model/Retrieval/*` (22), `Test/Unit/Model/Ranking/*` (28), `Test/Unit/Model/Chat/*` (+7: `ProductContextResolverTest` 2, `ProductContextFormatterTest` 4, `ChatEntryPipelineTest` +1 net).
- Full module suite: **899 tests, 2248 assertions, 0 failures** — up from 833/2111, zero regressions.
- No test-runner compatibility issues this time (Task 2's `#[DataProvider]` lesson was applied from the start — no data providers used anywhere in this task's new tests).

## Known gaps / TODOs left for later tasks

Explicitly confirmed **not** built in this task:
- **Reranking invocation** — `reranker_enabled` is read (into `SearchContext::rerankerRequested`) so a later task can consume the merchant's configured intent, but nothing calls a reranker. No reranker exists.
- **Live revalidation** — `SearchCandidate` carries index data only; nothing re-checks price/stock/salability/visibility against live Magento before a candidate would reach a customer.
- **Structured response contract / Output Validator** — `ChatGenerationService`'s output shape is unchanged; it just has product context available to reason over now.
- **Phase 2 ranking signals** (Promotion, Margin, Popularity, Personalization, Clearance, Campaign) — not built. The index doesn't even have `popularity_score`/`promotion_score` fields yet, so these would need index-schema work too, not just a new signal class.
- **Customer-group-aware retrieval filtering** — no config or index field exists for this anywhere; flagged, not invented.
- **Fallback execution, tool-calling, admin UI** — unchanged from Task 2's status, still not built.

**New finding, not previously known:** the live-blocking `ProductIndexMapping.php` `space_type` bug (see Container verification and `references/progress-log.md` area 4). This isn't a Task-3 gap — it's a pre-existing defect in earlier indexing-milestone code that had never been exercised against a real cluster with the assistant enabled. Recommend a short, narrowly-scoped follow-up fix before or alongside Task 4, since Task 4's live-revalidation work will eventually need real indexed data to revalidate against.

## Skill files updated

- `references/progress-log.md` — updated in place: status table rows for areas 4 (flagged the mapping bug), 6 (retrieval now wired), 10 (done); added the full Task 3 history entry; updated "Next up" to point at Task 4 (Output Validator + response contract + live revalidation) with a note about the mapping bug as a possible pre-Task-4 follow-up.
- This file: `docs/status-reports/2026-08-16-hybrid-retrieval-ranking.md` (`docs/status-reports/` did not exist before this task — created it).

## Not done / blocked

Nothing was left incomplete relative to this task's scope. The one open item (`ProductIndexMapping.php`'s live-blocking bug) is deliberately **not fixed here** because it sits outside Task 3's boundary (retrieval/ranking, not index-write mapping) — flagged prominently instead, per this project's scope discipline, rather than silently expanding this task to cover it.
