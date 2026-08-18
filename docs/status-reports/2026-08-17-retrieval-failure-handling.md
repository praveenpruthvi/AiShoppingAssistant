# STATUS REPORT — Retrieval-failure handling

Task 12 of the Aavirbhava_AiShoppingAssistant build sequence: fix the
retrieval-layer uncaught-exception gap flagged in Task 5's report and
reconfirmed live in Task 11 — an in-scope customer message that hits a
retrieval failure (OpenSearch unreachable/misconfigured, or the
query-embedding step failing) must return the existing safe, non-AI
fallback response, not propagate a raw PHP exception to the customer.

## Files created/changed

- `Model/Chat/ChatEntryPipeline.php` — wraps `ProductContextResolver::resolve()` in a `try`/`catch (ProductIndexingException | ProviderException)`; new `REASON_RETRIEVAL_UNAVAILABLE` constant; new `LoggerInterface` constructor dependency; new `logRetrievalFailure()` private helper.
- `Model/Chat/ProductContextResolver.php` — docblock only, updated to no longer describe this as an unhandled gap.
- `Test/Unit/Model/Chat/ChatEntryPipelineTest.php` — 3 new tests, plus the `pipeline()` test helper extended for the new `LoggerInterface` dependency.

## Conventions followed

- Reuses the exact `ChatPipelineResult::shortCircuit(new SafeResponse(...))` shape every other short-circuit path in this pipeline already uses — no second fallback mechanism invented.
- The catch scope follows `FallbackEligibilityPolicy`'s established "only catch what's actually eligible/expected" discipline: two specific sanitized exception taxonomies, not `\Throwable`.
- Logging follows `AddToCartTool`'s existing structured-context convention: a short fixed message plus a context array, `error` level, never raw customer text.
- The new `LoggerInterface` constructor parameter is a plain required dependency (not defaulted) since the only production caller is Magento's own DI container (auto-wires via its global `Psr\Log\LoggerInterface` preference, no di.xml change needed) and the only direct-construction caller is the test file, updated in this same task.

## Deviations from existing conventions

None. This is an additive fix within the pipeline's existing short-circuit pattern.

## Exception handling design

**Caught:** `ProductIndexingException` (the OpenSearch client/index-backend hierarchy — `SearchQueryFailedException`, `SearchResponseInvalidException`, `OpenSearchBackendUnavailableException`, `OpenSearchConfigurationInvalidException`, etc. — confirmed by inspection that `HybridRetrievalService::retrieve()`'s read path can only realistically throw these subclasses, never the indexer/writer-side ones used during a full reindex) and `ProviderException` (confirmed by inspection that `EmbeddingConfigurationException`/`EmbeddingResponseException`, thrown by the query-embedding step inside `retrieve()`, both already extend it — the identical hierarchy `ChatEntryPipeline` already catches for chat-generation failures at the `toolCallingChatService->converse()` call a few lines below).

**Not caught:** everything else. `RankingPipeline::rank()` throws nothing at request time — its only `InvalidArgumentException`s are constructor-time di.xml wiring checks (confirmed by inspection), never triggered by a real customer request. A genuine bug — a `TypeError`, an `InvalidArgumentException` from real misuse, anything outside the two taxonomies above — still propagates uncaught, exactly as intended: this fix targets known, expected failure modes, not a blanket safety net that would also mask real bugs.

**Reason code:** a new, distinct `retrieval_unavailable`, not a reuse of `assistant_unavailable`. The customer-facing *message* text is identical either way — both reuse `guardrails.outOfScopeMessage()`, keeping one consistent safe-fallback experience regardless of which backend failed. The *reason code* is kept distinct because this module already gives every distinct failure mode its own code (`off_topic_request`, `malformed_response`, `fabricated_sku`, `fabricated_price`, `assistant_disabled`, `assistant_unavailable`) specifically so logs/metrics/the admin Playground can tell failure modes apart — collapsing an OpenSearch/embedding-provider outage into the same code as an LLM-provider outage would make that diagnostic value worse for no benefit to the customer experience, which is unaffected either way.

**Logging:** the underlying exception's class, sanitized `errorCode()`, and message are logged at `error` level alongside `store_id` — never the raw customer message text — so an admin can see exactly what failed and when, even though the customer only ever sees the generic safe message.

## Container verification

`php -l`, `setup:upgrade`, `setup:di:compile` (confirms `LoggerInterface` auto-wires into the new constructor parameter with zero di.xml change needed), `cache:flush` all clean. Full suite: **1207 tests / 2943 assertions / 0 failures** (up from 1204/2936).

**Reproduced Task 11's exact finding, live, and confirmed it's fixed:** with the assistant temporarily enabled, a real `curl -X POST` to `/aichat/chat/send` with an in-scope message ("Show me some duffle bags") against this environment's actual unconfigured-OpenSearch state — which previously returned a raw PHP exception stack trace through the real endpoint (Task 11's own finding) — now returns a real HTTP 200 JSON response:

```json
{"message":"I can help you search, compare, and learn about products and services available on this store. What are you looking for?","reason_code":"retrieval_unavailable","products":[],"follow_up_questions":[],"actions":[],"metadata":null,"awaiting_confirmation":false}
```

The real `var/log/system.log` shows the matching structured error-level entry for the same request:

```
[2026-08-16T18:31:11.986576+00:00] main.ERROR: AI shopping assistant: retrieval/ranking failed, returning a safe fallback response. {"store_id":1,"exception_class":"Aavirbhava\\AiShoppingAssistant\\Model\\Indexing\\Exception\\SearchQueryFailedException","error_code":"search_query_failed","exception":"The AI shopping assistant search could not be completed."} []
```

confirming ops visibility is preserved even though the customer never sees it.

**No regression:** a second real request with an out-of-scope message ("What is the weather like today?") still returns its own distinct `off_topic_request` reason code, completely unchanged from before this fix — the already-working short-circuit path is unaffected. A successful, generated-response path still cannot be live-verified end-to-end through the real HTTP endpoint in this environment, for the same pre-existing no-OpenSearch-index reason every task since Task 9 has documented — this task doesn't change that, and doesn't need to (it targets exactly the failure path, which is now provably reachable and provably safe).

Config (`general.enabled`) was reverted to its original value and reconfirmed afterward.

## Test results

1204 → 1207 tests (+3), 2936 → 2943 assertions (+7), 0 failures. New tests in `ChatEntryPipelineTest`: a `ProductIndexingException` (via `SearchQueryFailedException`) short-circuits correctly and never reaches `toolCallingChatService->converse()`; a `ProviderException` (via `EmbeddingConfigurationException`, simulating the query-embedding failure path) short-circuits correctly; the failure is logged with the correct `store_id`/sanitized `error_code` and the raw exception never propagates.

## Known gaps / TODOs left for later tasks

None newly introduced by this task. Pre-existing residual gaps, unaffected and carried forward: free-text price-fabrication detection's regex limits (Task 5); no periodic cron sweep for abandoned conversation rows (Task 8); no `Test/Integration/` DB test for `CmsPageContentSearcher`/`ProductContentSearcher` (Task 10); the Hyva chat widget template unverified against a real theme, and no JS test/browser-automation tooling for the widget (both Task 11).

## Skill files updated

- `references/progress-log.md` — status row 6 updated to note the fix; full Task 12 history entry added; "Next up" updated back to listing the (unchanged) residual gaps plus Phase 2 as the next open decision, no longer listing retrieval-failure handling as a gap.

## Not done / blocked

Nothing blocked. This task's scope was exactly the retrieval-failure gap; it does not attempt to fix the underlying missing-OpenSearch-index/embedding-provider configuration in this dev environment (that's an environment-setup matter, not a code defect) — it only ensures that condition now degrades gracefully instead of crashing.
