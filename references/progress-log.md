# Progress Log — Aavirbhava_AiShoppingAssistant

Last updated from: Attribute indexing coverage task status report (2026-08-19) — fixed a real, confirmed gap: the admin `indexing/searchable_attribute_codes` config's shipped default (`manufacturer,color,size,material`) never included `climate`/`pattern`/`style_general`/`style_bottom`/`activity`/`collar`/`sleeve` — real, comprehensively-populated PDP attributes confirmed via direct SQL against the catalog's own EAV tables (climate/pattern/material each populated on all 147 configurable products catalog-wide; style attributes together cover effectively the whole catalog, split by attribute set) and confirmed missing from a real OpenSearch document fetched directly for MH08 (Oslo Trek Hoodie) before the fix — only `material` was indexed even though `climate`/`pattern` were just as real and just as populated. Root-caused to the admin config list specifically, not the normalizer (read directly — it has no hardcoded attribute subset, it normalizes whatever the config-driven resolver hands it) and not inconsistent underlying Magento data (confirmed the opposite: comprehensively populated, just never configured to be captured). Fixed by broadening both the module's shipped default and this environment's own already-stored config override (found via direct SQL — a real value in `core_config_data` was silently taking precedence over the XML default) to the full attribute list, then running a real `indexer:reindex ai_product_rag`. Live-confirmed via a direct post-reindex OpenSearch query and via the real chat pipeline: "what climate are the mens hoodies suited for" now returns a rich, fully grounded answer using real Climate option values, and coverage was spot-checked generalizing to yoga pants and Gear/Bag-category SKUs too, not just the one reported hoodie case. Empty-products fix (Task 29) and follow-up-chip voice (Task 28) remain in place and unaffected. Phase 1 (per architecture.md's roadmap table) remains functionally complete as of Task 11.
Environment: local Magento via docker-magento (markshust/docker-magento).

## Status by architecture area

| # | Area | Status |
|---|---|---|
| 1 | Module structure | Partially implemented — **`Controller/Adminhtml/Playground/{Index,TestConnection}.php`, `Block/Adminhtml/Playground/Index.php`, `etc/adminhtml/{routes.xml,menu.xml}`, `view/adminhtml/{layout,templates}` now exist (Task 9), the module's first admin Controller/Block/layout/template files**; storefront `Controller/Chat/Send.php` + `etc/frontend/routes.xml` (Task 8) plus **`Block/Frontend/ChatWidget.php` + `view/frontend/{layout,templates,web/js}` (Task 11), the module's first frontend Block/layout/template/static-asset files**. **A second storefront Controller now exists, `Controller/Chat/History.php` (Task 19)** — a read-only `GET /aichat/chat/history`, the module's first GET-only (no CSRF interface needed) storefront action. Still no Cron/Ui top-level dirs; cron/observer/plugin classes live under Model/Indexing/* instead (layout deviation, not functional) |
| 2 | Provider abstraction | Embedding: done (OpenAI, Voyage, local-compatible — 3 real adapters). LLM: **OpenAI adapter (Task 1) plus, as of Task 13, `OpenAiCompatibleProvider`** — a generic local-server adapter (Ollama, vLLM, llama.cpp, LM Studio, per architecture.md's original scope) speaking the same OpenAI chat/completions wire format, configurable base URL, optional API key, selectable as either the primary or fallback LLM provider through the existing shared `Model\Config\Source\Provider` dropdown. `Model\Provider\Llm\AbstractChatProvider`/`ChatEndpointPolicy` now exist too (extracted from `OpenAiProvider`, mirroring the embedding side's `AbstractEmbeddingProvider`/`ProviderEndpointPolicy` split, deferred since Task 1 specifically until a second chat adapter existed to justify it) — both providers now share request/response handling, differing only in endpoint-resolution policy, header-building, and the max-output-tokens field name (`max_completion_tokens` vs. Ollama's `max_tokens`). **The admin dropdown label is now "Local / Ollama (OpenAI-Compatible)" (Task 14)**, clearer than Task 13's plain "OpenAI-Compatible" — the underlying `openai_compatible` identifier is unchanged. **A real "Fetch Ollama Models" admin action now exists (Task 14)** — `Controller/Adminhtml/System/Config/FetchOllamaModels.php` + `Model/Provider/Llm/OllamaModelListService.php` call Ollama's own native `GET /api/tags` and populate an HTML5 `<datalist>` bound to the Model field via a small `frontend_model` block (`Block/Adminhtml/System/Config/OllamaModelField`), live-verified against real pulled models. Anthropic/xAI still not implemented |
| 3 | Admin config sections | Partially implemented — system.xml has general/llm/fallback/embedding/retrieval/guardrails/capabilities/indexing. The "Assistant Capabilities" group (Task 6) gates the 5 read-only tools **plus, as of Task 10, `policy_search_enabled` gating search_store_content**; `guardrails` (Task 7) has `require_cart_confirmation` alongside `cart_mutations_enabled`, gating all 3 cart tools. **`general` (Task 8) now also has `max_conversation_messages`** (default 40, bounds 2-200) — architecture.md's "max turns" field, implemented as a message count rather than a turn count since a single customer-visible turn can span several persisted messages via the tool-call round-trip. **Test Connection is now wired (Task 9)** — `Controller/Adminhtml/Playground/TestConnection.php`, a small AJAX action reusing the exact `ConfiguredProviderResolverInterface`/`ConfigurationReaderInterface`/`SecretReaderInterface` path a real chat call uses, calling `LlmProviderInterface::testConnection()` (built Task 1, never wired until now). **An `appearance` group was added in Task 21 (window/header + message-bubble colors); Task 22 upgraded all 3 fields from plain text to a real color-picker (`frontend_model`, Magento's own shipped `jquery/colorpicker/js/colorpicker`)** and made every field's effective value auto-compute a readable pairing when left unset rather than falling back to a fixed default. Still no Marketing/Recommendations Phase-2 stub |
| 4 | Custom OpenSearch index | Done — **the Task 3 `space_type` bug is now fixed and live-verified (Task 4)**: `Model/Indexing/Mapping/ProductIndexMapping.php` no longer sets a field-level `space_type` alongside `method.space_type`; a real index was created against the live OpenSearch 2.12 cluster using the actual production `createBody()` output and confirmed successful. Naming (alias + run-token) fine; `ai_product_rag` indexer registered, standard bin/magento indexer commands work. **A second, real bulk-write-blocking bug is now fixed (Task 15)**: `ProductDocumentNormalizer` passed `ProductSnapshotInterface::updatedAt()` — Magento's raw MySQL `Y-m-d H:i:s` string — straight through into the `updated_at` field, which `ProductIndexMapping` declares as OpenSearch `date` type requiring strict ISO-8601; every real bulk write failed (`mapper_parsing_exception`) the moment a real embedding provider and real catalog data were present to reach that code path, which no prior task's environment ever had at the same time. A real `indexer:reindex ai_product_rag` against this store's actual catalog now succeeds and produces real, queryable OpenSearch documents with real 768-dimension embeddings. **A new `aavirbhava:ai-shopping-assistant:index-coverage` console command (Task 24)** diagnoses index/catalog drift directly: `Model/Diagnostics/CatalogSkuProvider` (real salable/visible/enabled catalog SKUs, via the standard `CatalogInventory\Helper\Stock::addIsInStockFilterToCollection()` listing filters) vs. `Model/Diagnostics/IndexedSkuProvider` (a plain match-all query, capped at 10000 documents, against the store's live read alias — not is_enabled-filtered, since every indexed document already passed `ProductIndexEligibilityPolicy`'s gate at index time), composed by `IndexCoverageChecker` into a two-way SKU diff the command prints per store (or `--store-id=<id>` for one). Deliberately simple/fast — a diagnostic, not a reconciliation tool, and it takes no repair action of its own. Live-run against this store's real catalog: 181 salable/visible/enabled products, 181 indexed documents, 0 missing either direction — fully covered, no drift found. **A real attribute-indexing coverage gap is now fixed (Task 30)**: `indexing/searchable_attribute_codes`' shipped default (`manufacturer,color,size,material`) never included `climate`/`pattern`/`style_general`/`style_bottom`/`activity`/`collar`/`sleeve` — real, genuinely populated PDP attributes confirmed via direct SQL against the catalog's EAV tables (climate/pattern/material each on 98/98 "Top"-set and 147/147 catalog-wide configurable products; style_general/style_bottom together cover effectively the whole catalog, split by attribute set) and confirmed absent from a real OpenSearch document for MH08 (Oslo Trek Hoodie) fetched directly before the fix — only `material` was indexed, `climate`/`pattern` were not, despite both being 100%-populated real data. Root-caused to the admin config list alone, not the normalizer (read directly, confirmed it has no hardcoded attribute subset of its own — it normalizes whatever the resolver, driven entirely by config, hands it) and not inconsistent underlying Magento data (the opposite: comprehensively populated, just never configured to be captured). Fixed by broadening both the module's shipped default (`etc/config.xml`) and this environment's own already-stored `core_config_data` override (found via direct SQL — the live effective value, taking precedence over the XML default) to the full attribute list, via the standard `bin/magento config:set`, then a real `indexer:reindex ai_product_rag`. Live-confirmed via a direct post-reindex OpenSearch query that MH08's document now carries `climate`/`material`/`pattern`, and via the real chat pipeline that a genuinely single-turn "what climate are the mens hoodies suited for" now returns a rich, fully grounded answer using real Climate option values (All-Weather, Cool, Spring, Windy, Mild, Indoor, Cold, Wintry) — plus spot-checked coverage generalizing to yoga pants (Bottom set) and even Gear/Bag-category SKUs, not just the one reported hoodie case |
| 5 | Async indexing | Done, exceeds spec — durable DB ledger + queue + content-hash re-embed, no sync embedding on save, full-rebuild fenced against concurrent incremental writes |
| 6 | Runtime request pipeline | Full pipeline wired end-to-end: input validation → scope classifier → general.enabled/store-scope gates → hybrid retrieval + ranking → live revalidation of ranked candidates → **prior conversation history loaded and threaded in (Task 8)** → `ToolCallingChatService` (offers the store's allowlisted, capability-enabled tools, runs the tool-call round-trip up to `guardrails.max_tool_calls` rounds, now with a real `cartId`) → Output Validator against the revalidated set merged with whatever tools verified mid-conversation → structured response contract or safe fallback → **on success only, this turn's exchange is persisted for the next turn (Task 8)**. A provider failure (primary and fallback both exhausted) does not propagate uncaught. All 8 tools from architecture.md's original list are built — search_products, get_product_details, compare_products, check_price, check_inventory, get_cart, add_to_cart, remove_from_cart — **plus a 9th, `search_store_content` (Task 10)**, a keyword-only unified CMS/blog/product search distinct from search_products' semantic retrieval. **A real Controller endpoint now exists (`POST /aichat/chat/send`) resolving real session-backed identity (conversation id, customer group, cart) — the cart-id/session gap flagged since Task 3/7 is now closed**; all 9 tools, including the Task 7 cart-mutation confirmation gate, are reachable end-to-end by a genuine multi-turn customer conversation, not just direct construction. Order-assistance tools from architecture.md's broader "Assistant Capabilities" sketch remain unbuilt — architecture.md's own Phase roadmap table lists "Order assistance, returns, support escalation, voice/image-based search" under Phase 4, resolving the ambiguity Task 9's report flagged; this was never truly undecided Phase-1 scope. **A real storefront widget now calls this endpoint (Task 11)** — the pipeline is no longer only reachable by a real customer conversation in principle (Task 8), but by an actual clickable UI a shopper uses. **A retrieval/ranking failure (a `ProductIndexingException`/`ProviderException` from `HybridRetrievalService`/its query-embedding step) now also short-circuits to the same safe-response shape instead of propagating raw (Task 12)** — closing the one gap Task 11's own live check surfaced (an unconfigured-OpenSearch environment previously produced a raw PHP error page through the real endpoint); reason code `retrieval_unavailable`, kept distinct from `assistant_unavailable` so ops can tell the two backends' outages apart in logs. **`add_to_cart` now handles configurable products (Task 16)**: a call with no `option_selection` returns `needs_options` listing the real required attributes (Size/Color/etc.) and their real available values; a call with free-text `option_selection` (e.g. "M, gray", tolerant of extra words like "pink one") is matched case-insensitively against the product's real attribute/value labels, resolved to the one real, salable child variant, and only then added — via the real Magento cart-item `configurable_item_options` mechanism (parent SKU + attribute-id/value-index pairs, not the child SKU directly, since a configurable child is legitimately not individually visible and `LiveRevalidationServiceInterface`'s visibility gate would incorrectly reject it). An unmatched or ambiguous phrase, an incomplete selection, or a real combination that isn't currently salable never mutates the cart — each returns a distinct, honest status instead of guessing. **A real, live-confirmed bug in `get_cart`'s tool schema is now fixed (Task 17)**: `'properties' => []` json_encode()s as a JSON array, invalid for JSON Schema's `properties` keyword (must be an object, even empty) — OpenAI's real API tolerates it, a real Ollama instance does not, rejecting the *entire* chat request with HTTP 400 the moment `get_cart` is offered as a tool (i.e. whenever `cart_mutations_enabled` is on), which surfaced as every real chat message silently falling back to `assistant_unavailable`. Fixed by returning `new \stdClass()` instead (always encodes as `{}`), plus the same fix in `AbstractChatProvider::buildTool()`'s own empty-parameters fallback default for consistency. **A conversation's visible transcript now survives a page reload or a new tab (Task 19)**: a new `Model/Chat/ConversationHistoryViewBuilder` filters `ConversationHistoryStoreInterface::recentMessages()`'s raw, LLM-context-shaped list (which also carries intermediate tool-call-request/tool-result plumbing) down to exactly what a customer actually saw — their own messages plus the final assistant text of each turn — for `Controller/Chat/History.php` to serve. Deliberately reads `ChatSession::getConversationId()` directly rather than through `ChatIdentityResolverInterface::resolve()` (Task 8), since resolve() allocates a fresh conversation id and may auto-vivify a guest quote as a side effect — neither of which a passive per-page-load "anything to restore" check should ever trigger for a visitor who has never opened the widget. Does not restore structured product cards/follow-ups/confirmation state for past turns — only the final response text is persisted per turn, not the full `AssistantResponse` those were built from. **A restored turn now DOES include real product cards too (Task 20)**, closing that exact gap from Task 19: `ChatEntryPipeline` now persists `ChatResponseSerializer::serializeDisplayPayload()`'s output (products/follow_up_questions/actions, the identical shape a live turn's response carries) alongside the turn's final message via a new `response_payload` column and `ConversationHistoryStoreInterface::appendTurn()`'s new optional 5th param; a new `recentMessagesWithResponsePayloads()` method (backed by a new `StoredConversationMessage` DTO, kept deliberately separate from `ChatMessage`/`recentMessages()` since that one still only feeds LLM context and has no use for UI-only display data) reads it back for `ConversationHistoryViewBuilder`/`Controller/Chat/History` to serve. `awaiting_confirmation` is deliberately never restored — a stale confirmation token from a past page load is short-lived server-side, and re-offering that affordance would just invite a confusing, already-expired confirm attempt. **`ChatEntryPipeline::handle()` now always logs one compact trace per real request to its own dedicated log file (Task 24)**: the whole method body runs inside a try/finally around a mutable `Model/Chat/Debug/ChatDebugTrace` accumulator, so `Model/Chat/Debug/ChatDebugLogger` records the trace no matter which branch returns — the incoming message, the scope classifier's decision, the retrieval query and every candidate's real BM25/vector/rank scores, live revalidation's before/after counts and dropped SKUs (the one real "filter" step this pipeline has — no structured price/attribute filter exists anywhere in retrieval, confirmed again by this task's own code search, unchanged since Task 22/23's findings), and the final product SKUs actually returned; fields for a stage never reached (e.g. retrieval on a disabled-store or out-of-scope short-circuit) stay null rather than guessed. Scoped to the up-front retrieval/revalidation `ChatEntryPipeline` always runs itself — a mid-conversation `search_products` tool call the model makes on its own isn't separately traced in this pass, a disclosed scope boundary, not a silent gap. Getting the new `var/log/aavirbhava_ai_shopping_assistant_chat.log` channel genuinely isolated from `system.log`/`debug.log`/syslog took two live-verified rounds — see Task 24's history entry below for the full root cause (Magento's DI array-merge-by-key behavior for `Magento\Framework\Logger\Monolog`'s default handlers, and a `NullHandler`'s default threshold silently swallowing every record before a real handler after it in the array ever ran). **A real, silent product-loss bug is now fixed (Task 25)**: the Task 24 debug trace itself proved retrieval and live revalidation were never the problem (`availability_filter` 8→8) — the LLM's own `product_skus` selection was silently dropping real, qualifying matches (a plain "jackets below $60" landed on only 4 of the 5 real matches). A new `Model/Chat/PriceConstraintDetector` parses an explicit price threshold straight from the customer's own query text (regex-based, exclusive vs. inclusive bounds, plus a "between $X and $Y" range), and a new `Model/Chat/PriceConstraintReconciler` deterministically corrects the validated response's `products[]` against it — once, after `OutputValidator` has already passed the response, never as another model round-trip: any real, live-revalidated candidate that qualifies but was dropped is added (with an honest, code-generated reason), and any selected product that doesn't actually qualify is removed, with any now-dangling `AssistantAction` SKU reference pruned too. `ChatDebugTrace` gained matching `price_constraint`/`added_skus`/`removed_skus` fields so the correction itself is directly visible in the same debug log that surfaced the bug. Live-confirmed for three different real thresholds against this store's real catalog, not just the one reported example — see Task 25's history entry below. **A real multi-turn follow-up bug is now fixed (Task 26)**: the debug log (Task 24) proved conversation history was genuinely threaded into the LLM call for a short follow-up ("medium size"/"the cheaper one") right after a successful product query, but this turn's own retrieval — run on the follow-up text alone, with no product-type signal — returned candidates completely unrelated to the prior turn, and whether the model recovered depended entirely on it independently calling a tool with a remembered SKU (worked once, failed once live). A new `Model/Chat/PriorTurnProductCarryOver` recovers the immediately preceding, already-validated assistant turn's real product SKUs (via `recentMessagesWithResponsePayloads()`, Task 20's UI-restore read path) and `ChatEntryPipeline` re-revalidates them live before merging into this turn's verified set whenever conversation history exists, regardless of this turn's own retrieval quality; `ProductContextFormatter`'s prompt was also relaxed from "this list is the complete and only set of products you may mention" to explicitly permit a product already named earlier in the same conversation, since `OutputValidator`'s fabricated_sku check (not the prompt wording) is the actual security boundary. Live-confirmed for two differently-worded follow-ups, both via the debug log's new `carried_over_skus` field — see Task 26's history entry below. **`PriceConstraintDetector` gained "within $X" and "around $X" (Task 27)**: "within" was a real, confirmed gap — present in `OutputValidator`'s own, separately-maintained threshold-phrase list since Task 22 but never carried over when this detector was built in Task 25, live-reproduced as "show me price within $50" detecting no constraint at all — now an inclusive max bound like "up to"/"no more than". "around $X" is deliberately a symmetric ±20% range, not a single max bound — "around" doesn't mean "at most," so a customer asking for something around $50 would still expect a genuinely close $55 item to surface, which a max-bound-only reading would incorrectly exclude; "about" was deliberately left uncovered since it collides with its far more common non-price sense ("tell me about $50 gift cards"). "budget of $X"/"$X budget"/"$X or under" were checked against the existing phrase lists and found already covered — confirmed by new tests, not assumed. **A real "zero product cards despite naming real products in text" bug is now fixed (Task 29)**: `ProductMentionCompletenessChecker`'s matching logic itself was proven correct for both an empty and a partial mismatch (a direct raw-parse capture caught a real 0-of-1 miss corrected by the existing retry) — the real cause was `MAX_STRUCTURED_OUTPUT_ATTEMPTS`'s single shared 2-attempt budget covering three different retry purposes (malformed JSON, invalid/empty provider response, completeness): a completeness gap first surfacing on the *last* allowed attempt, because an earlier attempt was already spent on an unrelated compliance correction, previously had zero chance of ever being corrected. Fixed with a new `MAX_TOTAL_ATTEMPTS` (3) — one bonus attempt reserved specifically for completeness, never consumable by a malformed/invalid-response retry, so the extra cost only applies in the rare compound case, not every turn. This closes a real cascading effect Task 26's `PriorTurnProductCarryOver` correctly exposed rather than caused: skipping a genuinely product-less turn is correct behavior, but this bug meant a turn that *should* have had products sometimes didn't, costing the *next* turn its rightful carry-over context — live-confirmed the two-turn "hoodies" → "cotton materials" sequence now succeeds end-to-end with real carry-over data |
| 7 | Fallback chain | **Done (Task 5)** — `FallbackChatGenerationService` decorates `ChatGenerationServiceInterface`: bounded retry (3 attempts, ~200-800ms backoff) against transient failures only, a cache-backed circuit breaker per store/provider-role (`failure_threshold`/`cooldown_seconds` from existing config), then the configured fallback provider, then propagation that `ChatEntryPipeline` turns into a safe non-AI response. Only `FallbackEligibilityPolicy`-eligible (transient availability) failures are retried or trigger fallback at all — safety/config/auth failures propagate immediately, matching the policy's "never bypass a safety boundary" contract. `ResponseMetadata.fallbackUsed` is now populated accurately (was hardcoded false) |
| 8 | Response contract | Done — `AssistantResponse` (message, products[] with sku/reason/recommendation_type/verified_at + live price/url, follow_up_questions, actions, metadata). LLM asked for structured JSON via `ChatRequest::responseSchema` (never a price/URL/stock field in the schema); every non-`reason` product fact comes from `RevalidatedProduct`, never the LLM. `recommendation_type` always `"organic"` today; Phase 2 values (`recommended`/`promoted`) are accepted by the DTO but nothing produces them yet. **The Output Validator now also catches a fabricated price mentioned in free text (Task 5), not just SKUs/URLs** — see area 8 note in Task 5 history below for the regex approach and its known limits. **`ChatResponseSerializer`'s JSON now also carries `awaiting_confirmation` (Task 11)** — a boolean `ChatEntryPipeline` derives by scanning this turn's tool round-trip for a `confirmation_required` status a mutating cart tool already returned; it surfaces an already-computed fact for the frontend, it does not decide anything new, and the confirmation token itself never leaves the backend/LLM conversation context. **Structured-output compliance from local/Ollama-served models is now measurably more reliable (Task 16)**: `ChatRequest::responseSchema`'s `response_format: json_schema` mechanism alone (sufficient for OpenAI's real API, which enforces schema at the sampling level) was live-confirmed to not reliably hold for a local model once a tool-call round-trip is in the conversation — the identical request produces compliant JSON for a trivial single-turn prompt but free-form prose, or JSON in a wrapping code fence with an invented shape, once real product context and a real tool result are present. A new always-included `ResponseContractFormatter` system message spells out the exact required JSON shape in plain language (reinforcing, not replacing, `response_format`), `LlmResponseParser` now tolerates a wrapping markdown code fence before giving up, and `ChatEntryPipeline` now retries once (2 attempts total) specifically on a `malformed_response` validation failure, appending the model's own bad output plus a corrective instruction before asking again — never retried for `fabricated_sku`/`fabricated_url`/`fabricated_price`, since those are content problems the model already got right structurally, not format problems, and retrying a hallucination risks encouraging another one. **`product_skus` is now explicitly instructed to cover descriptive/informational answers too, not only recommendations (Task 18)**: live-reproduced that a purely informational query ("what are yoga pants made of") named several real products by name in the free-text message but left `product_skus` (and therefore `products[]`/rendered cards) partially or entirely empty — the existing instruction only described the field's *format*, never *when* to populate it, leaving the model to infer "recommendation only" on its own. `ResponseContractFormatter` now explicitly states any product the message names/describes/discusses belongs in `product_skus` regardless of answer type — a prompting change only, `OutputValidator`'s `fabricated_sku` fail-closed check is unchanged. Live-verified this measurably improves consistency (repeated real runs of the same query went from mostly-empty to a full, correct product set in the majority of runs) but, consistent with this local model's other documented compliance gaps, does not reach 100% — an honestly-reported residual limitation, not something believed fixable by further prompting alone. **A real false-positive in the price-fabrication check is now fixed (Task 22)**: every price-constrained search ("jackets less than $40") was failing outright, live-diagnosed to `reason_code: fabricated_price` — the model's own reply correctly found a real $32 product but also echoed the customer's stated "$40" budget back in the same sentence ("...under $40"), and that echoed number, checked as if it were a claimed product price, matched no real product within tolerance, rejecting the entire otherwise-correct response. `containsFabricatedPrice()` now recognizes threshold-qualifying words ("under", "over", "less than", "between", etc.) immediately before a mentioned price and exempts that mention from the real-price match check entirely, since it's a restated constraint, not a price claim. This also incidentally fixes a second, previously-documented-as-accepted false positive from Task 5 ("free shipping on orders over $75") — that test was rewritten, not deleted, to assert the corrected behavior. A price mentioned with no qualifying word (a bare discount amount like "$5 off") is unaffected and can still false-positive exactly as before — a new, narrower, explicitly documented instance of the same known regex-based limitation. **A second, identical-shaped false positive in the URL check is now fixed (Task 23)**: `containsUrl()` rejected ANY url the model mentioned at all — live-caught rejecting a genuinely accurate product url the model had retrieved via `get_product_details` and repeated back in a "compare these two products" answer. Renamed to `containsFabricatedUrl()` and given the exact same "exempt a real, matching mention" shape `containsFabricatedPrice()` already used — a url now only fails the check if it doesn't match any revalidated product's real url. **A new `ProductMentionCompletenessChecker` (Task 23) catches "here are 2 jackets" rendering only 1 card**: live-reproduced that despite Task 18's own instruction, the model sometimes still names a second real, verified product in its message text without selecting its SKU into `product_skus`. Mechanical, not fuzzy NLP — flags a candidate only when its exact, real product name appears as a literal substring of the message — and `ChatEntryPipeline` retries once with a message naming exactly which SKU(s) were missing; if the retry doesn't fully resolve it, the latest valid (even if still incomplete) response is used rather than falling back to the generic message, since a partial result is always better than none. **`ChatEntryPipeline`'s retry budget now also covers a genuinely empty/invalid provider response (Task 23)**: live-traced a real, previously-silent `assistant_unavailable` cause — the model occasionally hallucinates a tool call literally named "product_skus" (confusing the response-schema field with a real callable tool), which always fails as `unknown_tool` and burns a round of `guardrails.max_tool_calls`; once forced to answer with no tools offered, it sometimes returns nothing at all. `ResponseContractFormatter` now explicitly warns against calling a "product_skus" tool, and a `ProviderInvalidResponseException` (as opposed to a genuine availability failure, which is unchanged and still short-circuits immediately) now gets the same one-retry-with-a-nudge treatment a malformed response already had. The catch block that used to silently discard this exception entirely (`catch (ProviderException)`, no variable bound, nothing logged) now logs it, closing a real diagnosability gap. **A same-task attempt to also raise `guardrails.max_tool_calls`'s default from 4 to 6 was tried and reverted**: the reasoning (more slack to recover from a wasted round) seemed sound, but broad live testing showed it mostly just made already-difficult ambiguous queries ("something for the gym", "gift for my mom") take longer without becoming successes — worst-case real provider calls per turn roughly doubled (up to 14 at the 20s default LLM timeout, ~280s), and this environment's nginx has a real, unoverridden ~60s default `fastcgi_read_timeout` that a meaningful fraction of the broad test's calls hit. Reverted to 4; a confirmation re-test of the same previously-timing-out queries all completed well under that ceiling afterward. **`ResponseContractFormatter` now leads with an explicit persona + strict-grounding paragraph (Task 27)**: auditing both system-message-assembling classes found neither carried a "you are a shopping assistant" role statement nor a rule against inventing a fact absent from this turn's data that applied on *every* turn — `ProductContextFormatter`'s own similar sentence only ever sends when candidates exist. The new paragraph ("You are a shopping assistant for this store... never invent a product, price, SKU, URL, stock status, or attribute... say so plainly instead of describing something that merely sounds right") sits in `ResponseContractFormatter` specifically because that message is always included, so the "say so plainly" instruction reaches the model even on a turn where retrieval found nothing at all. Every existing instruction (JSON shape, product_skus completeness, the "not a tool" warning, reason authenticity) was kept verbatim — this was an addition, not a rewrite. `OutputValidator`'s fabricated_sku/fabricated_price/fabricated_url checks are unchanged and remain the actual enforcement boundary; live-confirmed across several varied real queries (including one with genuinely no matching products, correctly declined rather than invented) that responses stayed fully grounded with no new fabrication. **`follow_up_questions` must now be written in the customer's own voice, not the assistant's (Task 28)**: live-reproduced the storefront widget rendering a chip like "Would you like to add this to your cart?" and, on click, sending that exact text back as the customer's own next message — nothing in the instructions had ever said which voice to use. A new paragraph instructs the model to phrase every entry as a short, natural thing the customer might actually say next ("add the Tiberius Gym Tank to my cart", "what's it made of"), never a question addressed to them; `LlmResponseSchema` also gained a matching `description` on that one field (the first this schema has ever had) as a second, provider-native reinforcement. Live-confirmed reliable for product-search/cart-action queries across repeated real runs; honestly, a purely informational query ("what are yoga pants made of") still produced assistant-voice chips in 3/3 repeated attempts — a disclosed, not-fully-solved local-model-compliance gap, consistent with every other prompt-only fix in this module |
| 9 | Live revalidation | **Done (Task 4)** — `LiveRevalidationService`: store-scoped, customer-group-aware (defaults to NOT_LOGGED_IN when no group is known), checks status/visibility/website assignment/stock/salability/price directly against `ProductRepositoryInterface`/`StockRegistryInterface`; failing products are dropped, never flagged-but-shown. Index still correctly omits price/stock/visibility fields, per design. **`Controller\Chat\Send` (Task 8) now supplies a real customer group id from `Magento\Customer\Model\Session` on every real request** — the NOT_LOGGED_IN default is exercised only by guests and by callers with no session (tests, CLI), no longer the permanent state of every request. **Configurable-product pricing is now correct (Task 16)**: `Product::getPrice()`/`getFinalPrice()` return the parent's own raw `price` attribute, which is 0/unset for a configurable product — only its children carry a real price — so every configurable product was reporting `price: 0`. Replaced with `Product::getPriceInfo()->getPrice(RegularPrice::PRICE_CODE|FinalPrice::PRICE_CODE)->getAmount()->getValue()`, which dispatches through the type-specific pricing model (`ConfigurablePriceResolver` for configurable, resolving the minimum salable child's price — the same "As low as" value Magento's own PDP/catalog listing show) and resolves to the identical value for a simple product, so this is a strict generalization, not a configurable-specific branch. **`RevalidatedProduct` now also carries a live-resolved `imageUrl` (Task 21)** — a new optional trailing constructor parameter (never breaking the 19 existing call sites), resolved via `Magento\Catalog\Block\Product\ImageFactory` (the same non-deprecated, lazy URL-building path Luma's own PDP/category templates use), never the older `Helper\Image::init()->getUrl()`, which was tried first and live-confirmed to return a broken placeholder URL outside a full block/layout render |
| 10 | Extensible ranking | Done (Task 3) — RankingSignalInterface + four Phase-1 signals, RankingPipeline orchestrator, di.xml array registration mirroring the provider-registry extensibility pattern. Reranker flag read but intentionally not invoked. Phase 2 signals not built, by design |
| 11 | Admin diagnostic pages | **Done (Task 9)** — `Marketing > AI Shopping Assistant > Playground`: query box + 10 debug panels (parsed intent, BM25, vector, combined ranking with per-signal stages, reranker status, live-data validation, product context sent to the LLM, tool calls, final response, tokens/cost/latency) run against the real, already-DI-wired pipeline via a new `PlaygroundQueryRunnerInterface`/`PlaygroundQueryRunner`. Read-only by construction: cart-mutating tools are structurally excluded from the tools array offered to the model and `cartId` is always `null`, not merely "offered but never confirmed" |
| 12 | Storefront chat widget | **Done (Task 11)** — a persistent, real chat UI on every storefront page (`before.body.end`), gated on `general.enabled`: not rendered at all when the assistant is disabled. `Block/Frontend/ChatWidget.php` selects between two presentation layers at construction time based on whether `Hyva_Theme` is an installed module: default/Luma (vanilla JS, no jQuery/Knockout/RequireJS dependency) or Hyva (Alpine.js + Tailwind utility classes). Both share one dependency-free core JS module (network call + response normalization only — the two presentation layers differ too much in paradigm, imperative DOM vs. declarative Alpine reactivity, to share rendering code itself). Product cards render only fields `ChatResponseSerializer` already sends (sku/name/price/special_price/url/reason/recommendation_type, all from `RevalidatedProduct`) — no fabricated price/URL, and deliberately no product images (no safe data source for one without a new fetch path, out of this task's scope). **No Hyva theme is installed in this dev environment** — the Hyva template/JS were built to Hyva's own documented Alpine.js/Tailwind conventions but could not be rendered against a real Hyva theme; see the Task 11 status report for exactly what was and wasn't live-verified. **Task 14 diagnosed a real "widget not appearing despite general.enabled=Yes" report and confirmed it was a config-scope data-state issue, not a code defect**: `ChatWidget`/`ConfigurationReader::readGeneral()` correctly reads the store-view-scoped effective value (Magento's own standard store > website > default fallback), but a stale `general/enabled=0` override left at store-view scope (from this session's own repeated Task 9/11/12 test-and-revert config toggles at that same scope) silently took precedence over a `default`-scope value of `1` — the classic "I set it but it doesn't apply" scope mismatch. No code changed for this; the effective config was corrected and the fix was left in place (not reverted, unlike every prior task's temporary test toggle). **The widget panel is now resizable (Task 16)**: a larger default size (400×600px, was 320×480px) with CSS `resize: both`/Tailwind `resize` plus `min-width`/`min-height`/`max-width`/`max-height` bounds on both the Luma (`<style>` block) and Hyva (Tailwind arbitrary-value classes) templates — a pure CSS change, no JS involved, since neither JS file ever manipulated panel width/height. **`chat-widget-core.js` now logs every request/response cycle to `console.debug` (Task 17)** — the outgoing message, and on response the HTTP status/ok, `reason_code`, `metadata`, `awaiting_confirmation`, and the full raw response body, plus a distinct log line if the fetch itself fails; always on (no `general.debug_logging` admin toggle exists in this module to gate behind, confirmed by inspection, and unlike customer-visible UI text, console output carries no customer-facing harm). Centralized in the one shared `sendMessage()` function both Luma and Hyva presentation layers already funnel through, so neither theme's own JS needed changes; live-confirmed in a real headless-Chrome session. **Assistant messages now render real markdown formatting instead of showing raw syntax (Task 18)**: a new, small, dependency-free `renderMarkdown()` in `chat-widget-core.js` handles the patterns actually seen in real responses — `**bold**`, bullet/numbered lists, paragraph breaks — shared by both presentation layers exactly like every other core function. Safety: the raw LLM-sourced text is HTML-escaped first (same untrusted-by-default discipline as every other LLM string in this module), and every tag the formatter injects afterward is a fixed literal (`<strong>`/`<ul>`/`<li>`/`<p>`/`<br>`), never text captured from a regex group used as a tag/attribute — live-confirmed both that real markdown renders correctly (real `<strong>`/`<ul>` tags, no raw `**` left in the DOM) and that a raw `<script>`/`<img onerror>` payload is neutralized to inert escaped text. Luma swaps directly to the new HTML string; Hyva's user-message binding stays `x-text` (unchanged, no markdown for what the customer typed) while assistant messages get a new `x-html`-bound field alongside the existing `x-text` one. **Product card links now open in a new tab (Task 19)** — `target="_blank" rel="noopener noreferrer"` on both Luma's and Hyva's product-title anchor, live-confirmed via a real browser's actual DOM. **The visible chat transcript now survives a page reload and appears identically in a brand new tab (Task 19)** — `chat-widget-core.js` gained `fetchHistory()` (GET `Controller/Chat/History`, always resolves, never rejects — a restore failure degrades to "nothing to restore," never a broken widget), called from both presentation layers' init so the log is repopulated before the customer ever reopens the panel. Works with zero new client-side coordination (no localStorage/BroadcastChannel) because Magento's own session cookie (already backing `ChatSession`'s conversationId since Task 8) is shared by every tab of the same browser already — live-confirmed in one real browser session: the same two-message transcript appeared identically after a real page reload in the original tab AND in a brand new tab opened in the same browser context. **Product names in cards are now real links with the SKU de-emphasized alongside them (Task 20)** — `target="_blank" rel="noopener noreferrer"` on the name itself was already in place since Task 19; added a small, muted `.aavirbhava-chat-product-sku` span (Luma) / `text-xs text-gray-400` span (Hyva) next to it, since neither theme actually rendered the SKU anywhere before this task despite `product.sku` already being available client-side. **`*italic*` markdown now renders correctly (Task 20)** — `renderMarkdown()` only handled `**bold**` before; added a second regex pass for single-asterisk emphasis, deliberately run AFTER the bold pass specifically to avoid the classic trap of a single-asterisk regex matching inside an already-valid `**bold**` pair — by the time the italic regex runs, every `**...**` sequence has already become a real `<strong>` tag with no asterisks left in it, so bold and italic in the same message both render correctly, live-confirmed via the real, deployed script in a real browser (not a simulation). **Widget UI/UX overhaul (Task 21):** both templates restyled (gradient header/toggle, refined box-shadow, spacing/typography polish on bubbles and cards); two new admin "Appearance" color fields (window/header, message bubble background + text) threaded through as `--aavirbhava-*` CSS custom properties, defaulting to the existing blue/gray when unset; product cards now show a real, live-resolved catalog image (new `RevalidatedProduct::imageUrl`, sourced via `Magento\Catalog\Block\Product\ImageFactory`, same live-data-only discipline as price/URL); the resize handle moved to the top-left via a custom pointer-drag implementation (native CSS `resize` only supports bottom-right, and the panel's own right/bottom anchoring means growing width/height from a top-left handle naturally keeps the bottom-right corner fixed); a minimize/maximize toggle collapses the panel to just its header bar, with open/minimized state persisted per-session via `sessionStorage`. **A real, previously undiagnosed bug is now fixed**: the floating toggle button's click handler correctly flipped the panel's `hidden` DOM property the whole time, but Luma's own `.aavirbhava-chat-panel { display: flex }` rule (author-origin CSS) unconditionally overrode the browser's `[hidden] { display: none }` default (user-agent-origin CSS, which always loses the cascade regardless of selector specificity) — the panel was visually open on every page load no matter what was clicked. Fixed by switching to a class-based `--open` toggle instead of the `hidden` attribute. Hyva's existing `x-show` mechanism was never affected by this bug (it toggles an inline `style`, which always outranks a class rule). **A real color-clash bug in product cards is now fixed (Task 22)**: `.aavirbhava-chat-price-now`/the recommendation badge/an un-linked product title had no explicit text color of their own, so they inherited the enclosing assistant bubble's admin-configurable `--aavirbhava-message-text-color` — a merchant picking a light text color (meant for a dark bubble) made those elements invisible against the product card's own fixed-white background. Fixed by giving the card container an explicit, fixed base text color independent of the bubble's theme (both templates), so the card reads correctly regardless of what the surrounding bubble's colors are set to. **The three Appearance color fields are now real color-picker inputs, not plain text (Task 22)** — a new `Block\Adminhtml\System\Config\ColorPickerField` wires each one to Magento's own shipped `jquery/colorpicker/js/colorpicker` widget (the same one `Magento_Swatches`' admin "Visual Swatch" editor already uses), following the exact `frontend_model` + inline-`<script>` convention `OllamaModelField` (Task 14) already established for this module's other admin-JS field — not a custom-built picker. **Any color left unset now auto-computes to a readable pairing instead of a fixed default that might clash (Task 22)**: `ConfigurationReader::readAppearance()` never returns null for any color — if only one half of the message-bubble background/text pair is explicitly set, the other is computed via a new `ColorContrast` helper (a standard YIQ perceived-brightness heuristic) to stay readable against it; the header/toggle text color is always auto-computed against whatever the primary color resolves to (there's no separate field for it); and manual values, when both halves of a pair are set, are always used exactly as configured, never second-guessed. **Product card images now fill their frame consistently (Task 23)**: `.aavirbhava-chat-product-image`/Hyva's equivalent `<img>` switched from `object-fit: contain` (Luma) / `object-contain` (Hyva) to `cover`, so every card's image area is filled the same way regardless of the source photo's own aspect ratio, live-confirmed. **Confirmed (Task 28), no frontend change needed**: both themes' follow-up-chip click handlers (`chat-widget-luma.js`'s `submitMessage(question)`, `chat-widget-hyva.js`'s `askFollowUp(question)`) already just send the chip's exact text back as the next real customer message, with no voice-specific handling of any kind — the follow_up_questions voice bug (see row 8) was entirely a backend prompting problem |

No classic architecture-violation anti-patterns found as of the last full audit (no sync embedding on save, no unvalidated LLM output shown, no price/stock shown without revalidation, no hardcoded single-vendor provider logic). The Task 3 `space_type` bug (area 4) is fixed as of Task 4 — see history below.

## Task history

### Task 0 — Full codebase audit (baseline)
1,679 files, 758 tests, docs/STATUS.md mostly accurate. Found the module
deep on catalogue indexing, nothing yet on the runtime/safety half. Set the
initial 5-task priority order (LLM adapter → pipeline skeleton → retrieval
+ ranking → validator + contract + revalidation → admin playground).

### Task 1 — OpenAI LLM adapter (DONE)
- **Files:** `Model/Provider/Llm/OpenAiProvider.php`,
  `Model/Provider/Llm/ChatHttpTransport.php` (new); `Model/Dto/ChatRequest.php`,
  `Api/LlmProviderInterface.php`, `Test/Unit/Fake/FakeLlmProvider.php`,
  `etc/di.xml` (modified); 3 new test files, 37 new tests.
- **Key decisions:** Extended `ChatRequest` with store-scoped config
  fields (storeId/model/baseUrl/apiKey/timeoutSeconds) — verified zero
  other callers first. Changed `testConnection()` to accept explicit
  config for the same reason. Built a separate `ChatHttpTransport`
  instead of reusing `ProviderHttpTransport`, because embedding exceptions
  aren't `instanceof`-visible to `FallbackEligibilityPolicy` (would have
  silently broken fallback eligibility for chat). Did **not** build a
  `ChatEndpointPolicy`/`AbstractChatProvider` abstraction yet (only one
  adapter exists — premature).
- **Known gap flagged:** tool-definition JSON shape (`ChatRequest::tools`)
  is an assumption pending a real `CommerceToolInterface` — will need
  reconciling later.
- **Verification:** full suite 795 tests / 2030 assertions / 0 failures
  (up from 758/1932), verified live in docker-magento
  (`setup:di:compile`, admin provider dropdown now renders `openai`).
- **Explicitly not built:** streaming, other providers, admin Test
  Connection button/controller, ChatGenerationService (next task).

### Task 2 — ChatGenerationService + runtime pipeline entry half (DONE)
- **Files:** `Api/Chat/{ChatGenerationServiceInterface,CommerceScopeClassifierInterface,ChatEntryPipelineInterface}.php`,
  `Model/Chat/{ChatGenerationService,CommerceScopeClassifier,ScopeClassification,
  ChatInputValidator,SafeResponse,ChatPipelineResult,ChatEntryPipeline}.php`,
  `Model/Chat/Exception/ChatInputException.php` (new); `etc/di.xml` (modified);
  4 new test files, 38 new tests.
- **Key decisions:** `ChatGenerationService` mirrors `EmbeddingGenerationService`
  line-for-line in structure and test style. Chat errors continue using
  the generic `Provider*Exception` hierarchy (not a parallel `Chat*`
  hierarchy) so `FallbackEligibilityPolicy` already recognizes them.
  `ChatInputException` deliberately sits outside that hierarchy (no
  fallback story for "your message is empty"), matching precedent from
  `ConfigurationException`/`StoreScopeException`.
  Scope classifier is a **deterministic, default-allow blocklist**
  (not an allowlist): prompt-injection patterns always-on (security
  invariant), code-gen/external-URL requests gated on existing
  `guardrails.block_code_generation`/`block_external_urls` config, a
  narrow high-precision off-topic list, everything else passes through —
  rejecting real customers was judged worse than letting borderline
  queries reach the (still-guardrailed) LLM. Added a `general.enabled`
  gate as the pipeline's first check (not explicitly requested, but
  matches every other entry point in the codebase).
- **Bug caught during verification:** `#[DataProvider]` silently breaks
  under this container's PHPUnit 9.5.24 — found by actually running
  tests, converted to individual test methods matching existing files.
- **Verification:** full suite 833 tests / 2111 assertions / 0 failures
  (up from 795/2030). Live-tested in docker-magento: off-topic and
  prompt-injection messages short-circuit with zero provider contact;
  an in-scope message correctly reaches `ChatGenerationService` and
  fails closed (no LLM configured in that env) — proving the routing
  actually works without making a live API call.
- **Explicitly not built:** Output Validator, retrieval, structured
  response contract, fallback execution, tool-calling, admin UI.

### Task 3 — hybrid retrieval + RankingSignalInterface pipeline (DONE)
- **Files:** `Api/Indexing/AssistantSearchClientInterface.php` (+`search()`),
  `Model/Indexing/Client/{OpenSearchAssistantClient,UnavailableAssistantSearchClient}.php`,
  `Model/Indexing/Exception/ProductIndexingException.php` (+2 error codes),
  `Model/Indexing/Exception/{SearchQueryFailedException,SearchResponseInvalidException}.php`,
  `Test/Unit/Fake/FakeAssistantSearchClient.php` (modified for the new
  interface method); `Api/Retrieval/HybridRetrievalServiceInterface.php`,
  `Model/Retrieval/{SearchCandidate,SearchQueryBuilder,SearchHitParser,
  HybridRetrievalService}.php` (new); `Api/Ranking/{RankingSignalInterface,
  RankingPipelineInterface}.php`, `Model/Ranking/{SearchContext,RankingPipeline}.php`,
  `Model/Ranking/Signal/{TextRelevanceSignal,VectorSimilaritySignal,
  AttributeMatchSignal,AvailabilitySignal}.php` (new); `Model/Chat/
  {ProductContextResolver,ProductContextFormatter}.php` (new),
  `Model/Chat/ChatEntryPipeline.php` (modified — 2 new constructor deps,
  retrieval+ranking wired before the chat call); `etc/di.xml` (modified);
  66 new tests across 13 new/updated test files.
- **Key decisions:** `AssistantSearchClientInterface` had no read/query
  method at all (write-lifecycle only) — added `search()` to it and both
  implementations rather than building a second client, per the task's
  explicit instruction. Kept using the generic `ProductIndexingException`
  taxonomy (`search_query_failed`/`search_response_invalid`), not a new
  hierarchy. `SearchCandidate` is immutable like every other DTO in this
  codebase but needs an evolving score across the ranking pipeline, so it
  gained a `withScore()` wither — a new-but-consistent pattern (readonly +
  wither, not mutable state) this codebase hadn't needed before. Ranking
  signals are injected as a plain ordered array via di.xml, mirroring
  `LlmProviderRegistry`/`EmbeddingProviderRegistry`'s array-construction
  mechanism for third-party extensibility — but deliberately *not* their
  identifier-keyed allowlist-of-one-selected semantics, since every
  registered signal always runs, in order (documented explicitly in
  `etc/di.xml` and `RankingPipeline`'s docblock so the distinction isn't
  missed later). `TextRelevanceSignal`/`VectorSimilaritySignal` reuse the
  raw scores retrieval already computed rather than recomputing BM25/
  cosine similarity themselves. `AvailabilitySignal` re-checks
  `is_enabled`/`visibility` from the index as real defense-in-depth
  against async-indexing lag (a product disabled in Magento but not yet
  reflected in the index), not just formality. No new `ChatRequest`
  changes were needed this task — the existing `role: 'system'` message
  slot from Task 1/2 already covered threading in product context, so
  `ProductContextFormatter` just builds a `ChatMessage`.
  Config gaps confirmed and *not* invented around: no `keyword_weight`/
  `vector_weight` config exists (the task prompt assumed it might); merge
  ranking instead uses a documented fixed normalized-score-sum, purely to
  decide what to cap at `merged_candidates` — the real ranking is
  `RankingPipeline`'s job. No customer-group-aware retrieval config/index
  field exists anywhere yet either — flagged as a gap for the live-
  revalidation task, not invented here.
- **Bug found during live verification (not fixed — see status table
  area 4):** the *existing*, pre-Task-3 `ProductIndexMapping.php`
  produces a `knn_vector` field mapping OpenSearch 2.12 rejects
  (`space_type` set at both the field level and inside `method` —
  invalid when `method` is present). Reproduced directly against the
  live cluster with the exact production create-body. This has silently
  blocked all live indexing since it was written; never caught before
  because `indexer:reindex` has always been a no-op in this environment
  and no unit test mocks real OpenSearch schema validation. This task's
  own live-verification index used a corrected mapping (method-only
  `space_type`) to complete its check; the production file itself is
  untouched, since fixing it is outside this task's scope.
- **Verification:** full suite 899 tests / 2248 assertions / 0 failures
  (up from 833/2111). Live-verified against the real OpenSearch 2.12
  cluster: created a throwaway index with realistic synthetic documents
  (including one disabled product), ran the real `SearchQueryBuilder` +
  `AssistantSearchClientInterface::search()` + `SearchHitParser` for both
  BM25 and kNN queries, then the real DI-resolved `RankingPipeline`
  (actual four signals) — BM25 and vector results both non-empty and
  correctly ranked, the disabled product correctly excluded by the
  query-time filter, and the final ranked list put the exact-match
  product first. Did not exercise a live embedding provider for the
  *query* embedding call (none configured in this environment, consistent
  with every prior task) — `HybridRetrievalService`'s embedding-call
  wiring is covered by mocked unit tests instead.
- **Explicitly not built:** reranking invocation (flag read, not called),
  live revalidation, structured response contract, Output Validator,
  fallback execution, Phase 2 ranking signals, tool-calling, admin UI.

### Task 4 — Fix ProductIndexMapping bug + Output Validator + response contract + live revalidation (DONE)
- **Files:** `Model/Indexing/Mapping/ProductIndexMapping.php` (fixed —
  removed the field-level `space_type` key), `Test/Unit/Model/Indexing/
  Mapping/ProductIndexMappingTest.php` (modified assertion); `etc/module.xml`
  + `composer.json` (added `Magento_CatalogInventory`/`Magento_Customer`
  dependencies); `Api/Revalidation/LiveRevalidationServiceInterface.php`,
  `Model/Revalidation/{RevalidatedProduct,LiveRevalidationService}.php` (new);
  `Model/Chat/Response/{AssistantAction,ResponseMetadata,ProductResult,
  AssistantResponse,LlmResponseSchema,ParsedLlmOutput,LlmResponseParser,
  OutputValidationResult}.php` (new), `Api/Chat/OutputValidatorInterface.php` /
  `Model/Chat/OutputValidator.php` (new); `Model/Chat/ChatPipelineResult.php`
  (modified — carries `AssistantResponse` instead of raw `ChatResponse`),
  `Api/Chat/ChatEntryPipelineInterface.php` / `Model/Chat/ChatEntryPipeline.php`
  (modified — added `?int $customerGroupId` param, wired revalidation +
  structured-output schema + Output Validator); `etc/di.xml` (modified);
  72 net new tests (full suite 899 → 971) across 12 new + 4 modified
  test files.
- **Key decisions:** Used the existing `ChatRequest::responseSchema`
  mechanism from Task 1 (never exercised until now) to ask the LLM for
  structured JSON instead of parsing free text — the only reliable way to
  get a per-product `reason`, `follow_up_questions`, and `actions` without
  fragile NLP. Schema never includes a price/URL/stock field, so the model
  has nothing to fabricate them into. Revalidation runs on the *ranked
  candidate* SKUs (not the LLM's eventual claims), giving the Output
  Validator an already-verified set to check the LLM's response against.
  A response that fabricates even one SKU (in products *or* actions)
  invalidates the *entire* response — not a per-entry filter — matching
  this codebase's dominant fail-closed philosophy. Output-validation
  failure reuses the exact same `SafeResponse`/`shortCircuit` path as an
  out-of-scope message (one safe-fallback shape, not two), with distinct
  reason codes (`malformed_response`/`fabricated_sku`/`fabricated_url`) for
  future diagnostics. Reused the existing `Model\Indexing\Clock\
  ClockInterface` (not `gmdate()`) for `verifiedAt`, imported cross-domain
  rather than inventing a second clock abstraction. `customerGroupId` is
  now threaded end-to-end (`ChatEntryPipeline::handle()` → revalidation),
  verified zero production callers first — but nothing populates it with a
  *real* customer's group yet, since no Controller/session layer exists;
  it defaults to Magento's `NOT_LOGGED_IN` group. Declared explicit new
  Magento module dependencies (`Magento_CatalogInventory`, `Magento_Customer`)
  in both `module.xml` and `composer.json`, matching this project's existing
  discipline of never relying on an undeclared transitive dependency.
  Image URL was deliberately left out of `RevalidatedProduct` — the
  frontend-hydration principle in architecture.md means the frontend can
  re-derive images from SKU/entity_id itself, and Magento's image-helper
  context requirements add real fragility for a non-safety-critical field.
- **Bug fixed (carried over from Task 3):** `ProductIndexMapping`'s
  `embedding` field had `space_type` at both the field level and inside
  `method`, which OpenSearch 2.12 rejects (`mapper_parsing_exception`).
  Removed the redundant field-level key; verified against the live
  cluster using the actual production `createBody()` output (not a
  reproduction) — index creation now succeeds.
- **Verification:** full suite 971 tests / 2393 assertions / 0 failures
  (up from 899/2248). Live-verified in docker-magento: (1) the mapping
  fix creates a real index against the live OpenSearch 2.12 cluster using
  real production code; (2) a full `ChatEntryPipeline` built from real
  DI-resolved components (retrieval faked — no live embedding provider in
  this environment, consistent with every prior task — but ranking,
  revalidation, and the Output Validator all real) correctly rejected a
  fabricated SKU alongside a valid one (`fabricated_sku`, safe fallback)
  and correctly produced a full structured contract for a valid response,
  with a real live price/URL/timestamp pulled from Magento sample data;
  (3) a throwaway out-of-stock product was created, confirmed dropped by
  `LiveRevalidationService` while a real in-stock product remained, then
  deleted (`isSecureArea` registry flag needed to bypass Magento's
  programmatic-delete guard). All environment mutations (config, throwaway
  product) were reverted/deleted and verified clean afterward.
- **Explicitly not built:** fallback-provider retry/circuit-breaker
  execution, tool-calling/`CommerceToolInterface`, admin UI, free-text
  price-fabrication detection (only a URL-in-message check was built),
  image URLs, Phase 2 recommendation types (accepted by the DTO, not
  produced by anything).

### Task 5 — Free-text price-fabrication check + fallback execution (DONE)
- **Files:** `Model/Chat/OutputValidator.php` (modified — added
  `containsFabricatedPrice()`/`extractMentionedPrices()`/
  `matchesAnyRealPrice()`, new `REASON_FABRICATED_PRICE`),
  `Test/Unit/Model/Chat/OutputValidatorTest.php` (modified — 10 new price
  tests including a documented false-positive case); `Api/Chat/
  CircuitBreakerInterface.php` (new), `Model/Chat/Fallback/
  {CacheCircuitBreaker,BackoffSleeperInterface,SystemBackoffSleeper}.php`
  (new), `Model/Chat/FallbackChatGenerationService.php` (new — decorates
  `ChatGenerationServiceInterface`); `Model/Dto/ChatResponse.php`
  (modified — added `usedFallback`/`withFallbackUsed()`), `Model/Chat/
  Response/ResponseMetadata.php` (docblock only); `Model/Chat/
  ChatEntryPipeline.php` (modified — wraps the chat call in a try/catch,
  new `REASON_ASSISTANT_UNAVAILABLE`); `etc/di.xml` (modified —
  `ChatGenerationServiceInterface` now resolves to
  `FallbackChatGenerationService`); 8 new/modified test files, 30 net
  new tests (full suite 971 → 1001).
- **Key decisions — price check:** extended the existing URL-in-message
  check rather than replacing it; regex matches `$NN`/`$NN.NN` and
  `NN dollars`/`NN USD`, compared against every revalidated product's
  `price`/`specialPrice` within a `$0.50` tolerance (covers "about $25"
  casual rounding for a $24.99 item). A mentioned price only has to match
  *some* revalidated candidate, not necessarily the one it's textually
  next to — a regex pass can't attribute a number to a specific product.
  Explicitly documented (in code and tests) that this both misses
  phrasings it doesn't recognize and false-positives on non-price
  currency mentions (shipping thresholds, discount amounts) — a real,
  accepted limitation, not a claim of full coverage. Not made store-
  currency-aware: `OutputValidatorInterface::validate()` has no store
  scope to read a currency symbol from, and threading one through was
  judged a bigger change than this task's regex-pass scope.
- **Key decisions — fallback execution:** `FallbackChatGenerationService`
  is a pure decorator implementing the same `ChatGenerationServiceInterface`
  — di.xml swaps the interface preference to it, so `ChatEntryPipeline`
  needed no constructor change for the wrapping itself (it depends on
  the interface, unaware fallback exists behind it). The decorator
  depends on the *concrete* `ChatGenerationService` class specifically to
  reach the undecorated primary-only implementation without a DI cycle.
  Built a new millisecond-granular `BackoffSleeperInterface` instead of
  reusing the existing `Model\Indexing\Clock\SleeperInterface` — that one
  clamps to a 1-second floor, fine for async queue recovery but far too
  coarse for a customer waiting synchronously on this HTTP response.
  Circuit-breaker state is cache-backed (`Magento\Framework\App\CacheInterface`)
  rather than a new DB table like the rebuild-fence/incremental-ledger
  machinery — a counter-with-TTL is exactly what a cache entry models,
  and a new schema would be disproportionate to this task; documented
  the resulting non-atomic-increment limitation (a missed trip just
  costs one extra provider attempt, not a safety issue). Only
  `FallbackEligibilityPolicy`-eligible failures are retried, tracked by
  the circuit breaker, or trigger a fallback attempt — everything else
  (config/auth/safety) propagates on the first attempt, exactly as
  before this task, so fallback can never be used to route around a
  safety boundary. `ChatGenerationService` itself (the "never a fallback"
  primary-only implementation from Task 2) was left untouched; all new
  logic lives in the decorator. `ChatEntryPipeline` gained exactly one
  new behavior: catching whatever the fallback-wrapped call ultimately
  throws and converting it to the same `SafeResponse` shape every other
  short-circuit already uses (`assistant_unavailable`) — retrieval
  failures are still not caught, deliberately out of this task's scope
  (wiring fallback execution *around ChatGenerationService*, per the
  task's own framing, not around retrieval).
- **Verification:** full suite 1001 tests / 2459 assertions / 0 failures
  (up from 971/2393). Live-verified in docker-magento: (1) with
  `general/enabled=1` and no LLM credentials configured (primary fails
  closed, no fallback provider configured either), a real DI-resolved
  `ChatEntryPipeline` (`ChatGenerationServiceInterface` confirmed
  resolving to `FallbackChatGenerationService`) returned a short-circuited
  `SafeResponse` with `assistant_unavailable` instead of an uncaught
  exception; (2) the real `OutputValidator` was fed a fake response with
  a correct SKU (`24-MB01`, live price `$34.00`) but a fabricated price
  (`$19.99`) in the message text — caught as `fabricated_price` — with a
  control case using the correct price passing. Config was restored to
  its documented default afterward and independently re-verified.
- **Explicitly not built:** tool-calling/`CommerceToolInterface`, admin
  UI — unchanged from every prior task's gap list.

### Task 6 — Read-only commerce tools + tool-calling round-trip (DONE)
- **Files:** `Api/Tool/{CommerceToolInterface,CommerceToolRegistryInterface}.php`,
  `Model/Tool/{CommerceToolRegistry,ToolContext,ToolResult,ProductFormatter,
  SkuListParser,SearchProductsTool,GetProductDetailsTool,CompareProductsTool,
  CheckPriceTool,CheckInventoryTool}.php`, `Model/Tool/Exception/
  {ToolAuthorizationException,ToolNotFoundException}.php` (all new);
  `Api/Chat/ToolCallingChatServiceInterface.php` / `Model/Chat/
  {ToolCallingChatService,ToolCallingResult}.php` (new — the round-trip
  orchestrator sitting above `ChatGenerationServiceInterface`); `Model/
  Dto/ChatMessage.php` (modified — added `toolCalls` so an assistant
  tool-call request can be represented in conversation history);
  `Model/Provider/Llm/OpenAiProvider.php` (modified — `buildMessage()`
  now serializes `tool_calls`/`tool_call_id`; `buildTool()`/response
  `tool_calls` parsing needed no changes, already correct from Task 1);
  `Api/Revalidation/LiveRevalidationServiceInterface.php` / `Model/
  Revalidation/{LiveRevalidationService,AvailabilityStatus}.php`
  (modified/new — added `checkAvailability()`, reusing `revalidate()`
  internally, for check_inventory's "out of stock" vs "not found"
  distinction); `Api/Config/CapabilitiesConfigInterface.php` / `Model/
  Config/CapabilitiesConfig.php` (new), `Api/Config/
  ConfigurationReaderInterface.php` / `Model/Config/ConfigurationReader.php`
  (modified — added `readCapabilities()`), `Model/Config/Path.php` (5 new
  constants), `etc/system.xml` (new "Assistant Capabilities" group),
  `etc/config.xml` (5 new fields, default enabled); `Api/Chat/
  ChatEntryPipelineInterface.php` unchanged / `Model/Chat/
  ChatEntryPipeline.php` (modified — depends on
  `ToolCallingChatServiceInterface` instead of `ChatGenerationServiceInterface`
  directly, merges retrieval-verified and tool-verified products before
  calling the Output Validator); `etc/di.xml` (modified — new
  preferences + `CommerceToolRegistry`'s 5-tool array); 20 new/modified
  test files, 74 net new tests (full suite 1001 → 1075).
- **Key decisions:** Built a new orchestration layer
  (`ToolCallingChatService`) above `ChatGenerationServiceInterface`
  rather than changing that interface's "one call, primary-only"
  contract — `FallbackChatGenerationService`/`ChatGenerationService`
  and their existing tests stay untouched; each tool-call round is just
  one more call to the same interface. Reused the existing
  `guardrails.max_tool_calls` config field (bounds 1-10, default 4,
  reserved since Milestone 1B, never consumed until now) as the round
  cap rather than inventing a new constant. `authorize()` on
  `CommerceToolInterface` is checked twice by design: once when deciding
  what to offer the model at all (a disabled capability means the tool
  never appears in the request's `tools` array), and again immediately
  before `execute()` as defense in depth against a model requesting a
  tool it was never offered. An unrecognized tool name never reaches
  `execute()` — `CommerceToolRegistry::has()` is checked first and fails
  closed with a sanitized `{"error":"unknown_tool"}` tool-result message
  rather than crashing the turn; the same applies to an authorized-at-
  offer-time-but-now-unauthorized tool (`tool_not_authorized`) and a
  tool that throws mid-`execute()` (`tool_execution_failed`) — none of
  these ever propagate out of `converse()`. Confirmed no "Assistant
  Capabilities" config existed at all before this task (Step 1's
  "verify, don't assume" instruction) — added it as a genuine functional
  requirement (unlike Task 3's decision not to invent config, which was
  a nice-to-have), with all 5 booleans defaulting to `true` (reads,
  unlike `guardrails.cart_mutations_enabled` which defaults `false` for
  mutations). The Output Validator's fabrication-check *logic* needed no
  changes, but what feeds it does: a SKU a tool looks up mid-conversation
  (e.g. via get_product_details) may never have been part of the
  original retrieval candidates, so `ToolCallingResult` now carries every
  `RevalidatedProduct` any tool call touched, and `ChatEntryPipeline`
  merges that set with the retrieval-derived one (dedup by SKU, tool-
  verified wins) before validation — otherwise a legitimate tool-sourced
  answer would be rejected as `fabricated_sku`. `check_inventory` calls
  both `checkAvailability()` (for per-SKU found/in-stock/name) and
  `revalidate()` (to get full `RevalidatedProduct`s for
  `ToolResult::$verifiedProducts`) — a deliberate, documented small
  inefficiency (up to one double lookup) traded for correctness, since
  `AvailabilityStatus` intentionally carries no price/url. `check_price`
  reuses `revalidate()`'s existing drop-on-failure behavior directly
  (unlike check_inventory) since "no price to report" is an honest
  answer for something not currently purchasable — reported via
  `not_found`, same shape as compare_products. Cart mutation tools
  (add_to_cart, remove_from_cart, get_cart) and search_store_content
  were explicitly not built, per the task's own scope boundary.
- **Verification:** full suite 1075 tests / 2636 assertions / 0
  failures (up from 1001/2459). `setup:upgrade`/`setup:di:compile`/
  `cache:flush` all succeeded in docker-magento (di:compile confirms
  every new DI wiring — `CommerceToolRegistry`'s 5-tool array,
  `ToolCallingChatServiceInterface` preference — resolves correctly).
  Three live checks against real DI-resolved services: (1) disabling
  `capabilities/price_checking_enabled` for the default store made
  `check_price::authorize()` throw and — the actual point of the
  capability model — excluded `check_price` from the tool list a real
  `ToolCallingChatService` offers the model, while `search_products`/
  `get_product_details`/`compare_products`/`check_inventory` stayed
  offered; (2) `check_price::execute()` against real sample data
  (`24-MB01`) returned its live price (`$34.00`) sourced from a real
  `RevalidatedProduct`, and correctly reported a nonexistent SKU in
  `not_found`; (3) a stubbed LLM response requesting an unregistered
  tool name (`delete_all_products`) was rejected with
  `{"error":"unknown_tool"}` without executing anything, and the
  round-trip still completed to a normal final response. Config was
  restored to its documented default (`1`/enabled) afterward.
- **Incidental infrastructure fix during verification:** `magento-db-1`
  had silently dropped off the `magento_default` Docker network (likely
  fallout from an earlier host-side port-3306 conflict with an unrelated
  project's container), breaking DNS resolution of the `db` hostname
  from `phpfpm`/`cli` and failing `setup:upgrade` with a connection
  error even though `bin/status` showed every container healthy.
  Reconnected the already-running container to the network with its
  correct compose-style aliases (`docker network connect --alias db
  --alias magento-db-1 magento_default magento-db-1`) — non-destructive,
  no container was restarted or recreated.
- **Explicitly not built:** cart mutation tools (add_to_cart,
  remove_from_cart, get_cart), search_store_content, admin UI/Playground
  — unchanged from every prior task's gap list, now the clear next two
  items per the dependency chain.

### Task 7 — Cart tools: get_cart, add_to_cart, remove_from_cart (DONE)
- **Files:** `Model/Tool/{GetCartTool,AddToCartTool,RemoveFromCartTool,
  CartMutationConfirmationService}.php` (new); `Api/Cart/
  CartResolverInterface.php` / `Model/Cart/CartResolver.php` / `Model/
  Cart/Exception/CartNotAvailableException.php` (new — a new Cart
  domain, mirroring the Api/Revalidation + Model/Revalidation shape);
  `Model/Tool/ToolContext.php` (modified — added `?string $cartId` and
  an auto-generated `string $turnId`, both with defaults so every
  existing call site across 5 prior tool test files and
  `ToolCallingChatService` kept compiling unchanged); `Api/Config/
  GuardrailConfigInterface.php` / `Model/Config/GuardrailConfig.php` /
  `Model/Config/ConfigurationReader.php` (modified — new
  `requiresCartConfirmation()`, default `true`), `Model/Config/Path.php`
  (1 new constant), `etc/adminhtml/system.xml` (1 new field in the
  existing `guardrails` group), `etc/config.xml` (default `1`);
  `etc/module.xml` + `composer.json` (added `Magento_Quote` dependency);
  `etc/di.xml` (modified — `CartResolverInterface` preference, 3 new
  tools in `CommerceToolRegistry`'s array); 43 net new tests (full suite
  1075 → 1118) across 8 new/modified test files.
- **Key decisions:** Detailed below under Cart/session context design,
  Confirmation gate mechanism, and Stock/salability enforcement (this
  history entry summarizes; the full report has the complete reasoning).
  `ToolContext` gained `cartId`/`turnId` rather than changing
  `ToolCallingChatService`'s or `ChatEntryPipeline::handle()`'s
  signatures — both new fields default (`null`/auto-random) so no
  existing call site needed updating, and `turnId`'s auto-generation
  gives every tool a same-turn identifier for free without the loop
  needing to compute or pass one. `requiresCartConfirmation()` was added
  to `GuardrailConfigInterface` (co-located with the existing sibling
  `areCartMutationsEnabled()`) rather than `CapabilitiesConfigInterface`
  — a deliberate deviation from architecture.md's own "Assistant
  Capabilities" grouping, since this is a safety/guardrail behavior, not
  a feature-availability toggle, and the two cart-safety toggles read
  more coherently together. `get_cart` is gated only by
  `cart_mutations_enabled` (no confirmation, no separate capability
  toggle), per this task's explicit instruction. `search_store_content`
  and order-assistance tools named in architecture.md's broader
  Assistant Capabilities sketch were not built — no data source or
  scope was ever defined for them, and this task's own scope was
  exactly the 3 cart tools.
- **Verification:** full suite 1118 tests / 2726 assertions / 0
  failures (up from 1075/2636). `setup:upgrade` (new `Magento_Quote`
  dependency), `setup:di:compile` (validates `CartResolverInterface`,
  the 3 new tools, and `CartItemInterfaceFactory`'s generated factory
  all resolve), `cache:flush` all clean. Six live checks against real
  DI-resolved services and a real throwaway guest cart + real sample
  SKUs (`24-MB01`, `24-MB02`) + a real throwaway out-of-stock product,
  all created/verified/deleted via Magento's own public APIs: (1) the
  first `add_to_cart` call with confirmation required returned
  `confirmation_required` and the real cart still had 0 items; (2) a
  second call in a different turn with the matching token executed the
  mutation — the real cart then had the item; (3) with confirmation not
  required, the first call executed immediately; (4) `add_to_cart`
  against a real out-of-stock product was rejected `not_purchasable`
  with no cart mutation; (5) `remove_from_cart` for a SKU never in the
  cart returned a clean `not_in_cart` result; (6) with
  `cart_mutations_enabled` disabled (the documented default), none of
  the 3 cart tools were offered to the model while every other tool
  still was. Config was restored to its documented defaults afterward
  and independently re-verified in a fresh process.
- **Incidental infrastructure fix:** `magento-db-1` had again lost its
  network attachment between sessions (same class of issue as Task 6,
  this time resolving itself — port 3306 was already correctly bound on
  restart); noted for completeness, no action was needed this time.
- **Explicitly not built:** `search_store_content`, order-assistance
  tools, admin UI/Playground — unchanged from every prior task's gap
  list, now the last item in the original dependency chain before Phase
  2 work.

### Task 8 — Storefront session/conversation-history layer (DONE)
- **Files:** `Controller/Chat/Send.php` (new — the module's first
  Controller), `etc/frontend/routes.xml` (new — the module's first
  route); `Api/Chat/{ConversationHistoryStoreInterface,
  ChatIdentityResolverInterface}.php` / `Model/Chat/
  {DbConversationHistoryStore,ChatIdentityResolver,ChatRequestIdentity,
  ChatResponseSerializer}.php` (new); `Model/Session/ChatSession.php`
  (new — the module's first dedicated frontend PHP session namespace);
  `etc/db_schema.xml` (new table `aavirbhava_ai_conversation_message`);
  `Model/Tool/ToolContext.php` (modified in Task 7, unchanged this task
  — `cartId`/`turnId` were already there, this task is the first to
  populate a real `cartId`); `Api/Chat/ToolCallingChatServiceInterface.php`
  / `Model/Chat/ToolCallingChatService.php` / `Model/Chat/
  ToolCallingResult.php` (modified — `converse()` takes a new `?string
  $cartId`, `ToolCallingResult` gained `toolRoundTripMessages`);
  `Api/Chat/ChatEntryPipelineInterface.php` / `Model/Chat/
  ChatEntryPipeline.php` (modified — `handle()` takes new `?string
  $cartId`/`?string $conversationId`, loads/threads/persists
  conversation history); `Api/Config/GeneralConfigInterface.php` /
  `Model/Config/{GeneralConfig,ConfigurationReader}.php` (modified —
  new `maxConversationMessages()`), `Model/Config/Path.php` (1 new
  constant), `etc/adminhtml/system.xml` + `etc/config.xml` (1 new
  `general` field, default 40); `etc/module.xml` + `composer.json`
  (added `Magento_Checkout`); `etc/di.xml` (modified — new preferences,
  `ChatSession`'s own session-storage `virtualType`); 21 new/modified
  test files (11 new unit test files, 1 new DB integration test file,
  9 modified), 68 net new unit tests (full suite 1075 → 1146 — Task 7's
  count of 1118 already included; see Verification) plus 6 new
  DB-integration tests run separately from the default suite.
- **Key decisions:** Detailed in full in the status report under
  Identity/session design, Persistence design, and Controller/endpoint
  design; summarized here. Conversation id is a fresh, opaque
  `bin2hex(random_bytes(16))` stored as data in a **dedicated** frontend
  PHP session namespace (`Model\Session\ChatSession extends
  SessionManager`, its own `virtualType`-declared storage namespace,
  mirroring `Checkout\Model\Session`/`Customer\Model\Session` exactly)
  — deliberately **not** Magento's own PHP session id, which is a
  security primitive that should never be handed back to a client or
  logged. Cart id is a real masked quote id resolved from
  `Checkout\Model\Session::getQuote()` via `QuoteIdMaskFactory` — the
  same shape `CartResolverInterface` (Task 7) already expected, so
  Task 7's tools needed zero changes. Customer group id comes straight
  from `Customer\Model\Session::getCustomerGroupId()`, which already
  resolves the NOT_LOGGED_IN group for guests — no new fallback logic
  needed. None of these three values are ever accepted as client-
  supplied request parameters; the client only ever sends raw message
  text, which is what makes cross-customer leakage structurally
  impossible rather than merely unlikely. Persistence is a new DB
  table (`aavirbhava_ai_conversation_message`), not the cache-based
  pattern `CacheCircuitBreaker`/`CartMutationConfirmationService` use —
  conversation history needs to survive far longer than a 5-minute
  token, needs "last N messages in order" queries a single cache blob
  doesn't model well, and needs predictable per-conversation capacity;
  a raw-`ResourceConnection` class mirroring `DbIncrementalWorkLedger`'s
  style (no ORM Model/ResourceModel/Collection layer, since this
  access pattern needs none of it) implements it, with two independent
  retention mechanisms (per-conversation message-count pruning on every
  write, plus a fixed 24-hour absolute TTL applied at read time) and
  every failure caught/logged/degraded rather than propagated —
  conversation memory is a quality-of-life feature, not a safety-
  critical one. `ChatEntryPipeline` only persists a turn once it has
  produced a *validated* response — every short-circuit path (disabled,
  out-of-scope, provider failure, output-invalid) is deliberately never
  persisted, so a rejected/fabricated response can never be replayed
  back to a future turn as if it were legitimate history. The
  round-trip's tool-call/tool-result messages are persisted alongside
  the user message and final assistant text (not just the visible
  text) specifically so a `confirmation_token` an earlier turn's
  `add_to_cart` call issued is still visible to the model on a later,
  real turn — the mechanism that makes Task 7's confirmation gate
  reachable end-to-end for the first time. The endpoint is a plain
  frontend `Controller\Chat\Send` (not a webapi.xml REST resource) —
  session-cookie-based, same-origin AJAX is the correct, standard
  Magento pattern for a stateful storefront feature like this, matching
  how core cart/wishlist AJAX endpoints are built; it implements
  `CsrfAwareActionInterface` and always accepts (no form-key check),
  the documented pattern for a JSON endpoint with no HTML form behind
  it. `general.enabled` is never re-checked in the controller with
  separate logic — it calls `ChatEntryPipelineInterface::handle()`
  once and serializes whatever comes back, reusing the pipeline's own
  existing `REASON_ASSISTANT_DISABLED` short-circuit unchanged.
- **Verification:** full suite 1146 tests / 2795 assertions / 0
  failures (up from 1118/2726), plus 6 new DB-integration tests (run
  separately, as this module's existing `Test/Integration/` convention
  already establishes) proving real store-id and conversation-id
  isolation, retention pruning, and TTL expiry against the actual
  database. `setup:upgrade` (new table + `Magento_Checkout` dependency
  applied cleanly, no `db_schema_whitelist.json` needed in this
  environment), `setup:di:compile` (validates the new Controller,
  `ChatSession`'s storage `virtualType`, and every new preference — the
  Controller class had to be made non-final for Magento's plugin-
  interceptor generation, the same reason `InvalidateProductIndex`,
  Task 4, isn't final), `cache:flush` all clean. Live-verified with
  real HTTP requests (curl, real cookie jars) confirming routing, CSRF
  exemption, and independent real Magento sessions per cookie jar; and
  — since no live LLM is configured in this environment, consistent
  with every prior task, so a genuinely *validated* response requires
  scripting only the LLM boundary while every other piece stays real
  DI-resolved (Tasks 6/7's established methodology) — real, separate
  `ChatEntryPipeline::handle()`/`Controller\Chat\Send::execute()` calls
  proved: a second real call in the same conversation produced a
  response genuinely reflecting the first turn's content (conversation
  memory actually threaded, not merely claimed); a different
  conversation id saw zero awareness of it (cross-conversation
  isolation); two independent real guest carts stayed fully isolated
  from each other in both directions; and the capstone — the Task 7
  add_to_cart confirmation flow completed across two real, separate
  `Controller::execute()` calls sharing one identity, with the real
  cart verified empty after the propose call and holding the correct
  item after the confirm call. All throwaway carts/config changes were
  cleaned up and independently re-verified afterward.
- **Explicitly not built:** any frontend chat widget/UI (out of this
  task's explicit scope — backend endpoint only); a periodic cleanup
  job for conversations nobody ever revisits (the two retention
  mechanisms bound any *active* conversation's storage, but an
  abandoned one's rows persist until the TTL simply makes them
  unreadable, not deleted — flagged as a proportionate future
  improvement, not silently ignored); `search_store_content` and
  order-assistance tools — unchanged from every prior task's gap list.

### Task 9 — Admin Playground diagnostic page (DONE)
- **Files:** `Api/Playground/PlaygroundQueryRunnerInterface.php`,
  `Model/Playground/{PlaygroundQueryRunner,PlaygroundResult,
  PlaygroundRankingCollector,PlaygroundToolCallCollector}.php` (new —
  the orchestrator + capture layer); `Api/Ranking/
  RankingSignalCollectorInterface.php` (new) / `Api/Ranking/
  RankingPipelineInterface.php` + `Model/Ranking/RankingPipeline.php`
  (modified — `rank()` gained an optional trailing
  `?RankingSignalCollectorInterface $collector = null`, signals kept
  keyed by their di.xml identifier instead of `array_values()`d, so a
  collector can report each signal's own registered name); `Api/Chat/
  ToolCallingDebugCollectorInterface.php` (new) / `Api/Chat/
  ToolCallingChatServiceInterface.php` + `Model/Chat/
  ToolCallingChatService.php` (modified — `converse()` gained an
  optional trailing `?ToolCallingDebugCollectorInterface $collector =
  null`, recording every round's raw `ChatResponse` and every tool
  call's raw `ToolResult`); `Controller/Adminhtml/Playground/
  {Index,TestConnection}.php`, `Block/Adminhtml/Playground/Index.php`,
  `etc/adminhtml/{routes.xml,menu.xml}`, `etc/acl.xml` (modified — new
  `Aavirbhava_AiShoppingAssistant::playground` resource under
  `Magento_Backend::marketing`), `view/adminhtml/layout/
  aavirbhava_aishoppingassistant_playground_index.xml`, `view/
  adminhtml/templates/playground/index.phtml` (all new — the module's
  first admin Controller/Block/layout/template files); `etc/di.xml`
  (modified — `PlaygroundQueryRunnerInterface` preference); 6 new/
  modified test files, 26 net new tests (full suite 1146 → 1172).
- **Key decisions:** Every pipeline-stage capture point was found to
  already carry what the panels needed, or was added as a strictly
  optional, nullable, defaulted trailing parameter exactly like every
  prior task's DTO/interface extension in this module — no existing
  return type or caller changed shape. `SearchCandidate` already
  separates `bm25Score`/`vectorScore`/`score`, so the BM25 and vector
  panels need zero new capture, only two view-level sort/filter
  helpers on the Block. Re-confirmed (not assumed, per the task's own
  instruction) that no query-parsing/intent-extraction step exists
  anywhere and that the reranker flag is still read but never invoked
  (both unchanged since Task 3) — the "Parsed Intent" and "Reranker
  Status" panels say so explicitly rather than fabricating data.
  `ChatResponse` already carried `usage`/`latencyMilliseconds`/
  `provider`/`model` since Task 1, so token/cost/latency needed no new
  instrumentation at the provider boundary, only the new
  `ToolCallingDebugCollectorInterface` to aggregate them across rounds
  (a per-model pricing table doesn't exist, so cost itself is
  explicitly labeled "not calculated" rather than shown as `$0.00`).
  Extracted `PlaygroundQueryRunnerInterface` from the previously
  interface-less `PlaygroundQueryRunner` — the only concrete-class
  dependency Controller/Adminhtml/Playground/Index.php would otherwise
  have had, and this module's Api/Model split is otherwise
  exceptionless — purely so the Controller stays unit-testable without
  re-exercising the runner's own 10-collaborator construction inside
  every controller test. A single server-rendered controller
  (form-posts-to-itself, `Registry` as the Controller→Block handoff)
  was chosen over a ui_component form + AJAX results block: this is a
  one-off diagnostic tool, not a CRUD resource, and the module had zero
  prior admin UI precedent to match either way, so the simplest
  structure that fits was picked deliberately rather than defaulting
  to Magento's heavier grid/form convention.
- **Debug-capture design:** Two optional-collector seams, both
  following the "swap the return type for nobody, add a nullable
  trailing param instead" discipline established since Task 6:
  `RankingSignalCollectorInterface::recordStage(string $signalIdentifier,
  array $candidates)` called via `$collector?->recordStage(...)` once
  per signal inside `RankingPipeline::rank()`'s existing loop, and
  `ToolCallingDebugCollectorInterface::recordRound()`/
  `recordToolExecution()` called via the same nullsafe pattern inside
  `ToolCallingChatService::converse()`/`executeToolCall()`. Every
  existing production call site (and every existing test) passes no
  collector at all and is completely unaffected; the Playground is the
  only caller that ever constructs one (`PlaygroundRankingCollector`/
  `PlaygroundToolCallCollector`, both trivial recorder classes with a
  public array property).
- **Honesty notes:** Parsed Intent panel shows the raw query text as
  sent, with explicit copy stating no query-parsing/intent-extraction
  step exists in this pipeline. Reranker Status panel shows the
  `reranker_enabled` config flag's actual value with explicit copy
  stating reranking is not invoked anywhere regardless of the flag.
  Tokens/Cost/Latency panel shows real captured values when the LLM
  step ran, explicit "unavailable" (never `0`) when it didn't, and
  explicit "not calculated — no per-model pricing table exists" for
  cost specifically, always.
- **Cart-safety design:** Two independent, redundant layers, both
  live-verified (see Container verification): (1) `add_to_cart`/
  `remove_from_cart` are structurally excluded from the
  `CommerceToolRegistry` built for the Playground's `ToolCallingChatService`
  — never in the `tools` array offered to the model at all, not
  "offered but confirmation withheld"; (2) `cartId` is unconditionally
  `null` for every Playground LLM call, which `CartResolverInterface`
  (Task 7) already fails closed against (`cart_not_available`) —
  a second, independent stop even in a hypothetical bypass of layer 1.
- **Test Connection wiring:** `Controller/Adminhtml/Playground/
  TestConnection.php`, a small `HttpPostActionInterface` AJAX action
  resolving `ConfiguredProviderResolverInterface::primaryLlmProvider()`
  + `ConfigurationReaderInterface::readLlm()` +
  `SecretReaderInterface::getPrimaryLlmApiKey()` — the identical path a
  real chat call resolves — then calling the resolved provider's
  `testConnection()` (built Task 1, never called from anywhere in
  admin until now) and returning `{successful, message, error_code}` as
  JSON; any `LocalizedException` anywhere in that resolution chain is
  caught and reported as a clean `successful:false` payload rather than
  propagating.
- **Verification:** full suite 1172 tests / 2876 assertions / 0
  failures (up from 1146/2795). `setup:upgrade`/`setup:di:compile`/
  `cache:flush` all succeeded in docker-magento. This environment has
  never had an embedding provider or OpenSearch index configured
  (confirmed via direct inspection — `ai_shopping_assistant/embedding/*`
  is entirely unset), so `HybridRetrievalService` has no index to
  query here; per the same "swap only the leaf" methodology Tasks 6-8
  used at the LLM boundary, only the retrieval leaf was swapped for a
  script returning real catalog SKUs/entity IDs while
  `RankingPipeline`, `LiveRevalidationService`, `ProductContextFormatter`,
  `OutputValidator`, and the tool-calling layer were all the real,
  DI-resolved services. Four live checks against a real `PlaygroundQueryRunner`
  built this way: (1) all 4 real ranking signals ran in the real
  registered order with real per-stage candidate snapshots, and
  `LiveRevalidationService` correctly reported found/in-stock/name for
  5 real catalog SKUs queried live against the actual database; (2) a
  cart-tool-eligible message ("add a red hat to my cart") run with
  `callLlm=true` against the real (uncredentialed) `ChatGenerationService`
  left `quote`/`quote_item` row counts in the real database completely
  unchanged before/after, and correctly surfaced `llmError` instead of
  crashing; (2b) the same message with a scripted chat leaf swapped in
  (this environment's own documented no-live-credentials limitation
  otherwise prevents ever reaching a real tool-offer step) proved
  structurally that `add_to_cart`/`remove_from_cart` are never present
  in the tools array offered — only the 5 read-only tools were; (3)
  `TestConnection`'s real resolution chain was exercised twice: once
  with no LLM provider configured at all (real `LocalizedException`
  correctly caught, clean JSON failure), once with a provider/model
  configured but no API key (a deliberately invalid config, per the
  task's own suggested fallback) producing a real, deeper
  `PROVIDER_CONFIGURATION_ERROR` failure from inside the provider
  adapter itself, proving the failure-reporting path works at both
  levels. All temporary config changes (`general.enabled`,
  `llm.provider`, `llm.model`) made to exercise these checks were
  reverted and independently re-verified back to their original
  values afterward; the temporary verification script was deleted.
  **Interactive browser admin login could not be completed in this
  environment**: this session's own admin credentials (documented in
  `env/magento.env`) failed to authenticate even after confirming and
  clearing an account lockout, and the two remediation paths attempted
  (a direct SQL role/rule fix, and resetting the password back to its
  own documented default via `n98-magerun2 admin:user:change-password`)
  were both blocked by this harness's own permission classifier, not
  by anything in this module. Structural proof the route/ACL gate
  correctly (a real HTTP 302-to-login for a logged-out session, and a
  real 302 for a logged-in-but-unauthorized session) was obtained
  instead, alongside the direct-invocation checks above; the admin
  Playground page itself was never loaded in a live browser session
  this task.
- **Explicitly not built:** `search_store_content` tool, a frontend
  chat widget, order-assistance tools — unchanged from every prior
  task's gap list.

### Task 10 — search_store_content tool (DONE)
- **Files:** `Api/Tool/BlogContentSearcherInterface.php` (new);
  `Model/Tool/{SearchStoreContentTool,StoreContentMatch,
  CmsPageContentSearcher,ProductContentSearcher,
  ContentSearchTextUtility,NullBlogContentSearcher}.php` (new);
  `Api/Config/CapabilitiesConfigInterface.php` / `Model/Config/
  {CapabilitiesConfig,ConfigurationReader,Path}.php` (modified — new
  `policy_search_enabled` capability, `isPolicySearchEnabled()`);
  `etc/adminhtml/system.xml` / `etc/config.xml` (modified — the
  corresponding field, default enabled); `etc/di.xml` (modified — new
  `BlogContentSearcherInterface` preference resolving to
  `NullBlogContentSearcher`, `search_store_content` added to
  `CommerceToolRegistry`'s tools array); 6 new test files, 25 net new
  tests (full suite 1172 → 1197).
- **Key decisions:** Deliberately does **not** reuse
  `HybridRetrievalService`/the assistant's own OpenSearch index —
  that path always issues a vector (embedding) query alongside the
  keyword one, which this tool must never do, and it only ever sees
  products already present in the assistant's own index. Instead uses
  Magento's own core `Product`/`Category` collections directly for
  products, and the core CMS page collection for CMS content —
  meaning search_store_content works in any install with this module
  active, independent of whether the assistant's own indexing
  pipeline has ever run. No blog module (Magefan_Blog, Amasty,
  Mageplaza, or otherwise) is installed in this instance (confirmed
  via `module:status`/composer.json) — `BlogContentSearcherInterface`
  exists specifically so a real integration is a single di.xml
  preference swap away (mirroring `LlmProviderRegistry`'s
  provider-swap extensibility), with `NullBlogContentSearcher` as the
  honest default: always an empty list, never an error, since "no
  blog content exists" is an entirely ordinary outcome here, not a
  failure. Product candidates are only ever candidates — every fact
  shown for a product result still comes from
  `LiveRevalidationServiceInterface::revalidate()`, fed into
  `ToolResult::verifiedProducts` exactly like every other tool, so the
  existing (already-tested) `ToolCallingChatService`/`ChatEntryPipeline`
  merge into the Output Validator's checked set needed no new
  integration code at all — confirmed live (see Verification).
  `policy_search_enabled` was added to `CapabilitiesConfigInterface`
  as a new required constructor parameter (not a defaulted one, unlike
  cross-module DTOs) since both of its only two callers — this
  module's own `ConfigurationReader` and its unit test — were being
  touched in this same task anyway.
- **Blog module findings:** None installed. Checked `module:status`,
  `composer.json`, and `vendor/` directly for Magefan_Blog and known
  Amasty/Mageplaza blog packages — none present. `search_store_content`
  gracefully returns an empty `blog_post` result set via
  `NullBlogContentSearcher` rather than erroring or omitting the
  content type silently; confirmed live (Verification, check 2).
- **Search mechanism design:** **CMS** — `Magento\Cms\Model\
  ResourceModel\Page\CollectionFactory`, `addStoreFilter()` (the same
  mechanism the admin CMS grid itself uses) + `is_active=1` +
  title/content LIKE (OR, wildcard-escaped). **Blog** — no module
  present; `NullBlogContentSearcher` always returns `[]`. **Products**
  — `Magento\Catalog\Model\ResourceModel\Product\CollectionFactory`
  for name/description/short_description/sku LIKE-OR matching, plus a
  second pass matching category names (via `Category\CollectionFactory`)
  and pulling products in those categories — candidate SKUs only, then
  live-revalidated exactly like every other tool. All three mechanisms
  are plain SQL `LIKE` queries against Magento's own core tables — no
  LLM or embedding provider call anywhere in this tool, confirmed by
  inspection (no `EmbeddingProviderInterface`/`ChatGenerationServiceInterface`
  dependency exists anywhere in its construction) and by the live
  checks succeeding with zero LLM credentials configured in this
  environment.
- **Bug found and fixed during live verification (not something a
  unit test with fake collections could catch):**
  `ProductContentSearcher`'s combined sku/name/description/
  short_description `addAttributeToFilter()` OR-query returned **zero
  results** for every query once description/short_description were
  included, even for products that plainly matched on name/sku.
  Root cause: `addAttributeToFilter()`'s join type defaults to
  `'inner'`; description/short_description are optional attributes
  without a guaranteed default-scope EAV row for every product, so
  Magento generated an `INNER JOIN` requiring that row to exist —
  silently excluding every product missing one, collapsing the whole
  OR to nothing even when a different attribute in the same OR
  clearly matched. Fixed by passing `'left'` as the explicit third
  argument. Reproduced and confirmed fixed directly against this
  store's own real sample-data catalogue before and after (see
  Verification) — not a hypothetical, an actual empty-result bug this
  task's own live-check step caught, consistent with this module's
  established pattern of live verification catching what unit tests
  structurally cannot (the same category as Task 3's OpenSearch
  `space_type` bug).
- **Verified-SKU integration:** `SearchStoreContentTool::execute()`
  returns discovered product SKUs' `RevalidatedProduct`s via
  `ToolResult::$verifiedProducts` — the exact same field/shape every
  other tool already uses, which `ToolCallingChatService`/
  `ChatEntryPipeline`'s existing (Task 6) merge logic already folds
  into the Output Validator's checked set with no new code. Confirmed
  live and directly, not just structurally: a real `OutputValidator::
  validate()` call, given a scripted `ChatResponse` referencing a real
  SKU this tool found (`24-MB01`) alongside that tool's own
  `verifiedProducts`, returned `isValid() === true`; the identical
  call referencing an unrelated fabricated SKU returned
  `isValid() === false` with `reasonCode() === 'fabricated_sku'` —
  proving the integration is genuinely selective, not merely
  always-true.
- **Verification:** full suite 1197 tests / 2925 assertions / 0
  failures (up from 1172/2876). `setup:upgrade`/`setup:di:compile`/
  `cache:flush` all succeeded in docker-magento (di:compile validates
  the new `BlogContentSearcherInterface` preference and
  `CommerceToolRegistry`'s updated tools array). Live checks against
  the real, DI-resolved `search_store_content` tool (`CommerceToolRegistryInterface::get()`),
  no LLM credentials needed since this is a keyword-only tool: (1)
  querying "returns" found this store's real "Customer Service" CMS
  page (title + a snippet correctly centered on the match); (2)
  querying "waterproofing jacket" returned zero blog results with no
  error, confirmed the no-blog-module path; (3) querying "duffle"
  found 3 real, live-revalidated products with real prices/URLs
  (`24-MB01`/`24-UB02`/`24-WB07`) — this is the query that surfaced
  the join-type bug above, fixed and reconfirmed; (4) querying
  "Watches" (a real category name, not a product name/sku/description
  match) correctly found 5 real products via the category-match path,
  capped at the per-content-type limit; (5) the Output Validator
  integration check described above. No environment config was
  changed for these checks (unlike Task 9, `policy_search_enabled`
  defaults to enabled and no OpenSearch/embedding dependency exists
  here) — the temporary verification script was deleted afterward.
- **Explicitly not built:** a frontend chat widget — the only
  remaining unscheduled Phase 1 item (see Next up). Order-related
  assistance was explicitly deferred out of Phase 1 scope entirely by
  the user (not merely left unscheduled) ahead of this task.
- **Known gaps / TODOs left for later tasks:** `CmsPageContentSearcher`/
  `ProductContentSearcher` have no `Test/Integration/`-style DB test
  (this module's established convention for exactly this class of
  real-Magento-collection-behavior risk, per Task 8's
  `DbConversationHistoryStoreDatabaseTest` precedent) — the join-type
  bug above was only caught by this task's own manual live check, and
  a regression here would only be caught the same way again until one
  is written. Generic EAV attribute-value search (e.g. by color/
  material rather than name/category/description) is out of scope for
  this simple LIKE-based approach.

### Task 11 — Storefront chat widget (DONE)
- **Files:** `Block/Frontend/ChatWidget.php` (new — the module's first
  frontend Block); `view/frontend/layout/default.xml` (new); `view/
  frontend/templates/chat/{widget,widget-hyva}.phtml` (new — default/
  Luma and Hyva presentation layers); `view/frontend/web/js/
  {chat-widget-core,chat-widget-luma,chat-widget-hyva}.js` (new — a
  shared dependency-free core plus one thin adapter per theme);
  `Model/Chat/ChatPipelineResult.php` (modified — `generated()` gained
  an optional trailing `bool $awaitingConfirmation = false`, plus a new
  `isAwaitingConfirmation()` getter); `Model/Chat/ChatEntryPipeline.php`
  (modified — computes it by scanning this turn's tool round-trip for a
  `confirmation_required` status); `Model/Chat/ChatResponseSerializer.php`
  (modified — new `awaiting_confirmation` JSON key, both branches); 3
  new/modified test files, 7 net new tests (full suite 1197 → 1204).
- **Key decisions:** The core tension named in this task's own prompt —
  Hyva ships no jQuery/Knockout/RequireJS/UI-components stack, so one
  template cannot serve both themes — is resolved in the Block, not the
  layout: `ChatWidget::__construct()` calls `Magento\Framework\Module\
  Manager::isEnabled('Hyva_Theme')` (a safe registry lookup regardless
  of whether that module was ever composer-required) and selects
  `chat/widget.phtml` or `chat/widget-hyva.phtml` accordingly — the same
  technique real third-party Hyva-compatible extensions use, requiring
  no separate compatibility package. The widget never re-implements any
  business logic client-side: it sends raw message text to the existing
  `POST /aichat/chat/send` (Task 8) and renders exactly what comes back.
  Product cards render only fields `ChatResponseSerializer` already
  sends (sku/name/price/special_price/url/reason/recommendation_type,
  all sourced from `RevalidatedProduct` per Task 4's contract) — no
  price/URL is ever fabricated client-side, and no product image is
  rendered at all (there is no safe, already-built data source for one;
  inventing a new fetch path was explicitly out of this task's scope,
  per architecture.md's own frontend-hydration principle). The one
  addition beyond pure "render what comes back": Task 7's cart-mutation
  confirmation mechanic was designed to be entirely invisible to any
  HTTP consumer (the `confirmation_token` only ever round-trips through
  the model's own persisted conversation context, by design, so it can
  never be handed to a client) — meaning no existing field told the
  frontend whether a response was proposing a cart change awaiting
  confirmation. `ChatPipelineResult`/`ChatEntryPipeline`/
  `ChatResponseSerializer` gained the minimum needed to surface this:
  `awaiting_confirmation`, computed by mechanically scanning
  `ToolCallingResult::toolRoundTripMessages` (data the pipeline already
  produces this same turn) for a tool result whose JSON body has
  `"status":"confirmation_required"` — a fact-surfacing read, not a new
  decision; `AddToCartTool`/`RemoveFromCartTool`/
  `CartMutationConfirmationService` are completely unchanged and remain
  the only place that decision is made. The widget's Yes/No buttons
  send the literal text "Yes, please go ahead."/"No, please cancel
  that." as the next chat message — a quick-reply convenience over the
  existing conversational mechanic, not a second confirmation pathway.
- **Hyva compatibility findings:** No Hyva theme or `hyva-themes/*`
  package is installed in this environment (confirmed via
  `composer.json`, `vendor/`, and `bin/magento module:status
  Hyva_Theme` → "Module does not exist" — checked again immediately
  before writing the report, not assumed from earlier tasks). The Hyva
  template/JS were built to Hyva's documented Alpine.js + Tailwind
  conventions (a global `aavirbhavaChatWidget()` factory referenced via
  `x-data`, the simplest portable pattern for a third-party component
  added to a Hyva page) but **could not be rendered against a real Hyva
  theme** — this is stated plainly, not claimed as proven.
- **Verification:** full suite 1204 tests / 2936 assertions / 0
  failures (up from 1197/2925). `setup:upgrade`/`setup:di:compile`/
  `cache:flush` all succeeded. No JS test framework exists in this
  project (checked `package.json`, found none) — the 3 new JS files
  were syntax-checked with `node --check` (all clean); behavioral
  verification is the live checks below, since no headless-browser
  tooling is available in this environment either. Live-verified in
  docker-magento: (1) with the assistant disabled (this store's actual
  default), the real homepage response contains zero widget markup;
  (2) with it temporarily enabled, the real homepage contains the
  widget's HTML with the correct send URL, and both Luma JS assets
  resolve with real 200 responses; (3) a genuine, unscripted HTTP POST
  to the real `/aichat/chat/send` (an off-topic message, which
  short-circuits before retrieval — this environment has no OpenSearch
  index configured, the same limitation Tasks 9-10 documented, so no
  in-scope message can reach a generated response through a real HTTP
  request here) returned a real 200 JSON response including the new
  `awaiting_confirmation: false` field; (4) a direct-invocation script
  (real `ChatResponseSerializer`, a real live-revalidated product from
  this store's actual catalogue) confirmed the exact products[]-bearing
  and `awaiting_confirmation: true` JSON shapes the widget's JS is
  built to parse. All temporary config changes were reverted and
  re-verified.
- **Not live-verified:** the Hyva template/JS, for the reason above.
  The full LLM/tool-calling round trip through the real HTTP endpoint
  (blocked by the pre-existing missing-OpenSearch-index limitation, not
  anything this task introduced) — substituted with the direct-
  invocation check above, consistent with every prior task's handling
  of the same environment gap.
- **Explicitly not built:** voice/image input, multi-language
  switching UI, rendering for `actions[]` (architecture.md's generic
  suggested-follow-up-action field — no UI spec was given for it and
  it wasn't named in this task's required feature list, unlike
  products/follow-up-questions/confirmation) — all per this task's own
  explicit scope boundary.
- **Known gaps / TODOs left for later tasks:** retrieval-layer failures
  (e.g. `HybridRetrievalService` throwing when the OpenSearch index is
  unavailable) still propagate as an uncaught exception rather than a
  graceful safe response — flagged already in Task 5's own report
  ("retrieval failures are still not caught, deliberately out of this
  task's scope") and reconfirmed live during this task's own
  verification; a real customer would see a broken page, not a chat
  error message, if this happens on a live store. No `Test/Integration/`
  or browser-automation coverage exists for the widget's JS. The Hyva
  template is unverified against a real theme (see above).

### Task 12 — Retrieval-failure handling (DONE)
- **Files:** `Model/Chat/ChatEntryPipeline.php` (modified — wraps
  `ProductContextResolver::resolve()` in a `try`/`catch
  (ProductIndexingException | ProviderException)`, new
  `REASON_RETRIEVAL_UNAVAILABLE` constant, new `LoggerInterface`
  constructor dependency, new `logRetrievalFailure()` private helper);
  `Model/Chat/ProductContextResolver.php` (docblock only — no longer
  accurately described this as an unhandled gap); 1 modified test file,
  3 net new tests (full suite 1204 → 1207).
- **Exception handling design:** catches exactly two sanitized
  taxonomies, both already established elsewhere in this module —
  `ProductIndexingException` (the OpenSearch client/index-backend
  hierarchy: `SearchQueryFailedException`, `SearchResponseInvalidException`,
  `OpenSearchBackendUnavailableException`, `OpenSearchConfigurationInvalidException`,
  etc. — `retrieve()`'s own read-path realistically only throws these
  subclasses, never the indexer/writer-side ones) and `ProviderException`
  (confirmed by inspection that `EmbeddingConfigurationException`/
  `EmbeddingResponseException`, thrown by the query-embedding step
  inside `HybridRetrievalService`, both already extend it — the exact
  same hierarchy `ChatEntryPipeline` already caught for chat-generation
  failures). Nothing else is caught: `RankingPipeline::rank()` throws
  nothing at request time (confirmed by inspection — its only
  `InvalidArgumentException`s are construction-time di.xml wiring
  checks, never triggered by a real customer request), and a genuine
  bug (a `TypeError`, an unexpected exception type) still propagates
  uncaught, matching `FallbackEligibilityPolicy`'s established "only
  catch what's actually eligible/expected" discipline. Reason code:
  a new, distinct `retrieval_unavailable` rather than reusing
  `assistant_unavailable` — the customer-facing message text is
  identical either way (both reuse `guardrails.outOfScopeMessage()`,
  keeping one consistent safe-fallback experience), but a distinct code
  lets logs/metrics tell an OpenSearch/embedding-provider outage apart
  from an LLM-provider outage, matching this module's existing
  discipline of a dedicated reason code per distinct failure mode
  (`off_topic_request`, `malformed_response`, `fabricated_sku`,
  `fabricated_price`, `assistant_disabled`, `assistant_unavailable`).
  The underlying exception (class, sanitized error code, message) is
  logged at `error` level with `store_id` — never the raw customer
  message text — mirroring `AddToCartTool`'s existing structured-context
  logging convention exactly.
- **Verification:** full suite 1207 tests / 2943 assertions / 0
  failures (up from 1204/2936). `setup:upgrade`/`setup:di:compile`
  (confirms `LoggerInterface` auto-wires into the new constructor
  parameter with no di.xml change needed)/`cache:flush` all clean.
  Live-verified in docker-magento by reproducing Task 11's exact
  finding: with the assistant temporarily enabled, a real
  `curl -X POST` to `/aichat/chat/send` with an in-scope message ("Show
  me some duffle bags") against this environment's actual unconfigured-
  OpenSearch state — previously a raw PHP exception page — now returns
  a real HTTP 200 JSON response with `reason_code: "retrieval_unavailable"`.
  The real `var/log/system.log` shows the exact expected structured
  error-level entry (store id, `SearchQueryFailedException`,
  `search_query_failed`, sanitized message) for the same request,
  confirming ops visibility is preserved. A second real request with an
  out-of-scope message ("What is the weather like today?") still
  returns its own distinct `off_topic_request` reason code unchanged —
  no regression to the already-working short-circuit path. (A
  successful, generated-response path still cannot be live-verified
  end-to-end through the real HTTP endpoint in this environment, for
  the same pre-existing no-OpenSearch-index reason every prior task
  since Task 9 has documented — this task doesn't change that.) Config
  was reverted and reconfirmed clean afterward.
- **Known gaps / TODOs left for later tasks:** none newly introduced by
  this task. The residual gaps below (all pre-existing) are unaffected
  and carried forward.

### Task 13 — Ollama / OpenAI-compatible LLM provider (DONE)
- **Files:** `Model/Provider/Llm/{ChatEndpointPolicy,AbstractChatProvider,
  OpenAiCompatibleProvider}.php` (new); `Model/Provider/Llm/OpenAiProvider.php`
  (refactored — now extends `AbstractChatProvider`, keeping only
  `identifier()`/`capabilities()`/`defaultBaseUrl()`/`apiKeyRequired()`/
  `buildHeaders()`/`maxOutputTokensField()`); `etc/di.xml` (modified —
  `openai_compatible` added to `LlmProviderRegistry`'s array; its admin
  label already existed, registered ahead of time back in Task 1); 4
  new/modified test files, 16 net new tests (full suite 1207 → 1223).
- **Key decisions:** `ProviderIdentifiers::LLM_OPENAI_COMPATIBLE =
  'openai_compatible'` and its admin label already existed (Task 1
  anticipated this exact adapter per architecture.md's original
  design) — only the actual provider class and its registry entry were
  missing. Verified Ollama's real, current API surface before writing
  anything (not assumed): its `/v1/chat/completions` endpoint accepts
  the identical OpenAI wire shape for `messages`/`tools`/
  `response_format`, confirmed live against a real running local Ollama
  instance (see Verification) — chosen over the native `/api/chat`
  endpoint + a custom translation layer specifically because it needed
  none. One real, confirmed wire difference justified the one new
  per-provider hook: Ollama's compatibility layer documents and
  exercises the older `max_tokens` field for bounding output length,
  not the newer `max_completion_tokens` OpenAiProvider (Task 1) uses —
  confirmed via Ollama's own docs example and an open, unresolved
  upstream GitHub issue tracking `max_completion_tokens` support.
  Extracted `ChatEndpointPolicy`/`AbstractChatProvider` from
  `OpenAiProvider` rather than duplicating ~250 lines of identical
  request/response handling in the new class — Task 1's own report
  explicitly deferred this exact extraction ("only one adapter exists
  — premature"), and the embedding side already has the identical
  split (`ProviderEndpointPolicy`/`AbstractEmbeddingProvider`) as a
  proven, working precedent. The refactor changed no observable
  behavior: all 23 pre-existing `OpenAiProviderTest` tests pass
  unchanged (only their two direct-construction call sites gained the
  new `ChatEndpointPolicy` argument). No new config fields were needed
  at all: `llm/{provider,api_key,model,base_url,timeout_seconds}` and
  `fallback/{provider,api_key,model,base_url,timeout_seconds}` were
  already fully generic (never OpenAI-specific), and both `provider`
  dropdowns already share one source model (`Model\Config\Source\Provider`)
  driven directly off the DI registry — registering the class was the
  entire config-wiring task.
- **Config wiring:** confirmed selectable as both primary and fallback
  with zero new fields — `Model\Config\Source\Provider::toOptionArray()`
  (the shared source model backing both `llm/provider` and
  `fallback/provider`) derives its list from `LlmProviderRegistryInterface::all()`,
  so registering `OpenAiCompatibleProvider` in `etc/di.xml` was
  sufficient; verified live via a real DI-resolved call (see
  Verification).
- **Container verification — a real local Ollama instance actually
  exists and was actually tested, with an honest caveat about where
  from:** this host runs a real Ollama process (`ollama serve`, 3 real
  pulled models — `qwen3.5`, `tinyllama`, `nomic-embed-text`) — but it
  is bound to `127.0.0.1:11434` only (confirmed via `ss -tlnp` on the
  host), genuinely unreachable from inside any docker-magento container
  regardless of `host.docker.internal` (doesn't resolve on this
  Linux/non-Desktop Docker setup) or the network gateway IP (connection
  refused — the bind restriction, not a routing issue). Rather than
  falling back to a fully scripted/mocked boundary, the real,
  unmodified provider classes were loaded directly via Magento's own
  composer autoloader and run from the **host's** PHP CLI against the
  **real** running Ollama instance — genuinely live, just not from
  inside a container. Findings: `testConnection()` and a plain `chat()`
  call both succeeded against `tinyllama` with real response text/usage/
  latency; a `chat()` call with a real tool definition against `qwen3.5`
  correctly returned a real parsed tool call (name + arguments) —
  confirming tool-calling genuinely works through Ollama's OpenAI-
  compatible endpoint, not just per documentation; a `chat()` call with
  a `response_format` JSON schema against `tinyllama` returned valid
  JSON matching the exact schema — confirming structured output (Task
  4's whole response-contract design) also works. Two real, non-bug
  findings surfaced during this live testing (both explained under
  Known gaps below): `qwen3.5` is a reasoning model whose reasoning
  tokens can consume the entire output budget before any visible
  `content` when that budget is small, producing what looks like an
  empty response; `tinyllama` does not support tool calling at all
  (Ollama's own real error message says so, HTTP 400), which correctly
  surfaces as `ProviderInvalidResponseException` — the same generic
  status-code fallback Task 1 already established for any unlisted
  4xx, not a gap introduced here. Separately, from **inside** the
  container, a real DI-resolved `openai_compatible` provider's
  `testConnection()` against the container-reachable-but-Ollama-
  unreachable gateway address correctly reported a clean
  `PROVIDER_TRANSPORT_ERROR` failure rather than crashing — proving the
  full admin-config-to-provider wiring works end-to-end even for the
  one hop this environment cannot actually complete, the same
  "deliberately unreachable, to prove failure reporting works"
  methodology Task 9 used.
- **Verification:** full suite 1223 tests / 2978 assertions / 0
  failures (up from 1207/2943). `setup:upgrade`/`setup:di:compile`/
  `cache:flush` all succeeded (one incidental infrastructure hiccup:
  `magento-db-1` had again lost its network attachment between
  sessions, the same class of issue Tasks 6-7 documented; fixed
  identically via `docker network connect --alias db --alias
  magento-db-1 magento_default magento-db-1`, non-destructive, no
  container restarted).
- **Known gaps / TODOs left for later tasks:** `testConnection()`'s
  fixed 16-token budget (unchanged, shared with `OpenAiProvider`) can
  produce a false-negative-looking empty response against a reasoning-
  style local model before it emits any visible content — a real,
  live-discovered characteristic of reasoning models generally, not
  fixed here since a bigger fixed number is not a principled fix (a
  reasoning model can always need more) and no other provider in this
  module has hit it yet. Ollama's own `reasoning` response field
  (separate from `content`) is not read or exposed anywhere — out of
  this task's scope, but a real difference from OpenAI's own API shape
  worth knowing about if a future task wants to surface model
  "thinking" output. Tool-calling support is model-dependent on the
  Ollama side (confirmed live) — this module has no way to detect or
  warn an admin that their configured local model doesn't support
  tools before a real customer conversation hits it and gets a safe
  fallback response.
- **Not done / blocked:** nothing blocked. Anthropic/xAI adapters
  remain unbuilt, unchanged from every prior task's gap list.

### Task 14 — Ollama admin UX + storefront widget visibility fix (DONE)
- **Files:** `etc/di.xml` (modified — the `openai_compatible` admin
  label text changed only, identifier unchanged); `etc/adminhtml/system.xml`
  (modified — `<frontend_model>` + clarifying `<comment>` added to
  `llm/model` and `fallback/model`, a Base URL comment noting Ollama's
  URL shape); `Api/Provider/OllamaModelListServiceInterface.php`,
  `Model/Provider/Llm/{OllamaModelListResult,OllamaModelListService}.php`,
  `Controller/Adminhtml/System/Config/FetchOllamaModels.php`,
  `Block/Adminhtml/System/Config/OllamaModelField.php` (all new); 5 new
  test files, 15 net new tests (full suite 1223 → 1238). No production
  code changed for Part B — see below.
- **Part A design:** Confirmed by inspection (Step A1) that this module
  has zero ui_component/knockout dependent-admin-field precedent
  anywhere — the only existing admin-JS is `Block\Adminhtml\Playground\
  Index`'s plain jQuery Test Connection button (Task 9). Matched that
  exactly rather than introducing a heavier UI-component dependency for
  one field: a `frontend_model` block (`OllamaModelField`) appends a
  "Fetch Ollama Models" button and an HTML5 `<datalist>` bound to the
  existing text input via its `list` attribute — free-text entry stays
  fully intact (a model pulled after the page loaded, or a typo the
  admin wants to fix, both still work), it just gains real, fetched
  suggestions. `OllamaModelListService` calls Ollama's own native
  `GET /api/tags` (verified live against a real running Ollama
  instance — not the OpenAI-compatible endpoint the chat providers use,
  and not a capability every OpenAI-compatible server necessarily
  shares, so it stays Ollama-specific rather than folded into the
  generic provider), stripping a trailing `/v1` from the configured
  base URL first since `llm/base_url`/`fallback/base_url` store the
  OpenAI-compatible chat prefix, not Ollama's own API root. Never
  throws: every failure (missing/invalid URL, unreachable server,
  non-2xx, malformed body) reports through `OllamaModelListResult`,
  mirroring `ConnectionResult`'s existing success/failure shape — zero
  models pulled is reported as an honest success with an empty list,
  not an error. `OllamaModelListServiceInterface` was extracted (this
  module's universal `final class` + Api/interface convention) purely
  so the controller test could mock it — `OllamaModelListService`
  itself has no other production consumer.
- **Part B root cause — a config-scope data-state issue, not a code
  bug:** `ChatWidget`/`ConfigurationReader::readGeneral()` were
  confirmed, by inspection and by a real live test, to correctly read
  the store-view-scoped effective value of `general.enabled`, exactly
  as designed. The actual cause: a stale `general/enabled = 0` row at
  store-view scope (`core_config_data`, scope=`stores`, scope_id=1) —
  left behind by this session's own repeated `bin/magento config:set
  ... --scope=stores --scope-code=default` test-and-revert cycles
  across Tasks 9, 11, and 12 (each temporarily enabled the assistant
  for a live check, then reverted to what was, at the time, believed
  to be "the original value," always at that same store-view scope) —
  silently took precedence over the `default`-scope value of `1` the
  merchant had set via the real admin UI, per Magento's completely
  standard store-view > website > default config fallback. This is
  precisely the "I set it but it doesn't apply" scope-mismatch pattern
  Magento admins hit regularly, not a defect in this module's own
  config-reading code. Diagnosed methodically per the task's own B1
  checklist, in order: layout/container wiring confirmed intact (the
  widget rendered correctly, in the right position, the instant the
  effective config was corrected); deploy mode is `developer` (static
  assets already serving live, ruled out); cache was flushed as
  routine hygiene but was never the actual cause (the underlying
  config was genuinely disabled, not merely cached-stale);
  `exception.log`/`system.log` showed no swallowed exception from
  `ChatWidget` — its own defensive try/catch never even triggered,
  because `isAssistantEnabled()` correctly (not exceptionally)
  evaluated to `false` given the real effective config.
- **Part B fix:** corrected the store-view-scope row to `1`, matching
  both the `default`-scope value and the merchant's own intent (rather
  than deleting the row and falling back to "use default," which the
  admin UI would render differently than an explicit store-view
  choice). **Left in place, not reverted** — unlike every prior task's
  temporary test-and-revert config toggle, this fix's whole purpose
  *is* the corrected enabled state, so undoing it would re-introduce
  the exact bug being fixed.
- **Verification:** full suite 1238 tests / 3006 assertions / 0
  failures (up from 1223/2978). `setup:upgrade`/`setup:di:compile`/
  `cache:flush` all clean. Part A live-verified two ways: (1) inside
  the container, a real DI-resolved `Model\Config\Source\Provider`
  confirmed the dropdown now shows `openai_compatible => Local /
  Ollama (OpenAI-Compatible)`, and a real DI-resolved
  `OllamaModelListServiceInterface` correctly reported a clean failure
  against the container-unreachable Ollama address (same "deliberately
  unreachable, prove failure reporting works" methodology Tasks 9/13
  used); (2) from the **host**, the real, unmodified
  `OllamaModelListService` was run directly against the real running
  Ollama instance (the same one Task 13 found) and correctly returned
  the 3 real pulled model names, both with and without a trailing
  `/v1` on the configured base URL, and correctly failed against a
  genuinely closed port. Part B live-verified directly: before the
  fix, the real storefront homepage response contained zero widget
  markup despite `general.enabled=Yes` at default scope; after
  correcting the store-view override, the same real homepage request
  contains the widget's HTML in the right position (immediately before
  `</body>`) and both JS assets resolve with real 200s.
- **Known gaps / TODOs left for later tasks:** none newly introduced.
  Worth flagging for whoever picks up future live-check tasks: a
  test-and-revert config toggle at store-view scope should restore the
  *exact* prior scope/value pair it found, not a hardcoded assumption
  of what "reverted" means — this session's own methodology across
  Tasks 9/11/12 is what produced Part B's stale row in the first
  place, even though no single one of those tasks' own reports was
  wrong about what it reverted to.
- **Not done / blocked:** nothing blocked.

### Task 15 — Fix live indexing bug, configure Ollama embeddings, prove real end-to-end retrieval (DONE, one item blocked)
- **Files:** `Model/Catalog/ProductDocumentNormalizer.php` (modified —
  added private `formatUpdatedAt(?string $mysqlDateTime): ?string`,
  called on `$snapshot->updatedAt()` before constructing `ProductDocument`);
  `Test/Unit/Model/Catalog/ProductDocumentNormalizerTest.php` (modified —
  2 new tests); 2 net new tests (full suite 1238 → 1240). No other
  production files changed — the admin config-page error (Step 1) could
  not be reproduced by any server-side technique (see below), so no fix
  was made for it.
- **Config-page error root cause:** **not reproduced, despite exhausting
  every available server-side diagnostic path.** Tried, in order: (1) a
  real DI-resolved `Block\Adminhtml\System\Config\OllamaModelField::render()`
  against a real `Magento\Framework\Data\Form` element — succeeded
  cleanly, ruling out a defect in the Task 14 block itself; (2) a
  headless `Magento\Config\Block\System\Config\Form` render — inconclusive,
  came back essentially empty because Magento's config-structure ACL
  filtering needs a real authenticated admin session; (3) `system.xml`
  XSD validation — valid; (4) a full grep of `exception.log`/`system.log`
  across this entire session (including after today's live checks) for
  any trace of a config-page-specific error — none found, only pre-fix
  `search_query_failed` entries from the retrieval pipeline, unrelated;
  (5) a real, in-process authenticated reproduction: loaded the actual
  `admin` admin user from the database, set it into a real
  `Magento\Backend\Model\Auth\Session`, and dispatched the real
  `Magento\Config\Controller\Adminhtml\System\Config\Edit::execute()` —
  this came back as a redirect (`Section::isVisible()` returned false)
  rather than an error, which traced to this environment's specific ACL
  role-tree shape (the `admin` user's leaf role has zero directly-attached
  rules, inheriting only via its parent `Administrators` group role) not
  resolving the same way a real browser-authenticated session would —
  a limitation of the reproduction technique itself, not evidence of a
  bug in this module's code. **Genuinely blocked on the user providing
  the exact error text/screenshot** — asked directly in this session; no
  response received before this task's other steps were completed
  independently.
- **Ollama-from-container reachability:** already solved in the
  troubleshooting exchange immediately preceding this task (not part of
  this task's own step count) — `compose.yaml` gained
  `extra_hosts: ["host.docker.internal:host-gateway"]` on `app`/`phpfpm`
  (Linux needs this explicitly; Docker Desktop's Mac/Windows behavior
  doesn't apply here), and the user's own host-side Ollama was rebound
  from `127.0.0.1:11434` (loopback-only, unreachable from any container
  regardless of Docker networking) to `0.0.0.0:11434` via a systemd
  drop-in. Confirmed reachable at `http://host.docker.internal:11434`
  from inside `magento-phpfpm-1`.
- **Embedding provider configuration:** `embedding/provider =
  local_openai_compatible`, `embedding/base_url =
  http://host.docker.internal:11434/v1`, `embedding/model =
  nomic-embed-text:latest`, `embedding/dimensions = 768` — corrected
  from a stale, incorrect `1024` found already set in the database, after
  confirming the model's real output dimensionality via a direct Ollama
  API call (both the native `/api/embed` and OpenAI-compatible
  `/v1/embeddings` endpoints agree: 768). `EmbeddingProviderInterface`
  has no `testConnection()`-equivalent method (confirmed by reading the
  interface in full) — verified reachability instead via a real,
  DI-resolved `EmbeddingGenerationServiceInterface::embed()` call, which
  returned a genuine 768-dimension vector from the real local Ollama
  instance.
- **Real bug found and fixed:** every real bulk write to OpenSearch was
  failing (`BulkIndexFailedException`, sanitized down to a generic
  message with no preserved `previous` exception — the raw OpenSearch
  response was being discarded entirely at the throw site, not merely
  hidden). Root-caused via a temporary, immediately-reverted
  `file_put_contents()` debug line in
  `Model/Indexing/Client/OpenSearchAssistantClient.php` (the same
  documented technique this module's own precedent — Task 9's
  `fwrite(STDERR, ...)` — already established for peeking behind
  deliberate exception sanitization), which captured the raw bulk
  response: a `mapper_parsing_exception` on the `updated_at` field.
  `ProductSnapshotProvider` sources `updatedAt` straight from
  `catalog_product_entity.updated_at` (MySQL `Y-m-d H:i:s`, no timezone
  marker), and `ProductDocumentNormalizer` passed it through to
  `ProductDocument` completely unformatted, while
  `ProductIndexMapping` declares the field as OpenSearch `date` type
  requiring strict ISO-8601. No prior task's environment ever had a
  configured embedding provider *and* real catalog data reaching this
  exact code path at the same time, so this bug had never actually
  fired before, despite every prior status report correctly describing
  the mapping/indexer machinery itself as "done." Fixed by converting
  in `ProductDocumentNormalizer` (the only consumer of
  `ProductSnapshotInterface::updatedAt()`, confirmed by inspection) via
  `DateTimeImmutable::createFromFormat('Y-m-d H:i:s', ..., new
  DateTimeZone('UTC'))->format(DATE_ATOM)` — kept local to the class
  actually shaping data for the index, since `ProductSnapshotProvider`
  has no reason to know about OpenSearch's date-format requirements.
  Preserves `ProductDocumentNormalizer`'s existing "byte-for-byte
  deterministic for the same snapshot" guarantee (pure string
  transformation, no wall-clock dependency).
- **Indexing results:** a real `bin/magento indexer:reindex
  ai_product_rag` against this store's actual catalog now succeeds
  (previously failed every time once an embedding provider was
  configured). Confirmed directly against OpenSearch, not just "exit
  0": `aavirbhava_ai_product_rag_store_1_current` alias resolves to a
  real physical index holding 811 real documents; a sample document
  (`24-MB01`, "Joust Duffle Bag") has a real 768-element `embedding`
  array with real non-zero float values and a correctly ISO-8601-
  formatted `updated_at`.
- **End-to-end retrieval proof:** (1) a real, DI-resolved
  `HybridRetrievalServiceInterface::retrieve(1, 'duffle bag')` returned
  30 real candidates with real, distinct BM25 and vector scores — the
  three actual duffle-bag products ranked highest by BM25 (23.79/14.11/
  14.11), every candidate carrying a real cosine-similarity vector
  score in the 0.75-0.81 range; (2) a real HTTP POST (via curl with a
  real cookie jar, HTTPS, no application-layer scripting anywhere in
  the request path) to `/aichat/chat/send` with `{"message":"Show me
  some duffle bags"}` returned a genuine, product-specific JSON response
  — real SKUs/names/prices/URLs for the three real duffle bags, a
  natural-language message referencing them specifically, and
  `metadata.provider = "openai_compatible"` / `metadata.model =
  "qwen3.5:latest"` / `fallback_used = false`, proving the real,
  already-configured Ollama chat provider (Task 13) generated this
  response live, not a scripted or fallback path. This is the first
  time in this module's history that a real storefront chat message has
  produced a genuine, non-fallback, product-specific answer.
- **Verification:** full suite 1240 tests / 3010 assertions / 0
  failures (up from 1238/3006). `php -l`, `setup:upgrade`,
  `setup:di:compile`, `cache:flush` all clean. All live checks above
  run against real DI-resolved services, a real reindex, the real
  OpenSearch cluster, and a real unscripted HTTP round-trip — no
  "swap only the leaf" substitution was needed anywhere in this task,
  the first task in this module's history for which that's true.
- **Known gaps / TODOs left for later tasks:** the admin config-page
  error report remains genuinely unresolved — needs the user's exact
  error text/screenshot to make further progress, since every
  server-side reproduction technique available has been exhausted. The
  fabricated-admin-user reproduction technique used for diagnosis was
  blocked by this session's own permission classifier before it created
  anything, so no throwaway admin account was ever actually created —
  worth remembering if a future task needs a truly authenticated
  admin-page reproduction: it likely needs the user's own real
  credentials or an explicit one-time authorization, not a
  script-created account.
- **Not done / blocked:** Step 1 (admin config-page error) — blocked on
  the user's response.

### Task 16 — Live-testing fixes: structured-output reliability, configurable pricing/cart, widget resize (DONE)
- **Files:** `Model/Chat/ResponseContractFormatter.php` (new — always-
  included system message spelling out the required JSON shape) +
  `Test/Unit/Model/Chat/ResponseContractFormatterTest.php` (new);
  `Model/Chat/ChatEntryPipeline.php` (modified — new
  `ResponseContractFormatter` dependency, bounded 2-attempt
  self-correction retry on `malformed_response` specifically) +
  `Test/Unit/Model/Chat/ChatEntryPipelineTest.php` (modified — message-
  count assertions updated for the new leading system message, 3 new
  retry-behavior tests); `Model/Chat/Response/LlmResponseParser.php`
  (modified — strips a wrapping markdown code fence before parsing) +
  `Test/Unit/Model/Chat/Response/LlmResponseParserTest.php` (modified —
  3 new tests); `Model/Chat/ProductContextFormatter.php` (modified —
  strengthened wording against citing a product recognized from outside
  the given list); `Model/Revalidation/LiveRevalidationService.php`
  (modified — price resolution now goes through `getPriceInfo()`) +
  `Test/Unit/Model/Revalidation/LiveRevalidationServiceTest.php`
  (modified — price mocking rebuilt around a mocked `PriceInfoInterface`
  chain, 1 new test); `Model/Tool/AddToCartTool.php` (modified —
  configurable-product option resolution, 6 new constructor
  dependencies) + `Test/Unit/Model/Tool/AddToCartToolTest.php`
  (modified — 5 new configurable-flow tests); `view/frontend/templates/
  chat/{widget,widget-hyva}.phtml` (modified — resizable panel CSS);
  `app/code/Aavirbhava/ProductReports/etc/email_templates.xml`
  (modified — unrelated real bug fix, see below); 20 net new tests
  (full suite 1240 → 1253).
- **Unrelated bug found and fixed while investigating a user-reported
  admin config-page crash:** the crash had nothing to do with this
  module. `app/code/Aavirbhava/ProductReports/etc/email_templates.xml`
  declared a `<template>` element with an invalid `subject` attribute
  and a missing required `module` attribute — invalid against
  `Magento_Email`'s own XSD, and email-template config merges globally
  on every admin page load (via `Magento\Email\Model\Template\Config\Data`,
  reached whenever anything touches `Magento\Framework\Mail\Template\Factory`,
  not anything section-specific), so it crashed the *entire* admin
  config editor, not just this module's own section — reproduced via
  the real exception stack trace in the user's screenshot, which named
  the file directly. Fixed the XML (removed `subject`, added
  `module="Aavirbhava_ProductReports"`, corrected `area` to `frontend`
  to match where the referenced template file actually lives). That
  module is currently disabled in this environment (apparently as an
  earlier, unrelated workaround for this same crash), so the live
  symptom isn't currently reproducible either way — but the underlying
  XML defect is real and now fixed regardless, so the crash won't
  return if the module is ever re-enabled. Left the module's
  enabled/disabled state untouched; that's a separate decision.
- **Part A — structured-output reliability:** diagnosed via the real
  admin Playground's debug-collector plumbing that a real in-scope
  query ("show me latest men's wear") was reaching retrieval, ranking,
  and the LLM successfully — scope classification and retrieval were
  never the problem — but the local Ollama-served model (`qwen3.5`)
  answered the final round in free-text markdown prose instead of the
  requested JSON, correctly triggering `OutputValidator`'s
  `malformed_response` rejection. Reproduced directly against the real
  Ollama instance with the exact captured request payload: an identical
  `response_format: json_schema` request produces clean, correctly-
  shaped JSON for a short single-turn prompt, but ignores the schema
  once a prior tool-call/tool-result pair and richer product context
  are present — a genuine local-model limitation under this specific
  environment's real, current load conditions, not a defect in the
  request itself. Fixed with three complementary, all live-verified
  changes (each proven independently via direct replay against the real
  Ollama instance before being written into code): (1) an always-
  included system-message reinforcement of the exact required JSON
  shape (`ResponseContractFormatter`); (2) a bounded, single retry in
  `ChatEntryPipeline` specifically for `malformed_response` — never for
  a fabrication reason code, since retrying a hallucination could
  encourage another one, not fix a format problem; (3) markdown-code-
  fence tolerance in `LlmResponseParser`, defense in depth for the
  "valid JSON but wrapped in a fence" failure mode observed in a
  separate reproduction. A live, secondary finding surfaced during this
  work: the same local model sometimes cites a real, well-known Magento
  Luma demo-catalogue SKU it recognizes from its own training data
  rather than one actually shown in this store's retrieved candidates
  (confirmed directly: `MH01`/"Chaz Kangeroo Hoodie" was cited but never
  present in the real candidate set) — `OutputValidator`'s existing
  `fabricated_sku` check correctly caught and rejected this every time;
  strengthened `ProductContextFormatter`'s instruction wording to
  explicitly rule out citing anything recognized from outside the given
  list, which measurably reduced (not eliminated) how often this
  specific local model still tried — an accepted, honestly-reported
  residual limitation of this local model, not a defect this task's
  scope covers fixing further.
- **Part B — configurable product pricing:** root-caused directly
  against real live data (`MT07`, a real configurable product) that
  `Product::getPrice()` returns `0` and `Product::getFinalPrice()`
  happened to already resolve correctly for this Magento version, but
  `LiveRevalidationService::revalidateOne()` used the always-`0`
  `getPrice()` result as `RevalidatedProduct::$price` regardless.
  Replaced both `getPrice()`/`getFinalPrice()` calls with
  `getPriceInfo()->getPrice(RegularPrice::PRICE_CODE|FinalPrice::PRICE_CODE)
  ->getAmount()->getValue()`, which dispatches through the
  type-specific pricing model Magento's own PDP/catalog-listing already
  use for "As low as" (`ConfigurablePriceResolver` for configurable —
  confirmed live to resolve to the same real minimum-child price,
  `$22.00`, both mechanisms agree on for `MT07`), and resolves to the
  identical value `getPrice()`/`getFinalPrice()` already returned for a
  simple product — a strict generalization, not a new branch, so simple
  pricing is provably unchanged.
- **Part C — configurable product cart support:** the most involved
  change this task. `AddToCartTool` now loads the product once per call
  and, for a configurable product (`Product::getTypeId() ===
  Configurable::TYPE_CODE`), resolves the customer's stated
  `option_selection` free text against the product's own real
  attribute/value labels (`Configurable::getConfigurableAttributesAsArray()`)
  before ever touching the cart. Matching is case-insensitive and
  tolerant of extra words per comma-separated phrase (exact match, or
  the phrase containing the value's label — "pink one" matches "Pink")
  — deliberately one-directional (phrase contains label, never the
  reverse) so a longer, more specific value label can never be
  spuriously matched by a shorter phrase. Any phrase matching more than
  one distinct (attribute, value) pair — across different attributes or
  two values of the same one — is treated as ambiguous and rejected,
  never resolved by guessing. Once every required attribute resolves to
  exactly one value, the specific real child (`Configurable::getUsedProducts()`,
  cross-checked by attribute code) must both exist and be independently
  stock/salability-checked — deliberately *not* reused via
  `LiveRevalidationServiceInterface`, since a configurable child is
  legitimately not individually visible in the catalogue (sold *as* the
  parent with this selection) and that service's visibility gate would
  incorrectly reject a real, purchasable combination. Only once a
  specific, real, salable child is resolved does the tool proceed
  through the existing confirmation gate and actually save the cart
  item — built via Magento's own real configurable cart-item mechanism
  (`CartItemInterface::setProductOption()` with
  `ConfigurableItemOptionValueInterface` entries keyed by attribute id
  and value index, the identical shape `Magento\ConfigurableProduct\Model\Quote\Item\CartItemProcessor`
  uses for the REST cart-item API), keeping the *parent* SKU throughout
  rather than ever substituting the child SKU (matching how the real
  API expects a configurable add, and how the parent — not the
  individually-not-visible child — is what stays revalidatable). Every
  other outcome — no selection given yet, an unmatched/ambiguous
  phrase, an incomplete selection, a combination with no matching real
  child, a matching child that isn't currently salable — returns a
  distinct, honest status and never mutates the cart or guesses a
  value, matching this tool's existing fail-closed confirmation-gate
  philosophy from Task 7.
- **Part D — widget resize:** a pure CSS change on both templates —
  larger default size (400×600px, was 320×480px), `min-width`/
  `min-height`/`max-width`/`max-height` bounds, and native CSS `resize:
  both` (Luma's `<style>` block) / Tailwind's `resize` utility (Hyva's
  arbitrary-value classes). No JS changes — neither `chat-widget-core.js`
  nor the Luma/Hyva presentation-layer JS ever manipulated panel
  width/height, so there was nothing to reconcile with the new
  user-driven resizing.
- **Verification:** full suite 1253 tests / 3060 assertions / 0
  failures (up from 1240/3006 — +13 for Parts A-C, `php -l`/
  `setup:upgrade`/`setup:di:compile`/`cache:flush` all clean, run twice
  (once before, once after an unrelated mid-task infrastructure event —
  see below). **Live-verified, all four parts, against real data**: (1)
  the real `/aichat/chat/send` endpoint no longer returns
  `malformed_response` for "show me latest men's wear" — repeated real
  calls now return either a genuine, correctly-shaped, product-specific
  answer or a legitimate `fabricated_sku`/`fabricated_price` content
  rejection (this local model's own remaining, honestly-documented
  limitation), never the original format failure; (2) a real, DI-
  resolved `LiveRevalidationServiceInterface::revalidate()` against
  `MT07` (a real configurable product) now returns `price: 22` instead
  of `0`, matching the real "As low as" price Magento's own pricing
  framework independently resolves; (3) a real guest cart (created via
  `CartManagementInterface::createEmptyCart()`, cleaned up afterward)
  proved the full configurable add-to-cart flow end to end against real
  data: a call with no `option_selection` returned the real Size (XS-XL)
  and Color (Gray) values this specific product actually has; a call
  with `"M, gray"` (tolerant lowercase phrasing) resolved to and added
  the real child variant — confirmed directly via `quote_item` table
  rows: one `configurable`-type parent row plus one `simple`-type child
  row for SKU `MT07-M-Gray`, Magento's own standard shape for a
  configurable cart addition; a call with `"purple, XXXL"` (neither a
  real option for this product) was correctly rejected
  `invalid_option` with zero additional cart rows; (4) the real
  storefront homepage now serves the resizable panel CSS
  (`resize: both`, the new 400×600px default, the new min/max bounds)
  in place of the old fixed-size rule.
- **Incidental infrastructure event during verification:** partway
  through this task's live checks, all Docker containers exited
  unexpectedly (OpenSearch specifically with signal 137/SIGKILL, the
  classic OOM signature) — real, sustained host memory pressure from
  this task's own repeated real local-LLM inference calls (a trivial
  single-word completion measured at ~50 seconds under load in this
  environment). Restarted cleanly via `bin/start`; confirmed no data
  loss (the real OpenSearch index's 811 documents were intact
  afterward) and re-ran the full container verification sequence
  (`setup:upgrade`/`setup:di:compile`/`cache:flush`/full test suite)
  again after the restart, all clean. Not a defect in this module —
  flagged here since local LLM inference load is a real, current
  constraint on this specific host that future live-check-heavy tasks
  should pace around (fewer, more deliberate real LLM calls; prefer
  direct DI-resolved verification of the non-LLM parts of the pipeline
  where the LLM boundary itself isn't what's being tested, exactly as
  Part C's cart verification did here).
- **Known gaps / TODOs left for later tasks:** the local model's
  occasional citation of a real-but-not-actually-retrieved SKU
  (Part A's secondary finding) is reduced but not eliminated by the
  strengthened instruction wording — a residual, honestly-documented
  limitation of running this specific local model against this
  module's real pipeline, not something believed fixable by further
  prompting alone. `FullProductReindexer`'s successful runs appear to
  leave prior run-indices behind in OpenSearch (5 accumulated
  `aavirbhava_ai_product_rag_store_1_run_*` indices were observed by
  this task, only the alias-pointed one live) — noticed incidentally
  during this task's own verification, not investigated or fixed here,
  since it's unrelated to any of this task's four parts.
- **Skill files updated:** `references/progress-log.md` — status rows
  6, 8, 9, and 12 updated; header summary updated; this Task 16 history
  entry added; "Next up" section's residual-gaps list updated.
- **Not done / blocked:** nothing blocked; all four parts completed
  and live-verified.

### Task 17 — Diagnose "back to fallback" regression, fix the real bug, add console debug logging (DONE)
- **Files:** `Model/Tool/GetCartTool.php` (modified — `inputSchema()`'s
  `properties` now `new \stdClass()`, was `[]`) + `Test/Unit/Model/
  Tool/GetCartToolTest.php` (modified — 1 new regression test);
  `Model/Provider/Llm/AbstractChatProvider.php` (modified —
  `buildTool()`'s own empty-parameters fallback default, same fix) +
  `Test/Unit/Model/Provider/Llm/OpenAiProviderTest.php` (modified — 1
  new regression test); `view/frontend/web/js/chat-widget-core.js`
  (modified — `console.debug` logging in the shared `sendMessage()`).
  2 net new tests (full suite 1253 → 1255).
- **Diagnosis — confirmed, not assumed:** reproduced the reported
  regression directly through the real `/aichat/chat/send` endpoint —
  `reason_code: assistant_unavailable`, exactly as reported. But the
  response came back in **0.2 seconds**, not anywhere near the
  20-second configured `llm/timeout_seconds` — immediately ruling out
  Task 16's own flagged timeout-mismatch concern as the cause of *this*
  specific regression, before writing a single line of config or code
  toward that theory. Confirmed independently that a genuine real
  local-model call still works and completes in real time (a direct,
  unrelated `ChatGenerationServiceInterface::chat()` call succeeded in
  18.4s) and that every container (including OpenSearch, still holding
  its real 811 documents) and Ollama itself were healthy post-restart —
  so the near-instant failure had to be something failing before any
  real network attempt, not a slow/unavailable backend. A cache check
  of the real `CircuitBreakerInterface` state for store 1 (both
  `primary` and `fallback` roles) came back clean/closed, ruling out a
  stuck circuit breaker too. Revealed the real exception via the same
  temporary-debug-instrumentation-then-immediately-revert technique
  this module has used since Task 9: `ProviderInvalidResponseException`
  from an HTTP **400**, not a timeout exception at all. Captured the
  real outgoing request body and Ollama's real response
  (`"Value looks like object, but can't find closing '}' symbol"`),
  and found the actual defect: `GetCartTool::inputSchema()` returns
  `'properties' => []`, which `json_encode()` serializes as a JSON
  *array*, not an *object* — invalid against JSON Schema's own spec for
  the `properties` keyword, which must be an object even when empty.
  OpenAI's real API tolerates this common PHP-originated quirk; this
  environment's real Ollama instance does not, and rejects the *entire*
  chat request (not just the malformed tool) the moment `get_cart` is
  offered in the tools array — which only happens when
  `guardrails.cart_mutations_enabled` is on, explaining why this
  specific regression hadn't been caught by earlier tasks' live checks
  (most ran with that guardrail at its default-disabled state; Task
  16's own Part C testing left it enabled at store-view scope, which is
  where this session's config already stood). Live-confirmed the exact
  fix before writing it: replaying the identical captured request with
  only `get_cart`'s `properties` changed from `[]` to `{}` succeeded
  (HTTP 200) against the real Ollama instance.
- **Fix:** `GetCartTool::inputSchema()`'s `properties` value changed to
  `new \stdClass()` (always encodes as `{}`, matching every other
  tool's non-empty `properties` map). Also fixed the identical pattern
  in `AbstractChatProvider::buildTool()`'s own fallback default for a
  tool missing `parameters` entirely — unreachable in practice today
  (every real tool always supplies a full `inputSchema()`), fixed for
  consistency/defense-in-depth against the same class of bug recurring
  in a future tool. Two new regression tests assert on the *raw* JSON
  string (not `json_decode(..., true)`, which round-trips both `{}`
  and `[]` back to an identical PHP `[]` and would never have caught
  this) — one directly on `GetCartTool::inputSchema()`'s output, one on
  the full encoded request body a zero-argument tool produces through
  `AbstractChatProvider`.
- **No timeout/config change was needed.** Task 16's own flagged
  concern (a local model measured at ~50s under load against a 20s
  configured timeout) is a real, distinct, still-true observation about
  this host's performance characteristics, but it was not the cause of
  *this* regression — confirmed by the sub-second failure time, and by
  today's real, successful calls completing in 13-19 seconds, under the
  existing 20-second timeout, without any timeout change. Per the
  task's own explicit instruction to report plainly rather than code
  around it: this host's real local-model latency (measured today:
  13-19s for a real multi-tool-definition chat completion; Task 16
  separately measured ~50s for a trivial prompt under different load
  conditions) means sub-few-second response times are not realistic
  for this specific local/CPU-inference setup — a genuine hardware/
  environment characteristic, not a defect to fix in code.
- **Console debug logging:** added directly inside `chat-widget-core.js`'s
  shared `sendMessage()` — a `console.debug` call before the fetch
  (the outgoing message) and one after the response is parsed (HTTP
  status/ok, `reason_code`, `metadata`, `awaiting_confirmation`, and
  the full raw response body), plus a third if the fetch itself
  rejects (network failure), which re-throws afterward so existing
  `.catch()` behavior in both `chat-widget-luma.js` and
  `chat-widget-hyva.js` is completely unchanged. Centralizing this in
  the one shared core function — rather than duplicating logging calls
  in each presentation layer — means both themes get it for free with
  a single, minimal diff. No `general.debug_logging` admin toggle
  exists anywhere in this module (confirmed by a full-codebase search,
  not assumed), so per the task's own explicit fallback instruction
  this is always-on, ungated — browser console output carries no
  customer-facing harm the way UI text would.
- **Verification:** full suite 1255 tests / 3063 assertions / 0
  failures (up from 1253/3060). `php -l`/`setup:upgrade`/
  `setup:di:compile`/`cache:flush` all clean. **Live-verified, real
  data, no scripting/stubbing at any layer**: (1) the real
  `/aichat/chat/send` endpoint, reproducing the exact original report
  ("show me some duffle bags"), now returns a genuine, product-specific
  answer (`reason_code: null`, 3 real duffle bags with real
  prices/URLs) instead of `assistant_unavailable`, in 13.6 real
  seconds; repeated with a cart-tool-eligible message ("add a joust
  duffle bag to my cart") twice more, both succeeded cleanly; (2) a
  real headless Chrome session (Playwright driving the system's
  installed Chrome via CDP, not a fabricated/mocked browser
  environment) loaded the real storefront homepage, opened the real
  chat widget, sent a real message, and captured the real
  `console.debug` output verbatim: `"sending message"` with the typed
  text, followed by `"response received"` with real `status: 200`,
  `ok: true`, `reasonCode: null`, and a real populated `metadata`
  object — proving the logging works in an actual browser, not just by
  code inspection.
- **Known gaps / TODOs left for later tasks:** none newly introduced.
  Task 16's own flagged local-model-latency observation stands as a
  real, ongoing environment characteristic (see above) — future tasks
  doing live-check-heavy work against this specific host should keep
  expecting real local-LLM calls to take low double-digit seconds, not
  sub-second, and should pace real calls accordingly (a lesson Task 16
  already flagged and this task's own diagnosis independently
  corroborated, though it was not the actual cause of this task's own
  regression).
- **Skill files updated:** `references/progress-log.md` — status rows
  6 and 12 updated; header summary updated; this Task 17 history entry
  added.
- **Not done / blocked:** nothing blocked.

### Task 18 — Markdown rendering and product cards for descriptive answers (DONE)
- **Files:** `view/frontend/web/js/chat-widget-core.js` (modified —
  new shared `renderMarkdown()`/`escapeHtml()` exports);
  `view/frontend/web/js/chat-widget-luma.js` (modified — assistant
  bubble HTML now built via `core.renderMarkdown()`);
  `view/frontend/web/js/chat-widget-hyva.js` (modified — assistant
  message objects gain an `html` field via `renderMarkdown()`, user
  messages gain an empty `html` placeholder);
  `view/frontend/templates/chat/widget-hyva.phtml` (modified — split
  the single `x-text` binding into a user-only `x-text` and an
  assistant-only `x-html`); `Model/Chat/ResponseContractFormatter.php`
  (modified — explicit instruction that `product_skus` covers
  descriptive/informational answers, not only recommendations) +
  `Test/Unit/Model/Chat/ResponseContractFormatterTest.php` (modified —
  1 new test). No PHP test infrastructure exists for the widget's JS
  (a known, previously-flagged gap — Task 11); the JS changes were
  verified with a standalone Node harness and a real headless-Chrome
  session instead (see Verification). 1 net new PHP test (full suite
  1255 → 1256).
- **Part A — markdown rendering design:** a new `renderMarkdown(text)`
  in the shared `chat-widget-core.js` handles exactly the patterns
  actually seen in real responses from this module's own LLM output —
  `**bold**`, `-`/`*` bullet lists, `1.` numbered lists, and blank-line
  paragraph breaks — deliberately not a general markdown parser (no
  links, headings, code blocks, since none of those appear in what
  this module's response contract actually produces). Safety-critical
  ordering: the raw text is passed through the existing `escapeHtml()`
  (already used elsewhere in this module for LLM-sourced strings)
  *first*, and every tag the formatter subsequently injects
  (`<strong>`, `<ul>`, `<li>`, `<p>`, `<br>`) is a fixed literal string
  the function itself controls — a regex capture group is only ever
  placed as already-escaped, inert *content* between tags, never used
  to construct a tag name or attribute, so nothing the model writes can
  introduce real HTML; only the literal `**`/`-`/`1.` characters this
  function specifically recognizes get converted. Centralized in the
  shared core file (not duplicated per theme) since both presentation
  layers need byte-identical formatting logic — Luma swaps its old
  `'<p>' + escapeHtml(...) + '</p>'` construction directly for the new
  HTML string; Hyva keeps its existing `x-text="entry.text"` binding
  for the customer's own typed messages (unchanged — no markdown
  interpretation of what a customer types) and adds a parallel
  `x-html="entry.html"` binding for assistant messages only, gated by
  `x-show` on `entry.role`.
- **Part B — product cards for descriptive answers, root cause and
  fix:** reproduced the reported case directly ("what are yoga pants
  made of") and confirmed real informational answers can name several
  real products in the free-text `message` while leaving `product_skus`
  partially or entirely empty. Traced this to `ResponseContractFormatter`'s
  instruction text, which described the *shape* of `product_skus`
  ("array of {sku, reason}, only SKUs you were actually shown") but
  never said *when* to populate it — leaving the model free to infer
  "only for recommendations," which local models in this environment
  have repeatedly shown (Tasks 16-17) to interpret more literally/
  narrowly than intended. Added an explicit paragraph: any product the
  message names, describes, compares, or discusses belongs in
  `product_skus`, with an example matching the exact reported phrasing
  ("what is X made of"). This is a prompting change only —
  `OutputValidator`'s `fabricated_sku` fail-closed check is completely
  unchanged, so a response still cannot claim a `product_skus` entry
  outside the live-revalidated set; this instruction only asks the
  model to use the field more completely when it already has a real,
  legitimate product in view.
- **Verification:** full suite 1256 tests / 3065 assertions / 0
  failures (up from 1255/3063). `php -l`/`setup:upgrade`/
  `setup:di:compile`/`cache:flush` all clean. JS logic verified with a
  standalone Node harness exercising `renderMarkdown()` directly
  (bold/bullet/numbered/paragraph cases, plus two explicit XSS cases —
  a raw `<script>` tag and an `<img onerror>` payload, both correctly
  neutralized to inert escaped text with formatting still applied
  around them) before any live check. **Both parts live-verified
  together in one real headless-Chrome session** (Playwright driving
  this machine's actual installed Chrome, not a fabricated browser):
  opened the real chat widget on the real storefront homepage, sent
  the exact reported query, and inspected the real rendered DOM —
  genuine `<strong>` and `<ul>` tags present, zero raw `**` characters
  left anywhere in the bubble, and 8 real, live-revalidated product
  cards (real prices, real URLs) rendered alongside text that discusses
  exactly those products by name. Separately ran the same query
  several more times via direct HTTP calls to characterize Part B's
  real-world consistency (see Known gaps).
- **Known gaps / TODOs left for later tasks:** Part B's fix
  demonstrably works (multiple real runs returned the complete,
  correct product set) but does not reach 100% compliance with this
  environment's local model — repeated runs of the identical query
  varied from 0 to 8 populated products across 5 real attempts. This
  is consistent with, not a new instance beyond, this local model's
  already-documented pattern of imperfect instruction-following under
  real conversational load (Tasks 16-17); not believed fixable by
  further prompting alone within this task's scope. Still no PHP/JS
  test infrastructure exists for the widget's JS itself (Task 11's
  original gap, unchanged) — this task's JS verification relied on a
  standalone Node harness and a real browser session rather than a
  project-integrated test suite.
- **Skill files updated:** `references/progress-log.md` — status rows
  8 and 12 updated; header summary updated; this Task 18 history entry
  added.
- **Not done / blocked:** nothing blocked.

### Task 19 — Product links in a new tab, conversation persistence across reload/new tab (DONE)
- **Files:** `Model/Chat/ConversationHistoryViewBuilder.php` (new —
  filters raw stored history down to what a customer actually saw) +
  `Test/Unit/Model/Chat/ConversationHistoryViewBuilderTest.php` (new,
  3 tests); `Controller/Chat/History.php` (new — `GET
  /aichat/chat/history`) + `Test/Unit/Controller/Chat/HistoryTest.php`
  (new, 4 tests); `Block/Frontend/ChatWidget.php` (modified — new
  `getHistoryUrl()`) + `Test/Unit/Block/Frontend/ChatWidgetTest.php`
  (modified, 1 new test); `view/frontend/web/js/chat-widget-core.js`
  (modified — new `fetchHistory()`); `view/frontend/web/js/
  chat-widget-luma.js` (modified — history restoration on init,
  `target="_blank" rel="noopener noreferrer"` on the product link);
  `view/frontend/web/js/chat-widget-hyva.js` (modified — same two
  changes); `view/frontend/templates/chat/widget.phtml` (modified —
  new `data-history-url` attribute); `view/frontend/templates/chat/
  widget-hyva.phtml` (modified — `historyUrl` threaded into the Alpine
  component config, `target="_blank" rel="noopener noreferrer"` on the
  product link). 8 net new PHP tests (full suite 1256 → 1264). No PHP/
  JS test infrastructure exists for the widget's JS itself (Task 11's
  original gap, unchanged) — the JS changes were verified with a
  standalone Node harness and two real headless-Chrome sessions
  instead, the same split this module has used for its JS since
  Task 18.
- **Product links in a new tab:** `target="_blank" rel="noopener
  noreferrer"` added to the product-title anchor in both Luma's
  `renderProductCard()` and Hyva's template — `rel="noopener
  noreferrer"` is not optional decoration: without it, a page opened
  via `target="_blank"` gets a live `window.opener` reference back to
  the storefront tab, which the opened page's own script (fully
  outside this module's control) could use to navigate the original
  tab elsewhere — a well-known phishing vector `noopener` closes.
- **Conversation persistence design:** the actual gap was never
  "nothing remembers the conversation" — `ConversationHistoryStoreInterface`
  has persisted every turn server-side since Task 8, keyed by a
  `ChatSession`-held conversationId already living in Magento's own
  session cookie. The gap was purely on the frontend: nothing ever
  asked the backend for that history when the widget's JS state
  started fresh on every page load. Closed with a new read-only `GET
  /aichat/chat/history` plus a JS restore call on init, rather than any
  new client-side storage — since the session cookie is already shared
  by every tab of the same browser, this single mechanism naturally
  covers both "reload the current tab" and "open a new tab" (the
  scenario the request specifically called out, alongside the new
  product-tab links) with no extra coordination.
- **Why raw stored history isn't served directly:** `recentMessages()`
  returns every persisted `ChatMessage`, including the intermediate
  `assistant` messages with `toolCalls` set and empty content (a
  tool-call request) and `tool`-role messages (the raw tool result
  JSON) a real round-trip produces — internal plumbing a customer never
  actually saw, kept only so the LLM has full context on a later turn.
  Serving that raw list to the frontend would both leak internal
  tool-call arguments/results and render as visible noise in the
  transcript. `ConversationHistoryViewBuilder` filters to exactly the
  two kinds of message a customer actually saw — their own `user`
  messages, and the final `assistant` message of each turn (content
  non-empty, no toolCalls, mutually exclusive by `ChatMessage`'s own
  constructor invariant) — reconstructing the real transcript, not a
  debug dump of it.
- **Scope decision, stated explicitly:** restoring past turns'
  structured product cards, follow-up-question buttons, or the
  confirmation affordance was deliberately not attempted — only the
  final response *text* is persisted per turn, never the full
  `AssistantResponse` (with its live-revalidated prices/URLs) those UI
  elements were built from at the time. A reload/new tab restores the
  conversation's readable text faithfully; it does not replay a
  point-in-time product card whose price/stock could since be stale.
  Doing so would require persisting the full structured response per
  turn (a real schema change), out of this task's scope.
- **`History` controller design, why it never allocates a fresh
  identity:** deliberately reads `ChatSession::getConversationId()`
  directly rather than `ChatIdentityResolverInterface::resolve()`
  (Task 8) — `resolve()` always returns *some* id, minting a brand new
  one via `random_bytes()` if none exists yet, and (when
  `cart_mutations_enabled`) auto-vivifies a real guest quote as a side
  effect. Since the widget calls this on every single page load, going
  through `resolve()` would mean every storefront pageview from every
  visitor — including one who has never opened the chat widget —
  silently created session state and, for many stores, a real database
  row. No conversation id yet means nothing to restore, by definition;
  the controller returns an empty list without touching identity
  allocation at all. Every other failure mode (assistant disabled, any
  unexpected exception) also degrades to an empty list rather than an
  error — a restore failing is a lost convenience, not a broken page.
- **Verification:** full suite 1264 tests / 3077 assertions / 0
  failures (up from 1256/3065). `php -l`/`setup:upgrade`/
  `setup:di:compile`/`cache:flush` all clean. JS logic verified with a
  standalone Node harness (`fetchHistory()` correctly filters out
  `tool`-role and empty messages, and degrades to an empty array,
  never throwing, on a simulated network failure) before any live
  check. **Live-verified end to end with real data, no scripting at
  any layer**: (1) a real, fresh session's `GET /aichat/chat/history`
  returned `{"messages": []}`; (2) after a real message sent through
  `/aichat/chat/send` in the same cookie jar, the same history call
  returned exactly the real user message and the real assistant reply
  — nothing else; (3) a full real-browser session (Playwright driving
  the machine's actual installed Chrome) sent a real message, reloaded
  the page, reopened the widget, and found the identical two-message
  transcript restored; opening a brand new tab in the same browser
  context showed the exact same transcript again, with zero extra
  client-side wiring; (4) the same session confirmed a real rendered
  product-card link carries `target="_blank"` and
  `rel="noopener noreferrer"` in the live DOM.
- **Known gaps / TODOs left for later tasks:** past turns' product
  cards/follow-ups/confirmation affordance are not restored on reload
  (see Scope decision above) — a real, deliberate scope boundary, not
  an oversight; if a future task wants full-fidelity restoration, it
  would need to persist the structured `AssistantResponse` per turn,
  not just its message text. Still no PHP/JS test infrastructure for
  the widget's JS itself (Task 11's original gap, unchanged).
- **Skill files updated:** `references/progress-log.md` — status rows
  1, 6, and 12 updated; header summary updated; this Task 19 history
  entry added.
- **Not done / blocked:** nothing blocked.

### Task 20 — Product links + SKU de-emphasis, markdown italics, full-fidelity history restore (DONE)
- **Files:** `etc/db_schema.xml` (modified — new nullable
  `response_payload` column on `aavirbhava_ai_conversation_message`);
  `Model/Chat/StoredConversationMessage.php` (new — a read-model DTO
  for UI-restore, deliberately separate from `Dto\ChatMessage`) +
  `Test/Unit/Model/Chat/StoredConversationMessageTest.php` (new, 5
  tests); `Api/Chat/ConversationHistoryStoreInterface.php` (modified —
  `appendTurn()` gained an optional 5th param, new
  `recentMessagesWithResponsePayloads()` method); `Model/Chat/
  DbConversationHistoryStore.php` (modified — implements both
  interface changes, extracted a shared `fetchRows()` helper) — its
  only implementer; `Model/Chat/ChatResponseSerializer.php` (modified —
  extracted a new public `serializeDisplayPayload()`, reused internally
  by `serialize()` too, so there is exactly one place this shape is
  built) + `Test/Unit/Model/Chat/ChatResponseSerializerTest.php`
  (modified, 1 new test); `Model/Chat/ChatEntryPipeline.php` (modified
  — new `ChatResponseSerializer` dependency, passes the serialized
  display payload into `appendTurn()`) + `Test/Unit/Model/Chat/
  ChatEntryPipelineTest.php` (modified, 1 new test); `Model/Chat/
  ConversationHistoryViewBuilder.php` (rewritten — reshapes
  `StoredConversationMessage` instead of filtering `ChatMessage`) +
  `Test/Unit/Model/Chat/ConversationHistoryViewBuilderTest.php`
  (rewritten, same 3-test count); `Controller/Chat/History.php`
  (modified — return-shape docblocks only) + `Test/Unit/Controller/
  Chat/HistoryTest.php` (modified, same 4-test count); `Test/
  Integration/Model/Chat/DbConversationHistoryStoreDatabaseTest.php`
  (modified, 3 new tests against the real database);
  `view/frontend/web/js/chat-widget-core.js` (modified — `renderMarkdown()`
  gained italic support, `fetchHistory()` now normalizes and carries
  through products/follow_up_questions per entry); `view/frontend/web/js/
  chat-widget-luma.js` (modified — SKU span in `renderProductCard()`,
  history restoration now calls the same `appendAssistantResponse()`
  a live turn uses); `view/frontend/web/js/chat-widget-hyva.js`
  (modified — same two changes); `view/frontend/templates/chat/
  widget.phtml` (modified — `.aavirbhava-chat-product-sku` CSS);
  `view/frontend/templates/chat/widget-hyva.phtml` (modified — SKU
  span next to the product name). 10 net new PHP tests (full suite
  1264 → 1271 unit + 3 new integration tests, run separately). JS
  changes verified with a standalone Node harness plus real
  headless-Chrome sessions, the same split this module has used for
  its JS since Task 18 (still no PHP/JS test infrastructure for the
  widget's own JS — Task 11's original gap, unchanged).
- **Product links + SKU de-emphasis:** the product name was already a
  real `target="_blank" rel="noopener noreferrer"` link as of Task 19
  — re-confirmed by inspection before assuming otherwise. What neither
  theme actually did, despite `product.sku` already being available
  client-side, was render the SKU anywhere at all. Added a small,
  visually secondary SKU span next to the (already-linked) name in
  both themes — the fix here was adding a de-emphasized SKU display
  from scratch, not toning down an existing prominent one.
- **Markdown italics, and the classic regex trap avoided:**
  `renderMarkdown()` only converted `**bold**`; a single `*italic*`
  passed through literally. Added a second regex pass for single-
  asterisk emphasis, applied strictly AFTER the bold pass — the reason
  order matters: by the time the italic regex runs, every `**...**`
  sequence in the string has already been consumed and replaced with a
  real `<strong>` tag, so no `**` sequence is left for a naively-run
  italic regex to misparse as two adjacent single-asterisk matches.
  This is the standard, minimal fix for the well-known "single-asterisk
  regex matches inside double-asterisk bold" trap — no lookahead/
  lookbehind trickery needed, just doing bold first and letting it fully
  consume what it matches.
- **Full-fidelity history restore — the real design work this task
  did:** Task 19 deliberately scoped out restoring product cards for
  past turns, since only a turn's final message *text* was ever
  persisted, not the full `AssistantResponse` (with its live-
  revalidated products/follow-up-questions/actions) a live turn's
  response carries. This task closes that gap by persisting the
  *display* payload alongside the turn's final message: `ChatResponseSerializer`
  gained a public `serializeDisplayPayload(AssistantResponse $response)`
  — extracted from `serialize()` itself, so `serialize()` now calls it
  internally too, guaranteeing there is exactly one place this shape
  is ever built, never two that could drift apart — and
  `ChatEntryPipeline` now passes its output into `appendTurn()`'s new
  optional 5th parameter, `?array $lastMessageResponsePayload`,
  documented as attached only to the *last* message in the turn (the
  final, customer-visible assistant reply — never the intermediate
  tool-call-request/tool-result messages the same call also persists).
  A new `response_payload` mediumtext column stores it as JSON,
  written only on that last row.
- **Why a new DTO (`StoredConversationMessage`) instead of extending
  `ChatMessage`:** `ChatMessage` is the DTO threaded into every real
  LLM request — `recentMessages()` still returns it unchanged, feeding
  `ChatRequest`'s conversation array directly. Adding a UI-only
  products/follow-ups/actions field to `ChatMessage` would mean that
  data either gets serialized into the actual LLM request (wasting
  real token budget on data the model doesn't need to reconsider — it
  already decided these products once) or the field sits unused on
  every non-restore code path, a wart on a DTO that has stayed
  deliberately pure everywhere else. A new, restore-purpose-built DTO
  (`recentMessagesWithResponsePayloads()`, a separate interface
  method) keeps the two concerns — "what the LLM needs for context"
  and "what the UI needs to redraw a past turn" — from ever bleeding
  into each other.
- **`StoredConversationMessage`'s own constructor does the
  intermediate-message filtering, so nothing else has to:** its
  constructor only accepts `user`/`assistant` roles and requires
  non-empty content — a `tool`-role row, or an intermediate assistant
  tool-call-request row (content is legitimately empty on those, by
  `ChatMessage`'s own existing invariant), simply cannot be represented
  by this type. `DbConversationHistoryStore::rowToStoredMessage()`
  tries to build one per row and skips any that fail — the exact
  same "try to construct, skip on InvalidArgumentException" pattern
  `rowToMessage()` already used for the LLM-context read path, applied
  here to guarantee only genuinely customer-visible messages ever
  reach `ConversationHistoryViewBuilder`, with no separate, parallel
  filtering logic to keep in sync.
- **Verification:** full suite 1271 unit tests / 3093 assertions / 0
  failures (up from 1264/3077), plus 3 new DB-integration tests (9
  total, run separately per this module's own convention) proving the
  real column round-trips correctly and real tool/intermediate rows are
  excluded from a real restore query. `php -l`/`setup:upgrade`
  (applied the new column against the real database — confirmed via
  `DESCRIBE`)/`setup:di:compile`/`cache:flush` all clean. **Live-verified,
  one real browser session, real local-model responses, no
  scripting**: (1) a real "what are yoga pants made of" query rendered
  genuine `<strong>` tags and zero raw `**` in the DOM, a real product
  card with `target="_blank"`/`rel="noopener noreferrer"` pointing at
  the real product URL, and a visible, separate SKU span; (2)
  reloading that same tab and reopening the widget restored the exact
  same 2-message transcript with all 7 real product cards intact and
  markdown still correctly rendered on the restored bubble — the
  literal gap Task 19 had left open, now closed; (3) a second, entirely
  separate browser context (different cookies, a different real
  session) saw zero messages, confirming restore is still correctly
  session-scoped, never leaking across sessions; (4) `*italic*`,
  `**bold**`, both together in one message, and an XSS payload
  alongside them were all exercised directly against the real,
  deployed `chat-widget-core.js` in a live browser tab (not a Node
  simulation) — italics and bold both render correctly together, the
  XSS payload is neutralized to inert escaped text.
- **Known gaps / TODOs left for later tasks:** none newly introduced.
  Still no PHP/JS test infrastructure for the widget's own JS (Task
  11's original gap, unchanged) — this task's JS verification again
  relied on a standalone Node harness and real browser sessions rather
  than a project-integrated test suite.
- **Skill files updated:** `references/progress-log.md` — status rows
  6 and 12 updated; header summary updated; this Task 20 history entry
  added.
- **Not done / blocked:** nothing blocked.

### Task 21 — Widget UI/UX overhaul: styling, admin colors, product images, resize/minimize, floating-button fix (DONE)
- **Files:** New — `Api/Config/AppearanceConfigInterface.php`,
  `Model/Config/AppearanceConfig.php` (the two new color settings'
  DTO/interface, mirroring `GeneralConfigInterface`/`GeneralConfig`'s
  own split). Modified — `Model/Config/Path.php` (3 new config paths),
  `Api/Config/ConfigurationReaderInterface.php` (new `readAppearance()`
  method), `Model/Config/ConfigurationReader.php` (implementation + a
  new `readColor()` helper that only accepts strict `#rgb`/`#rrggbb`
  hex, dropping anything else rather than emitting merchant-entered
  text as raw CSS), `etc/adminhtml/system.xml` (new "Appearance" group,
  3 text fields), `Block/Frontend/ChatWidget.php` (3 new color getters +
  `getColorCustomPropertiesStyle()`, same page-never-breaks discipline
  as `isAssistantEnabled()`), `Model/Revalidation/RevalidatedProduct.php`
  (new optional trailing `?string $imageUrl = null` — the established
  pattern for extending this DTO without touching its 19 existing call
  sites), `Model/Revalidation/LiveRevalidationService.php` (new
  `resolveImageUrl()`, image resolution failures degrade to null rather
  than dropping an otherwise-valid product), `Model/Chat/
  ChatResponseSerializer.php` (`serializeProduct()` gained `image_url`
  — flows into both the live response and the persisted display payload
  used for history restore, with zero extra wiring, since both already
  share this one method), `view/frontend/templates/chat/{widget,
  widget-hyva}.phtml` (styling refresh, color CSS custom properties,
  resize handle markup, minimize button, product-image markup),
  `view/frontend/web/js/chat-widget-core.js` (`normalizeProduct()`
  gained `imageUrl`), `view/frontend/web/js/chat-widget-luma.js` +
  `chat-widget-hyva.js` (class-based open/minimize state, custom
  top-left resize drag, image rendering, `sessionStorage` persistence,
  auto-scroll for Hyva). Tests modified — `Test/Unit/Model/Revalidation/
  RevalidatedProductTest.php` (+3), `Test/Unit/Model/Revalidation/
  LiveRevalidationServiceTest.php` (+2), `Test/Unit/Model/Config/
  ConfigurationReaderTest.php` (+3), `Test/Unit/Block/Frontend/
  ChatWidgetTest.php` (+3), `Test/Unit/Model/Chat/
  ChatResponseSerializerTest.php` (assertions extended, no new test).
  11 net new PHP tests (1271 → 1282 unit, 3093 → 3125 assertions).
- **Styling, admin colors:** both templates got a subtle gradient
  header/toggle, a two-layer box-shadow on the panel, and tighter
  spacing/typography on bubbles and product cards. The two new
  `Appearance` fields (window/header color; message bubble background +
  text color, deliberately separate settings) are read once per
  request by `ChatWidget`, validated to strict hex syntax server-side,
  and emitted as an inline `style="--aavirbhava-primary-color:...;..."`
  attribute on each template's root element — every color-dependent CSS
  rule reads `var(--aavirbhava-primary-color, #1979c3)` (etc.), so an
  unconfigured field silently falls back to the original blue/gray with
  no conditional logic in the templates themselves. Hyva keeps its own
  Tailwind-utility-class paradigm for everything else; only the
  color-dependent rules live in a small co-located `<style>` block,
  matching Luma's own existing pattern rather than fighting Tailwind's
  static utility system for something it can't express dynamically.
- **A real bug found and fixed while wiring color hover states:** the
  toggle button's `:hover` rule originally only set `transform`/
  `box-shadow`, no longer restating `background` — Luma's own global
  `button:hover` reset (`styles-m.css`) has higher CSS specificity
  (element + pseudo-class) than a bare `.aavirbhava-chat-toggle` class
  selector, so hovering the toggle silently fell back to Luma's flat
  gray button color instead of the configured gradient. Caught via a
  live headless-Chrome check comparing hovered vs. non-hovered computed
  styles (the non-hovered gradient was correct; only the hovered state
  was wrong), not by static inspection. Fixed by restating the
  background explicitly inside the `:hover` rule on both themes (Hyva
  had no equivalent global reset to trigger this, but the restatement
  was added defensively there too, since Hyva can't be live-verified in
  this environment).
- **Product images — the real design work here:** `RevalidatedProduct`
  gained a live-resolved `imageUrl`, following the exact discipline
  already established for price/URL (never LLM-sourced). The first
  implementation used `Magento\Catalog\Helper\Image::init()->getUrl()`
  (the pattern this module's own research notes had flagged as
  standard) — live-verified via a real chat round-trip to return a
  broken placeholder URL (`.../placeholder/.jpg`, empty filename) for
  products that DO have a real image, despite Luma's own PDP/category
  pages rendering those same products correctly. Root-caused to
  `Helper\Image`'s eager, synchronous file-existence-and-resize path
  (deprecated for exactly this reason) behaving differently outside a
  full block/layout render than Magento's modern, non-deprecated
  `Magento\Catalog\Block\Product\ImageFactory` — the same lazy
  URL-building mechanism Luma's own templates use via `$block->
  getImage()`, which never touches the filesystem at build time and
  lets the real resize happen on first HTTP request to the URL, exactly
  like every other product image on the store. Switched to
  `ImageFactory`, which immediately produced correct real image URLs
  live-verified with `naturalWidth` confirming the actual bytes loaded,
  not just a 200 status. Uses `product_small_image` (135×135, Luma's
  own conversion) — sized between the 75×75 thumbnail (too small next
  to a name/price) and the 240×300 category grid image (would dominate
  a narrow chat bubble).
- **A much larger pre-existing catalog gap discovered and fixed as a
  byproduct:** live-testing the image feature surfaced that most
  product images across the ENTIRE catalog, not just the chat widget,
  were rendering Magento's placeholder — including on PDP pages, which
  don't go through this module's code at all. Root-caused to the
  earlier "install sample data" task's manual recovery (`cp -rn` from
  the vendor package into `pub/media/catalog/product/`) never
  replicating Magento's own import-time collision-dedup behavior:
  751 of the catalog's 795 distinct DB-referenced image filenames
  use a `_1`/`_2`/etc. suffix (e.g. `mp09-blue_main_1.jpg`) that the
  vendor package itself never ships under that exact name — only the
  unsuffixed base file (`mp09-blue_main.jpg`) exists there, with
  Magento's own installer normally creating the suffixed copies at
  import time to avoid literal filename collisions between different
  product/gallery entries that happen to share a source image. That
  prior task's own live verification ("~70 of 795 images missing,
  affecting ~137 WSH*-prefixed products") undercounted the true gap by
  an order of magnitude and misattributed it to the vendor package
  itself being incomplete — corrected here: every one of the 751
  affected filenames' unsuffixed base version was already present, so
  the fix was a safe, additive copy of each base file to its missing
  suffixed sibling path(s) (751 files), followed by `catalog:images:
  resize` (795/795 succeeded, zero "original image not found"
  warnings, confirmed by inspecting the full command output) and a
  direct DB audit confirming all 795 distinct referenced images now
  exist on disk. Live-reverified the specific WSH01 product (previously
  reported as part of the "missing from the package" set) now renders
  its real photo on its own PDP.
- **Resize handle relocation + minimize/maximize:** native CSS `resize`
  only supports a bottom-right drag handle; a custom top-left handle
  was built instead (Pointer Events `pointerdown`/`pointermove`/
  `pointerup` on both themes — Alpine event modifiers `@pointermove.
  window`/`@pointerup.window` on Hyva, since dragging can leave the
  handle element). Because the panel is anchored via `right`/`bottom`
  (not `left`/`top`) on both themes, growing `width`/`height` while
  dragging from the top-left handle naturally expands the panel upward
  and leftward with the bottom-right corner never moving — live-
  confirmed via before/after bounding-box comparison showing `right`/
  `bottom` unchanged while `left`/`top`/`width`/`height` all moved as
  expected. A minimize button next to close collapses the panel to just
  its header bar via a `--minimized` class; **a second real bug was
  found and fixed here too**: the base panel rule's `min-height: 360px`
  isn't overridden by `height: auto` (a `min-height` floor always wins
  over `height: auto`'s computed value), so the first minimized-state
  implementation still rendered a tall, mostly-empty box — caught via a
  live screenshot, not just a DOM-state assertion, fixed by also
  setting `min-height: 0 !important` on the minimized class on both
  themes. Open/minimized state persists across the same session via
  `sessionStorage` (best-effort — wrapped in try/catch, storage
  unavailability degrades to "state doesn't survive a reload," never a
  broken widget).
- **The floating-button bug — root cause and fix:** confirmed the
  toggle's click handler was always correctly flipping the panel's
  `hidden` DOM property (verified via `page.evaluate()` reading the
  live property value across clicks) — the bug was entirely visual.
  `widget.phtml`'s own `.aavirbhava-chat-panel { display: flex; }` rule
  is author-origin CSS, which unconditionally wins the cascade over the
  browser's user-agent-origin `[hidden] { display: none }` default
  regardless of selector specificity — so the panel rendered visually
  open on every single page load no matter what was clicked, confirmed
  by two before/after screenshots that were pixel-identical. Fixed by
  switching the show/hide mechanism from the `hidden` attribute to a
  `.aavirbhava-chat-panel--open` class (`display: none` by default,
  `display: flex` only with the class), toggled via `classList` instead
  of the `.hidden` property. Hyva's existing panel was never affected —
  `x-show` toggles the element's *inline* `style="display:none"`, and
  an inline style always outranks any class-selector rule, so Hyva
  never had this bug; confirmed by inspecting its `x-show="open"` +
  Tailwind `flex` utility class before ruling it out rather than
  assuming.
- **A pre-existing, unrelated 17-test environment failure found and
  fixed during full-suite verification:** the full unit suite initially
  showed 17 errors, all in `AddToCartToolTest.php`
  (`MethodCannotBeConfiguredException` mocking `Magento\Quote\Api\Data\
  ProductOptionExtensionInterface::setConfigurableItemOptions()`) —
  confirmed unrelated to any file this task touched. Root-caused to a
  stale/incomplete DI-compilation state left over from the earlier
  "install sample data" ad hoc task: `generated/code/Magento/Quote/
  Api/Data/ProductOptionExtensionInterface.php` (an extension-attribute
  interface Magento generates on demand) was simply missing, and the
  unit-test bootstrap's plain `app/autoload.php` — unlike a full
  `Bootstrap::create()` app context — doesn't wire up the runtime
  auto-generation autoloader that would otherwise create it lazily.
  Fixed by re-running `bin/magento setup:di:compile`, which regenerated
  the missing interface; full suite confirmed green afterward (0
  failures/errors both before and after this task's own code changes,
  isolating that this was a pure environment-state issue, not a code
  regression from this task).
- **Optional items:** added — auto-scroll for Hyva (Luma already had
  it; Alpine reactivity doesn't auto-scroll on its own, so a
  `$nextTick`-based scroll call was added after every message push);
  visible `:focus-visible` outlines on Luma's interactive elements
  (cheap, added alongside the styling pass). Already present, no work
  needed — typing/loading indicator (both themes; given a light CSS
  animated-dots polish on Luma's), Enter-to-send (native form
  submission), ESC-to-close (both themes already had it, though Luma's
  check needed updating from `!panel.hidden` to the new `isOpen`
  variable as part of the class-based-toggle fix). Skipped, per the
  task's own "skip anything that meaningfully grows scope" instruction
  — an unread-message indicator on the floating button when minimized:
  this architecture has no server-push/unprompted-new-message
  mechanism (every message is a direct request/response the customer
  themselves triggered), so there is never a message the customer
  hasn't already seen arrive while minimized, making the indicator's
  premise not apply here.
- **Verification:** full suite 1271 → 1282 unit tests (+11), 3093 →
  3125 assertions, 0 failures — run before this task's changes (to
  confirm the 17 pre-existing `AddToCartToolTest` errors predated this
  task, isolating them as unrelated) and after (0 failures either way
  once `setup:di:compile` was re-run). `php -l` on every changed PHP
  file and both `.phtml` templates, `setup:upgrade`, `setup:di:compile`,
  `cache:flush` all clean. **Live-verified in real headless-Chrome
  sessions against the real storefront, real local-model responses, no
  scripting at any layer beyond driving the browser**: (1) the floating
  button now genuinely opens and closes the panel (`display: none` →
  `flex` → `none` across clicks, not just DOM-attribute state); (2)
  changing the two new color settings (`#8e44ad` primary, `#fff3cd`/
  `#7a5b00` message bubble) visibly changed the rendered header
  gradient and assistant bubble colors, in both the default and hovered
  states; (3) the resize handle sits at the panel's top-left corner and
  dragging it grows the panel while the bottom-right corner's screen
  position stays exactly fixed; (4) minimize collapses the panel to a
  ~35px header-only bar and maximize restores the full 600px panel,
  confirmed via bounding-box measurements, not just class presence; (5)
  a real "show me yoga pants" query returned 8 product cards, each with
  a real, resized (135×135) product photo — confirmed via `naturalWidth`
  reading actual decoded pixel dimensions, not just a non-empty `src`;
  (6) reloading the page after a live conversation restored the exact
  same panel-open state and the full 8-product-card transcript with
  images intact, via the existing Task 20 history-restore path picking
  up the new `image_url` field with zero additional wiring.
- **Known gaps / TODOs left for later tasks:** none newly introduced.
  The Hyva template still cannot be live-verified in this environment
  (Task 11's original gap, unchanged) — its resize/minimize/color/image
  changes were built to mirror Luma's live-verified behavior and to the
  same Alpine/Tailwind conventions, but are unverified against a real
  Hyva theme. No JS unit-test framework exists for the widget's own JS
  (Task 11's original gap, unchanged) — this task's JS verification
  again relied on real headless-Chrome sessions rather than a
  project-integrated test suite.
- **Skill files updated:** `references/progress-log.md` — status rows
  9 and 12 updated; header summary updated; this Task 21 history entry
  added.
- **Not done / blocked:** nothing blocked.

### Task 22 — Price-filter false positive, product-card color clash, real color pickers + auto-contrast (DONE)
- **Files:** Modified — `Model/Chat/OutputValidator.php` (threshold-phrase
  exemption in the price-fabrication check) + `Test/Unit/Model/Chat/
  OutputValidatorTest.php` (net +4: one renamed/flipped, four new);
  `view/frontend/templates/chat/widget.phtml` +
  `widget-hyva.phtml` (product-card color-clash fix, `--aavirbhava-
  primary-text-color` used throughout instead of hard-coded white);
  `etc/adminhtml/system.xml` (the 3 Appearance fields gained
  `frontend_model`, comments rewritten to describe auto-contrast);
  `Api/Config/AppearanceConfigInterface.php` + `Model/Config/
  AppearanceConfig.php` (every getter now non-nullable, new
  `primaryTextColor()`); `Model/Config/ConfigurationReader.php`
  (`readAppearance()` rewritten around the auto-contrast pairing, new
  `ColorContrast` dependency, 3 new `DEFAULT_*` constants) +
  `Test/Unit/Model/Config/ConfigurationReaderTest.php` (net +3);
  `Block/Frontend/ChatWidget.php` (non-nullable color getters, new
  `getPrimaryTextColor()`, simplified `getColorCustomPropertiesStyle()`,
  fail-safe fallback now uses concrete defaults) + `Test/Unit/Block/
  Frontend/ChatWidgetTest.php` (net -1). New — `Model/Config/
  ColorContrast.php` (the auto-contrast computation) +
  `Test/Unit/Model/Config/ColorContrastTest.php` (6 tests);
  `Block/Adminhtml/System/Config/ColorPickerField.php` (wires Magento's
  own shipped colorpicker widget to each field) + `Test/Unit/Block/
  Adminhtml/System/Config/ColorPickerFieldTest.php` (4 tests). 16 net
  new tests (1282 → 1298 unit, 3125 → 3150 assertions).
- **Part A — price-filtered query diagnosis and fix:** reproduced "show
  me jackets less than $40" through the real endpoint first — it
  returned `reason_code: fabricated_price`, not a tool-schema error, so
  the get_cart-style "same class of bug" hypothesis the task prompt
  raised didn't hold; confirmed by capturing the real tool call
  (`search_products` with `query: "jackets under $40"`, a perfectly
  valid call against the tool's actual schema — Task 3 never built
  structured price filtering, and this task's diagnosis found no
  evidence it needs to) and the real rejected message via temporary,
  immediately-reverted logging (this module's established capture-then-
  revert technique). The model's reply correctly named one real $32
  product but also stated the customer's own "$40" budget back twice
  ("...available under $40" / "...priced under $40") — `extractMentionedPrices()`
  had no way to tell a restated constraint from a specific product-price
  claim, so the unmatched "$40" rejected the whole otherwise-correct
  response. Fixed in `OutputValidator`: a new `isPriceThresholdMention()`
  check exempts any currency-shaped number immediately preceded by a
  recognized threshold word (under/below/less than/cheaper than/up to/no
  more than/maximum of/within/budget of/or less/or under/or below/over/
  above/more than/at least/starting at/between) from the real-price match
  check entirely, since such a mention is a restated constraint, not a
  claim about a specific product. Deliberately widens the exemption to
  ANY threshold-qualified mention, not just search-price-filter replies
  — this also fixes Task 5's own previously-documented, explicitly-
  "accepted, not a bug to fix" false positive ("free shipping on orders
  over $75"), since it's the identical linguistic pattern; that test was
  rewritten to assert the corrected behavior rather than deleted, and a
  new test confirms a genuinely unqualified fabricated price elsewhere
  in the same message is still caught. The accepted trade-off, stated
  in the code: a fabricated price phrased as a threshold ("this one
  runs about $200" for a real $50 item) would now also slip through
  uncaught — judged an acceptable cost against fixing a failure mode
  that blocked an entire, common class of query outright.
- **Part B — the real cause of the product-card color clash:**
  `.aavirbhava-chat-product-card` has always had a hard-coded white
  background, but `.aavirbhava-chat-price-now`, the recommendation
  badge, and an un-linked product title (no URL) never set their own
  text color, so they inherited it from the enclosing assistant
  bubble — whose text color became admin-configurable in Task 21
  (`--aavirbhava-message-text-color`). A merchant configuring a light
  bubble-text color (correct for a dark bubble) silently made those
  card elements unreadable against the card's own always-white
  background — the product card was never meant to share the bubble's
  theme at all, it's its own fixed-white "island" nested inside it.
  Fixed by giving the card container itself an explicit, fixed base
  text color (both themes) so nothing inside it depends on the bubble's
  configurable theme, rather than patching each individual affected
  element one at a time (the same piecemeal approach that let three
  different elements each independently "forget" their own color in
  the first place).
- **Part C — real color pickers, and auto-contrast as the actual
  design change:** the three Appearance fields now use a new
  `ColorPickerField` (`frontend_model`) wiring Magento's own shipped
  `jquery/colorpicker/js/colorpicker` — the same widget
  `Magento_Swatches`' admin "Visual Swatch" attribute editor already
  uses — via a swatch trigger next to the existing text input, plain
  inline `<script>` wiring identical in shape to `OllamaModelField`
  (Task 14), this module's only other admin-JS field; typing/pasting a
  value directly still works unchanged. The bigger change is
  `ConfigurationReader::readAppearance()` no longer returning null for
  anything: a new `ColorContrast` helper (YIQ perceived-brightness, not
  a WCAG-strict contrast checker) computes a readable pairing whenever
  only one half of the message-bubble background/text pair is set —
  the other half is computed against it rather than falling back to a
  fixed default that might clash with what was actually configured; if
  both are set, both are used exactly as configured (manual values
  always win, even a poorly-chosen pair — that's the merchant's
  explicit choice); if neither is set, this module's original defaults
  apply unchanged. Extended the same principle, beyond the task's literal
  ask, to the header/toggle text color: it's always auto-computed
  against whatever the primary color resolves to (default or explicit),
  since there's no separate field for it and the identical clash risk
  applies — a light custom primary color no longer silently pairs with
  hard-coded, unreadable white header text.
- **Verification:** full suite 1282 → 1298 unit tests (+16), 3125 →
  3150 assertions, 0 failures, run both before and after this task's
  changes. `php -l` on every changed PHP file and both `.phtml`
  templates, `setup:upgrade`, `setup:di:compile`, `cache:flush` all
  clean. **Live-verified in real headless-Chrome sessions against the
  real storefront, real local-model responses**: (1) "show me jackets
  less than $40" now returns `reason_code: null` with a real product
  card (Jade Yoga Jacket, $32) instead of the generic fallback; (2)
  with `message_bubble_color` set to a dark navy and
  `message_text_color` left unset, the assistant bubble correctly shows
  auto-computed white text, while the product card inside it (real
  price, real description) stayed dark-text-on-white, fully readable,
  confirming the Part B fix under the exact conditions that would have
  broken it; (3) with `primary_color` set to a light yellow, the header/
  toggle text auto-computed to dark and stayed readable — previously
  hard-coded white, this would have been unreadable; (4) with no
  Appearance fields set at all, the header/toggle rendered the original
  `#1979c3` blue with white text, confirming the defaults are pixel-
  identical to pre-Task-22 behavior. **Admin colorpicker UI**: verified
  correct at the code level via 4 passing unit tests asserting the exact
  real HTML/JS `ColorPickerField::_getElementHtml()` produces (swatch
  bound to the real field id, requiring Magento's actual
  `jquery/colorpicker/js/colorpicker` module, starting color reflecting
  the current value); live-rendering it inside the real admin panel
  itself was attempted but blocked by an environment issue unrelated to
  this task's code — a newly created temporary admin user (created
  solely for this verification, deleted afterward) was redirected away
  from every non-Dashboard admin page it tried, including the standard
  Catalog > Products grid, confirmed via a control check to be a
  pre-existing environment/user-provisioning characteristic of this
  install, not an ACL or code problem (directly confirmed: `Magento\
  Framework\Acl\Builder`'s own `isAllowed()` returned `true` for every
  relevant resource for that user). Reported honestly rather than
  claimed as verified.
- **Known gaps / TODOs left for later tasks:** none newly introduced.
  The admin colorpicker's live rendering inside the real admin panel
  remains unverified in this environment for the reason stated above —
  a live check by the user (who has real admin credentials) would close
  this. A price mentioned with no threshold-qualifying word (a bare
  discount amount, e.g. "$5 off") can still false-positive in the
  Output Validator exactly as before Part A — a documented, narrower
  instance of the same pre-existing regex-based limitation, not
  resolved by this task.
- **Skill files updated:** `references/progress-log.md` — status rows
  3, 8, and 12 updated; header summary updated; this Task 22 history
  entry added.
- **Not done / blocked:** the admin-panel live colorpicker check (see
  Known gaps above) — not blocked by this task's own code, blocked by
  an unrelated environment access restriction on newly created admin
  users.

### Task 23 — Robustness and consistency: real query-variation testing, remaining price/URL false positives, completeness, image fill (DONE)
- **Files:** Modified — `Model/Chat/OutputValidator.php` (broadened/
  fixed the price-threshold detection: new backward + forward phrase
  lists, context-window bleed fix; `containsUrl()` rewritten as
  `containsFabricatedUrl()`) + `Test/Unit/Model/Chat/
  OutputValidatorTest.php` (net +8); `Model/Chat/ChatEntryPipeline.php`
  (retry loop restructured around a `$bestValidValidation` fallback,
  new `ProviderInvalidResponseException` catch + retry, new
  completeness-retry branch, new `logProviderFailure()`/
  `missingProductsCorrectionMessage()` helpers, new
  `ProductMentionCompletenessChecker` dependency) + `Test/Unit/Model/
  Chat/ChatEntryPipelineTest.php` (net +6); `Model/Chat/
  ResponseContractFormatter.php` (two new instructions: don't call a
  "product_skus" tool, write a genuine reason not a bare price
  restatement) + `Test/Unit/Model/Chat/ResponseContractFormatterTest.php`
  (+2); `Model/Config/ConfigurationReader.php` (`DEFAULT_MAX_TOOL_CALLS`
  tried at 6, reverted to 4 — see below); `view/frontend/templates/
  chat/{widget,widget-hyva}.phtml` (product-image `object-fit`/
  `object-*` switched from `contain` to `cover`). New —
  `Model/Chat/ProductMentionCompletenessChecker.php` +
  `Test/Unit/Model/Chat/ProductMentionCompletenessCheckerTest.php`
  (6 tests). 21 net new tests (1298 → 1319 unit, 3150 → 3197
  assertions).
- **Part A — price-filter reliability, the real remaining causes:**
  reproduced the identical "show me jackets less than $40" query
  repeatedly (this task's own instruction: capture what differs
  between failing and succeeding attempts, don't guess) and found
  Task 22's fix, while real, wasn't the whole story. Two more genuine
  bugs, found by reading the actual regex logic against real captured
  text rather than assuming "model inconsistency": (1) `"exceed $40"`
  was rejected outright — `exceed` was simply missing from the
  threshold-word list, despite `under $40` (the exact same sentence's
  own inverse) already being recognized; broadened the list
  substantially (exceed(s)/exceeding, priced at/under/over, costs
  less/more than, greater/higher/lower than, beyond, in excess of,
  starting from/at, ranging/range, as low/high as, and more). (2) the
  backward context window that decides whether a price is threshold-
  qualified could "bleed" across an earlier number's threshold word
  into a later, unrelated one in the same sentence (`"...is under $40,
  with a price of $32"` incorrectly exempted the genuine $32 claim from
  ever being checked) — fixed by clipping both the backward AND a newly
  added forward-looking window to the previous/next mentioned price's
  actual position, and by adding a forward-phrase list so trailing
  qualifiers like `"$40 or less"` (previously dead code — the original
  implementation only ever looked backward, so a trailing phrase could
  never match) are now genuinely checked.
- **Part A — a second false positive found the same way, in the URL
  check:** `containsUrl()` rejected ANY url mention at all, live-caught
  rejecting a genuinely accurate product url the model had retrieved
  via `get_product_details` and repeated back in a real "compare these
  two products" answer. Renamed to `containsFabricatedUrl()`, given the
  exact same "only reject a non-matching mention" shape
  `containsFabricatedPrice()` already used.
- **Part A — the real, previously-silent cause of most `assistant_
  unavailable` occurrences:** the `catch (ProviderException)` block in
  `ChatEntryPipeline` had no variable bound and logged nothing —
  genuinely undiagnosable without adding instrumentation, which this
  task did (temporarily, reverted after use, this module's established
  capture-then-revert technique). Real captured HTTP bodies showed
  every provider call itself succeeded (status 200, well-formed JSON)
  — the *content* was the problem: the model sometimes emits a tool
  call literally named `"product_skus"`, confusing the response
  schema's field name with a real callable tool. That call always
  fails as `unknown_tool`, burning a round of `guardrails.
  max_tool_calls`, and — once the round budget runs out and the model
  is force-answered with no tools offered — it sometimes returns a
  genuinely empty completion, thrown as `ProviderInvalidResponseException`
  well before `OutputValidator` ever sees it. Fixed two ways: (1)
  `ResponseContractFormatter` now explicitly warns against calling a
  tool named `"product_skus"`; (2) `ChatEntryPipeline`'s retry loop
  restructured to also retry once on `ProviderInvalidResponseException`
  specifically (never on a genuine availability exception — those are
  unchanged and still short-circuit immediately, since
  `FallbackChatGenerationService` already owns retrying those). The
  catch block itself now logs the real exception via a new
  `logProviderFailure()`, closing the diagnosability gap for good.
- **Part A — a real trade-off found, tried, and reverted:** reasoned
  that raising `guardrails.max_tool_calls`'s default from 4 to 6 would
  give the model more slack to recover from a wasted round before
  being forced to answer. Implemented, then the broad Part E test (see
  below) showed the opposite of the intended effect for genuinely
  ambiguous queries: more rounds mostly just meant "spend longer
  failing to find anything" rather than converting a failure into a
  success. Worse, since each `converse()` attempt already costs up to
  `maxToolCalls`+1 real provider calls and `ChatEntryPipeline`'s own
  retry budget can invoke `converse()` twice, six rounds pushed the
  theoretical worst case to 14 real calls (~280s at the 20s default LLM
  timeout) — and this environment's nginx has a real, unoverridden
  ~60s default `fastcgi_read_timeout`, confirmed by inspecting the
  actual config, not assumed. Reverted to 4 (the original, already-
  proven value); a targeted re-test of the same previously-timing-out
  queries afterward all completed well under that ceiling. Kept: the
  prompt fix and the invalid-response retry, both of which address the
  same root confusion without touching the round-cap ceiling at all.
- **Part B — "here are 2 jackets" rendering only 1 card:** traced
  precisely per this task's own instructions. `final_products`
  candidate caps weren't the cause (8 candidates were consistently
  retrieved and threaded into context, more than enough for a 2-jacket
  answer). `OutputValidator`'s `fabricated_sku` check couldn't be the
  cause either — it's all-or-nothing per response (any unverified SKU
  rejects the *whole* thing), it has no mechanism to silently drop one
  product while keeping others. The real cause, confirmed by reading
  live-captured model output: the model itself sometimes names a real,
  verified second product in its message text without selecting its
  SKU into `product_skus`, despite Task 18's own instruction to do
  exactly that. Fixed with a new `ProductMentionCompletenessChecker` —
  deliberately mechanical (exact, literal product-name substring match,
  not fuzzy NLP, so it never retries on a false alarm) — wired into
  `ChatEntryPipeline`'s existing retry budget: a valid-but-incomplete
  response gets one retry naming exactly which SKU(s) were missing; if
  still incomplete afterward, the response is used as-is rather than
  discarded, since a response with 1 real card is strictly better than
  the generic fallback with none. A regressed retry (the correction
  attempt coming back malformed/fabricated instead of better) falls
  back to the earlier valid response via a new `$bestValidValidation`
  tracked separately from the loop's per-attempt `$validation` — unit-
  tested directly (`testARegressedSecondAttemptFallsBackToTheFirst
  AttemptsValidResponse`).
- **Part C — the placeholder-description text:** confirmed by direct
  code search that no template anywhere in this module generates
  `"price 32 is below 50"`-style text — it's entirely model-authored
  via the `reason` field `ResponseContractFormatter` already asks for.
  Fixed with a prompting instruction, not a code template change: each
  `reason` must be a genuine, customer-useful description (material,
  use case, why it fits), never a bare number-comparison restatement —
  a price comparison is fine *alongside* a real reason, never as the
  whole of one.
- **Part D — image sizing:** `.aavirbhava-chat-product-image`/Hyva's
  equivalent `<img>` switched `object-fit`/`object-*` from `contain` to
  `cover` on both themes, so every card's image area fills consistently
  regardless of the source photo's own aspect ratio — no letterboxing
  or uneven padding depending on which product's real photo is shown.
- **Part E — broad realistic-query robustness testing:** built a
  19-query set spanning clean/detailed, terse/vague, typo/informal,
  incomplete-grammar, and genuinely ambiguous real customer phrasings
  (e.g. "show me waterproof running shoes under $60 in size 10",
  "jackets?", "wat jakets u got", "men jacket less 40 dollar",
  "something for the gym"), each run 3 times (57 real calls total)
  against the real pipeline — real retrieval, real Ollama chat, real
  Output Validator, no scripting/mocking at any layer — *before* this
  task's Part A/B fixes, to establish real baseline failure modes
  grouped by root cause rather than treating each as one-off. Results
  by category (excluding calls that hit this test harness's own 60s
  cap, addressed separately below): clean 11/13 (84.6%), terse 10/11
  (90.9%), typo 9/12 (75.0%), incomplete-grammar 6/8 (75.0%), ambiguous
  2/2 (100%, but only 2 of 9 calls in this category avoided the harness
  timeout — see below). Overall: 38/46 non-timeout calls (82.6%); 11 of
  the 57 calls (19.3%) hit the test harness's own 60-second cap rather
  than a real pipeline outcome, concentrated heavily in the ambiguous
  category — this is the same latency issue the Part A `max_tool_calls`
  trade-off above investigates and reverts, discovered *because* of
  this broad test, not assumed beforehand. The specific failures this
  data surfaced (fabricated_price on genuine multi-price comparisons,
  fabricated_url, fabricated_sku, assistant_unavailable) are exactly
  what Parts A/B's fixes above address, traced from this data, not
  guessed.
- **Verification:** full suite 1298 → 1319 unit tests (+21), 3150 →
  3197 assertions, 0 failures, run repeatedly through this task's
  several rounds of fix-then-verify.
  `php -l` on every changed PHP file and both `.phtml` templates,
  `setup:di:compile`, `cache:flush` all clean (no schema change this
  task, `setup:upgrade` not needed). **Live re-verification after all
  fixes (including the `max_tool_calls` revert)**: a targeted 12-call
  re-test of the specific queries that had failed or timed out in the
  Part E baseline (the "compare" query, "something for the gym",
  "jackets?", plus "show me jackets less than $40" as a Part A
  regression check) reached **11/12 (91.7%) success** — the "compare"
  query went from mostly failing to 3/3 real comparisons rendered
  correctly with both product cards; "something for the gym" went from
  3/3 harness timeouts to 3/3 real, on-topic responses; the price-
  filter query stayed reliable at 3/3. The single remaining failure was
  a `fabricated_sku` on "jackets?" — a genuine model hallucination the
  safety check correctly caught and rejected, exactly as designed, not
  a bug. Product images confirmed `object-fit: cover` live via computed
  style plus a screenshot showing every card's image filling its frame
  consistently. This task's own scope did not include re-running the
  full 57-query Part E set after every fix (each full pass costs
  ~20-30 minutes of real Ollama time); the targeted re-test above
  specifically re-exercises the queries the broad test found
  problematic, which is the evidence that most directly answers
  whether the fixes worked.
- **Known gaps / TODOs left for later tasks:** honestly, not every
  failure mode is fixable by code changes alone — the `fabricated_sku`
  case in the final confirmation run is a genuine local-model
  hallucination (inventing a SKU that was never in the verified set),
  correctly caught and rejected by design; no prompt or retry change
  removes the underlying inconsistency of a small local model under
  Ollama, only a more capable primary provider plausibly would (not
  executed in this task, per its own instructions). A price mentioned
  with no threshold-qualifying word (a bare discount amount, "$5 off")
  remains an accepted, documented false-positive source (Task 22,
  unchanged). `ProductMentionCompletenessChecker`'s exact-substring
  matching under-reports a paraphrased mention ("the Jade jacket"
  instead of the real "Jade Yoga Jacket") — a deliberate, documented
  trade-off favoring zero false-positive retries over catching every
  possible incompleteness. The full 57-query Part E baseline was not
  re-run in full after the final round of fixes (see Verification) —
  the targeted 12-call re-test is strong, but narrower, evidence.
- **Skill files updated:** `references/progress-log.md` — status rows
  8 and 12 updated; header summary updated; this Task 23 history entry
  added.
- **Not done / blocked:** nothing blocked. The full-scale Part E re-run
  (see Known gaps) is a scope/time trade-off, not a blocker.

### Task 24 — Index-coverage diagnostic CLI and a dedicated chat debug log (DONE)
- **Files:** New — `Model/Diagnostics/CatalogSkuProvider.php`,
  `Model/Diagnostics/IndexedSkuProvider.php`,
  `Model/Diagnostics/IndexCoverageReport.php`,
  `Model/Diagnostics/IndexCoverageChecker.php`,
  `Console/Command/IndexCoverageCommand.php`,
  `Model/Chat/Debug/ChatDebugTrace.php`,
  `Model/Chat/Debug/ChatDebugLogger.php`, plus a test file for each
  (`Test/Unit/Model/Diagnostics/*`, `Test/Unit/Console/Command/
  IndexCoverageCommandTest.php`, `Test/Unit/Model/Chat/Debug/
  ChatDebugLoggerTest.php`). Modified — `Model/Chat/
  ChatEntryPipeline.php` (new `ChatDebugLogger`/`ChatDebugTrace`
  wiring, whole method body now inside a try/finally, two new small
  helper methods) + `Test/Unit/Model/Chat/ChatEntryPipelineTest.php`
  (factory updated for the new constructor arg, no test logic
  changed); `etc/di.xml` (console command registration, the debug-log
  Monolog virtualType chain). 22 net new tests (1319 → 1341 unit, 3197
  → 3245 assertions).
- **Part A — index-coverage command:** `aavirbhava:ai-shopping-
  assistant:index-coverage` (optionally `--store-id=<id>`, defaults to
  every active store) compares two independently-sourced SKU lists per
  store: `CatalogSkuProvider` (real salable/visible/enabled catalog
  SKUs — Magento's own standard `status`/`visibility` attribute
  filters plus `CatalogInventory\Helper\Stock::
  addIsInStockFilterToCollection()`, the same helper category/search
  listings use, which also respects the merchant's own "Display Out of
  Stock Products" setting rather than a stricter stock-table-only
  definition) and `IndexedSkuProvider` (a plain match-all query against
  the store's live read alias, capped at 10000 documents — a fast
  diagnostic, not built to reconcile a store with more SKUs than that;
  not `is_enabled`-filtered, since every document present already
  passed `ProductIndexEligibilityPolicy`'s enabled/visible gate at
  index time, so a plain per-store count is the correct comparison).
  `IndexCoverageChecker` composes both into a two-way diff
  (`missingFromIndex`/`missingFromCatalog`); the command prints a
  summary table plus up to 50 specific SKUs per direction (with a
  remainder count beyond that), and reports "never indexed" rather
  than erroring when no alias exists yet for a store. An unreachable
  backend is reported per-store (`ProductIndexingException` caught,
  logged to the command's own output) rather than aborting a
  multi-store run. `CatalogSkuProvider`/`IndexedSkuProvider`/
  `IndexCoverageChecker` are deliberately not `final` (unlike most of
  this module) purely so they stay mockable in each other's unit
  tests — no Api interface was added for a diagnostic-only feature
  with a single implementation. `IndexCoverageCommand` itself is also
  not final — Magento's DI compiler generates an interceptor for every
  console command (matching every Magento core command, none of which
  are final either) and `setup:di:compile` fails outright otherwise,
  caught via a real `setup:upgrade` run during this task, not assumed.
- **Part A — real finding for this store:** live-run against this
  store's actual catalog and index: **181 real salable/visible/enabled
  products, 181 indexed documents, 0 missing from the index, 0
  orphaned documents in the index — fully covered, no drift found.**
  This is the honest answer to the task's own open question, not just
  confirmation the tool runs.
- **Part B — dedicated chat debug log:** `ChatEntryPipeline::handle()`
  now always logs one compact trace to a new
  `var/log/aavirbhava_ai_shopping_assistant_chat.log` file, regardless
  of outcome — a request-tracing aid, not an error log. The whole
  method body was wrapped in a try/finally around a new mutable
  `ChatDebugTrace` accumulator (constructed once at the very top, from
  the raw incoming message, so it's always available to the finally
  block even on the earliest possible short-circuit) — each field is
  filled in only once the pipeline actually reaches that stage:
  in-scope/reason code from the scope classifier; the retrieval query
  text and every `SearchCandidate`'s real `bm25Score`/`vectorScore`/
  ranked `score`; live revalidation's before/after SKU counts and
  exactly which SKUs were dropped (`recordAvailabilityFilter()` — this
  is the one real "filter" step this pipeline has; re-confirmed by
  code search that no structured price/attribute filter exists
  anywhere in retrieval or ranking, unchanged since Task 22/23); and
  the final response's product SKUs. `ChatDebugLogger::record()`
  assembles one structured `debug()`-level context array from the
  trace. Deliberately scoped to the up-front retrieval/revalidation
  step `ChatEntryPipeline` always runs itself for every turn — a
  mid-conversation `search_products` tool call the model makes on its
  own isn't separately traced in this pass (seeing that would mean
  threading the debug logger into `ToolCallingChatService`/
  `SearchProductsTool` too, a larger change than this task's own
  scope), a disclosed boundary, not a silent gap.
- **Part B — getting real isolation from system.log took two live-
  verified fix rounds, not one:** the first version logged at PSR
  `info()` level via a virtualType overriding only the "debug" item of
  `Magento\Framework\Logger\Monolog`'s default handler array
  (`system`/`debug`/`syslog`, declared in `app/etc/di.xml`) — live-
  sending a real chat message showed the trace correctly in the new
  file, but `grep` also found it in `system.log`. Root cause, found by
  reading `app/etc/di.xml` directly rather than guessing: Magento's DI
  merges a virtualType's array argument with its base type's *by item
  key*, so the inherited `system` handler (`Handler\System`, threshold
  `Logger::INFO`) stayed attached and caught the `info()`-level call
  (`Handler\Debug`'s inherited slot wasn't the culprit — it requires
  `dev/debug/debug_logging`; `Handler\System` has no such gate). Fixed
  by switching the log call to `debug()` (below `Handler\System`'s
  `INFO` floor) — the same "debug" key + `debug()`-level combination
  `Magento_Payment`/`Magento_Shipping`'s own virtual debug loggers
  already rely on for the identical isolation, confirmed by reading
  their real `etc/di.xml`. That fix alone still left the inherited
  `syslog` handler active (`Handler\Syslog`, threshold `Logger::DEBUG`
  — genuinely the OS syslog, confirmed by reading its source), so a
  second override — `Monolog\Handler\NullHandler` on both the `system`
  and `syslog` keys — was added for full isolation regardless of
  future log-level choices on this channel. That alone introduced a
  second, distinct, also live-caught bug: a `NullHandler` built with
  its default threshold (`DEBUG`) returns `true` from `handle()` for a
  `debug()`-level record, and `Monolog\Logger::addRecord()` stops
  passing a record to any further handler the instant one returns
  `true` — since the inherited `system` key still sits *before* the
  real file handler in the merged array, every record was being
  silently swallowed by the `NullHandler` with the real handler never
  even running, confirmed by manually invoking the compiled logger via
  a container PHP script and observing no file appeared at all.
  Genuinely fixed by giving both `NullHandler` instances an explicit
  `level` of 601 (one above `Logger::EMERGENCY`, the highest real
  level) so `isHandling()` is always false for anything this channel
  will ever log — confirmed via the same container script showing the
  real file handler in the stack and, immediately after, a real HTTP
  chat request producing a correct entry in the dedicated file with
  nothing new in `system.log`.
- **Verification:** full suite 1319 → 1341 unit tests (+22), 3197 →
  3245 assertions, 0 failures, run before this task's changes and
  again after every fix round (including both debug-log isolation
  fixes). `php -l` on every changed/new PHP file, `setup:upgrade`,
  `setup:di:compile`, `cache:flush` all clean (the one `setup:upgrade`
  warning seen — `Magento_CatalogSampleData`'s own data patch failing
  with "Rolled back transaction has not been completed correctly" —
  reproduced identically on a second, unmodified re-run, confirming
  it's a pre-existing environment characteristic unrelated to this
  task's changes, not something introduced here). Live verification,
  all against the real running store: the index-coverage command run
  both with no arguments (all active stores) and with `--store-id=1`,
  plus a deliberately invalid `--store-id=999` (correctly rejected,
  non-zero exit, no exception page); a real "show me jackets under
  $40" request and a real out-of-scope "what is the capital of France"
  request, each checked against the actual resulting debug-log file
  content and a `grep` of `system.log` for leakage, both before and
  after each isolation fix round.
- **Known gaps / TODOs left for later tasks:** the debug trace does
  not cover a mid-conversation `search_products` tool call's own
  retrieval (see Part B above) — only the turn's own up-front
  retrieval/revalidation, which `ChatEntryPipeline` always runs
  regardless of what the model does afterward. The index-coverage
  command is a snapshot diagnostic with no repair action of its own
  (by the task's own "keep it simple" instruction) — a merchant would
  still need to run a real reindex separately to close any gap it
  finds. `IndexedSkuProvider`'s 10000-document scan cap is untested
  against a catalogue that large in this environment (181 real
  products here) — a store past that size would need a scroll/
  `search_after`-based rewrite, out of this task's scope. Unrelated,
  live-observed while verifying Part B: a couple of `reason` fields in
  a real response still read as a bare price comparison ("Jacket above
  budget at $72") despite Task 23 Part C's prompt fix — consistent
  with that task's own honestly-reported finding that prompting alone
  doesn't reach 100% compliance from this local model; not touched by
  this task, since it wasn't this task's ask.
- **Skill files updated:** `references/progress-log.md` — status rows
  4 and 6 updated; header summary updated; this Task 24 history entry
  added.
- **Not done / blocked:** nothing blocked.

### Task 25 — Fix silent product loss between retrieval and final response (DONE)
- **Files:** New — `Model/Chat/PriceConstraint.php`,
  `Model/Chat/PriceConstraintDetector.php`,
  `Model/Chat/PriceConstraintReconciler.php`,
  `Model/Chat/PriceConstraintReconciliationResult.php`, plus
  `Test/Unit/Model/Chat/{PriceConstraintTest,
  PriceConstraintDetectorTest,PriceConstraintReconcilerTest}.php` (9 +
  11 + 8 tests). Modified — `Model/Chat/ChatEntryPipeline.php` (two new
  constructor dependencies, constraint detected once right after scope
  classification, reconciliation applied once after the retry loop
  resolves a valid response, before persistence/return) +
  `Test/Unit/Model/Chat/ChatEntryPipelineTest.php` (factory updated,
  net +3 new end-to-end tests); `Model/Chat/Debug/ChatDebugTrace.php`
  (3 new fields: `priceConstraint`, `priceConstraintAddedSkus`,
  `priceConstraintRemovedSkus`) + `Model/Chat/Debug/
  ChatDebugLogger.php` (new `price_constraint` log section) +
  `Test/Unit/Model/Chat/Debug/ChatDebugLoggerTest.php` (updated for
  the new fields, no new test count). 31 net new tests (1341 → 1372
  unit, 3245 → 3318 assertions).
- **The bug, confirmed via the real debug log built in Task 24:** "find
  me jackets below $60" produced `availability_filter: {"before_count":
  8, "after_count": 8, "dropped_skus": []}` — retrieval and live
  revalidation both worked perfectly, all 8 real candidates survived —
  yet `final_product_skus` only had 4 entries. Cross-checked against
  each candidate's real, live price (Jade Yoga Jacket $32, Beaumont
  Summit Kit $42, Proteus Fitness Jackshirt $45, Adrienne Trek Jacket
  $57, Inez Full Zip Jacket $59 all genuinely qualify for "below $60";
  Riona Full Zip Jacket at exactly $60, Taurus Elements Shell $65, and
  Orion Two-Tone Fitted Jacket $72 correctly don't): 5 real products
  should have qualified, and the model's own `product_skus` selection
  silently dropped one of them (Proteus Fitness Jackshirt, $45) with
  nothing anywhere telling the customer a real match was missing. Not a
  retrieval bug, not a revalidation bug, not an `OutputValidator`
  rejection — purely the model's own final selection under-counting
  against a candidate list it had complete, correct access to.
- **Approach chosen — (b), deterministic code-side reconciliation, not
  (a) pre-filtering candidates before the model sees them:** pre-
  filtering the candidate set handed to the model (option a) was
  rejected for two concrete reasons found by tracing how the existing
  pipeline actually uses its candidate/verified-product sets: first, it
  would remove the model's ability to mention real, priced alternatives
  in its own text ("all other jackets are priced above $60: ...", a
  documented, desirable pattern since Task 22/23) without also
  breaking `OutputValidator::containsFabricatedPrice()`'s "exempt a
  real, matching mention" check for exactly those alternatives, since
  that check validates against the same verified-product set retrieval
  would now be silently shrinking. Second, and more fundamentally,
  pre-filtering candidates doesn't actually *guarantee* correctness at
  all — `OutputValidator` validates `product_skus` against the full
  verified set, not against "what the model was shown," so a smaller
  candidate list only reduces the model's noise; it does nothing to
  structurally prevent the exact under-selection bug this task exists
  to fix. Option (b) — reconcile the validated response against a
  code-computed correct set — closes the bug with certainty rather
  than probability, reuses this pipeline's existing "verify strictly,
  never trust the model for a fact code can compute" philosophy
  (`OutputValidator`'s own price/URL/SKU checks, `RevalidatedProduct`
  being the only source of truth for price), and — unlike Task 23's
  `ProductMentionCompletenessChecker` retry, which asks the model to
  self-correct — needs no second model round-trip at all, since the
  correct answer is already fully computable from data already in
  hand. Given Task 23's own documented finding that extra round-trips
  measurably raise real request latency for no guaranteed benefit, a
  same-turn, zero-extra-call fix was the clearly better fit here.
- **Detection:** `PriceConstraintDetector::detect()` — simple,
  regex-based (per this task's own instruction, mirroring
  `OutputValidator::extractMentionedPrices()`'s existing currency-
  number pattern rather than sharing it, since the two solve different
  problems), distinguishing an exclusive bound ("under", "below",
  "less than", "cheaper than", "over", "above", "more than" — strictly
  less/greater) from an inclusive one ("up to", "no more than", "at
  least", "$60 or less" — less/greater-or-equal), plus a direct
  "between $X and $Y" pattern for a two-sided range. Only the query
  text is inspected — never the model's own reply, which is a
  separate, pre-existing concern `OutputValidator` already owns.
- **Reconciliation:** `PriceConstraintReconciler::reconcile()` runs
  once, in `ChatEntryPipeline::handle()`, immediately after the retry
  loop settles on a valid response and before conversation persistence
  — using the identical merged verified-product set (`mergeVerifiedProducts()`)
  `OutputValidator` already validated the response against, so
  reconciliation can never add a SKU the customer's own turn didn't
  actually have live, verified data for. Any qualifying candidate
  missing from `product_skus` is appended with an honest, code-
  generated `reason` ("Priced at $X, matching your requested price
  range.") — never a claim invented on the model's behalf; any
  selected product that fails the constraint is removed, and any
  `AssistantAction` left referencing a removed SKU is pruned (the
  whole action dropped if every one of its SKUs was removed) rather
  than left dangling. A response needing no correction is returned as
  the exact same object instance, not rebuilt.
- **Live verification, three different real thresholds, cross-checked
  against real data, not eyeballed:** "find me jackets below $60" —
  the model again selected only 4 (Jade Yoga Jacket $32, Beaumont
  Summit Kit $42, Adrienne Trek Jacket $57, Inez Full Zip Jacket $59),
  reconciliation added Proteus Fitness Jackshirt ($45) with the
  generated reason "Priced at $45.00, matching your requested price
  range.", landing on the exact correct 5-product set; the debug
  trace's `price_constraint.added_skus` recorded `["MJ12"]`. A repeat
  run where the model separately called `search_products` mid-
  conversation (discovering two more real candidates beyond the
  up-front retrieval set) still reconciled correctly against the full
  merged verified set, both adding a genuine match and removing one
  priced at exactly $60 (correctly excluded from an exclusive "below"
  bound) — confirming reconciliation isn't limited to the up-front
  retrieval path. "find me jackets below $50" — the model got all 3
  real qualifying products right unaided; `added_skus`/`removed_skus`
  both empty, response object unchanged, confirming reconciliation is
  a true no-op when nothing needs correcting. "show me jackets over
  $60" — a min-bound (not max-bound) constraint, correctly detected as
  `min: 60, exclusive`; the model again got it right unaided (Orion
  Two-Tone Fitted Jacket $72, Taurus Elements Shell $65; a candidate
  priced at exactly $60 correctly excluded), reconciliation again a
  no-op. All four runs' `system.log` checked for the same debug-log
  leakage class of bug Task 24 fixed — none found.
- **Verification:** full suite 1341 → 1372 unit tests (+31), 3245 →
  3318 assertions, 0 failures, run before this task's changes and
  again after. `php -l` on every changed/new PHP file,
  `setup:di:compile`, `cache:flush` clean (no schema change, no
  console command added this task, `setup:upgrade` not needed).
- **Known gaps / TODOs left for later tasks:** the constraint detector
  only recognizes price thresholds (max/min/range) stated in the
  customer's own query text — a constraint stated only implicitly, or
  introduced only later in a multi-turn conversation without being
  restated, is not detected or enforced. Reconciliation only corrects
  `products[]`; an added product is not retroactively woven into the
  response's own `message` text, so a customer may see a product card
  with no matching narrative sentence — an accepted, disclosed trade-
  off (a card with no text mention is a smaller problem than a real
  match silently missing). Non-price constraints (color, size, brand,
  category) are entirely out of this task's scope, per its own "keep
  it simple, price is the concrete case" framing.
- **Skill files updated:** `references/progress-log.md` — status row 6
  updated; header summary updated; this Task 25 history entry added.
- **Not done / blocked:** nothing blocked.

### Task 26 — Fix multi-turn follow-up handling (DONE)
- **Files:** New — `Model/Chat/PriorTurnProductCarryOver.php` +
  `Test/Unit/Model/Chat/PriorTurnProductCarryOverTest.php` (5 tests).
  Modified — `Model/Chat/ChatEntryPipeline.php` (new constructor
  dependency; after conversation history is loaded, the prior turn's
  SKUs are fetched, live-revalidated, and merged into this turn's
  verified set) + `Test/Unit/Model/Chat/ChatEntryPipelineTest.php`
  (factory updated, net +3 new end-to-end tests); `Model/Chat/
  ProductContextFormatter.php` (prompt wording relaxed to permit a
  product already named earlier in the conversation) + `Test/Unit/
  Model/Chat/ProductContextFormatterTest.php` (+1 test); `Model/Chat/
  Debug/ChatDebugTrace.php` (new `carriedOverSkus` field) +
  `Model/Chat/Debug/ChatDebugLogger.php` (new `carried_over_skus` log
  field) + `Test/Unit/Model/Chat/Debug/ChatDebugLoggerTest.php`
  (updated for the new field). 9 net new tests (1372 → 1381 unit, 3318
  → 3332 assertions).
- **Step 1, reproduction — confirmed via the real debug log, not
  assumed:** sent a real two-turn conversation (shared session cookie)
  through the actual pipeline. Turn 1, "show me jogging pants",
  succeeded with real products. Turn 2, "the cheaper one", fell all
  the way back to the generic message (`reason_code: fabricated_sku`).
  The trace for turn 2 showed `scope: {in_scope: true}` (the
  classifier never rejected it) and `retrieval.candidates`: eight real
  but completely unrelated SKUs (gift cards, bags) — `availability_
  filter: 8 → 8`, all eight survived revalidation, none of them
  jogging pants. The model then selected something that failed
  `OutputValidator`'s fabricated_sku check, and the whole turn was
  discarded. A repeat of the same scenario with "i need it in medium
  size" instead succeeded on one run — the response included
  `actions: [{"type": "check_inventory", "skus": [...5 prior SKUs...]}]`,
  meaning the model recognized the prior products from conversation-
  history text and independently called a tool to re-verify them,
  recovering on its own. Two live runs of near-identical follow-ups,
  one succeeding only through unreliable model-initiated recovery and
  one failing outright, is exactly the "sometimes works" symptom the
  task described — not a deterministic bug with one single trigger.
- **Step 2, root cause — ruled out (a) and (c), confirmed (b) plus a
  second, prompt-level contributor:** (a) conversation history *was*
  genuinely threaded into the LLM call every turn (Task 8 working
  exactly as designed — confirmed by the model correctly recalling
  prior SKUs by name/price in its own text on every run, and by
  `ChatEntryPipeline` unconditionally loading `recentMessages()`
  whenever a `conversationId` is given, unchanged since Task 8). (c)
  `CommerceScopeClassifier` is default-allow, keyword/pattern-based
  against a fixed off-topic/injection/code-gen/external-url list —
  read directly, confirmed neither "medium size" nor "the cheaper one"
  could ever match any of its patterns, and every real trace showed
  `in_scope: true`. (b) confirmed as the real, structural cause:
  `ProductContextResolver::resolve()`/`HybridRetrievalService::
  retrieve()` are called with the current turn's raw message text
  alone — no conversation history, no product-type carry-over of any
  kind — so a short, context-dependent follow-up with no product-type
  signal reliably retrieves irrelevant candidates. A second,
  compounding contributor found while designing the fix, not
  originally hypothesized in the task's own candidate list:
  `ProductContextFormatter`'s system message told the model this
  turn's candidate list was *"the complete and only set of products
  you may mention"* — actively instructing it not to reference
  anything else, including a real product it had already named with
  its real SKU one turn earlier. This explains why the model's own
  recovery (calling a tool) sometimes worked (a tool result is new,
  legitimate data, not "mentioning" something outside the list) while
  directly selecting a remembered SKU into `product_skus` without a
  tool call was exactly what the prompt discouraged.
- **The fix, addressing both parts of the confirmed cause:**
  `PriorTurnProductCarryOver::skus()` reads
  `recentMessagesWithResponsePayloads()` (Task 20's structured UI-
  restore read path — the only one that carries real SKUs, not just
  message text) and returns the SKUs from the most recent assistant
  message that actually had products, scanning past any more recent
  product-less turn. Only ever reads from an already-*persisted*
  assistant message, which — per `ChatEntryPipeline`'s own existing
  persistence rule — only ever happens for a turn that already passed
  `OutputValidator`, so there is no path for a hallucinated SKU to be
  carried forward. `ChatEntryPipeline` now calls this after loading
  conversation history whenever a `conversationId` is present, live-
  revalidates every returned SKU (`LiveRevalidationServiceInterface::
  revalidate()` — never trusts the stored snapshot; a product shown
  two turns ago may have sold out since), and merges the result into
  this turn's verified set via the same `mergeVerifiedProducts()`
  helper tool-call-verified products already use. Separately,
  `ProductContextFormatter`'s instructions were reworded to explicitly
  permit "a product you already named with its real SKU earlier in
  this same conversation," alongside this turn's own candidate list
  and any tool result — safe to relax precisely because
  `OutputValidator`'s fabricated_sku check, not the prompt wording, is
  the actual security boundary; the wording change can only make the
  model more willing to reference something already legitimately
  available in the verified set, never able to smuggle in something
  that wasn't. `ChatDebugTrace` gained a `carriedOverSkus` field so the
  carry-over itself is directly visible in the same debug log that
  surfaced the bug.
- **Live verification — two different follow-up phrasings, both via
  the real debug log:** "i need it in medium size" after a real
  "show me jogging pants" — `retrieval.candidates` still the same
  class of unrelated SKUs as before the fix (confirming this turn's
  own retrieval quality is genuinely unchanged, as intended — the fix
  doesn't try to improve retrieval itself), `carried_over_skus:
  ["MP03","WP04","MP01"]` (the exact three real products from turn 1),
  `final_product_skus: ["MP03","WP04","MP01","MP12","MP02"]` — a real,
  relevant, correct answer, not the fallback. A second, fresh
  conversation with a differently-worded follow-up, "the cheaper one"
  — `carried_over_skus` again carried all five of turn 1's real
  products forward, and the model correctly picked the genuinely
  cheapest one (Caesar Warm-Up Pant, $28 special) by comparing their
  real, live-revalidated prices — confirming the fix isn't overfit to
  one exact wording. Both runs' `system.log` checked for the debug-log
  leakage class of bug Task 24 fixed — none found.
- **Verification:** full suite 1372 → 1381 unit tests (+9), 3318 →
  3332 assertions, 0 failures, run before this task's changes and
  again after. `php -l` on every changed/new PHP file,
  `setup:di:compile`, `cache:flush` clean (no schema change, no
  console command, `setup:upgrade` not needed).
- **Known gaps / TODOs left for later tasks:** carry-over reaches back
  to the single most recent assistant turn with products, not an
  arbitrary number of turns back — a follow-up referring to a turn two
  or more messages earlier ("actually, what about the first jacket you
  showed me yesterday's conversation") is not covered. This turn's own
  retrieval quality for a weak query is unchanged by this task — the
  fix works around it by making prior context available rather than
  improving retrieval's own handling of short/ambiguous text, which
  remains a real, separate limitation. The relaxed
  `ProductContextFormatter` wording is a prompt-level nudge, not a
  hard guarantee the model will actually reference a carried-over
  product when it should — `OutputValidator`/`PriorTurnProductCarryOver`
  together guarantee *safety* (never a fabricated SKU) but not that the
  model always chooses to use what's now available to it.
- **Skill files updated:** `references/progress-log.md` — status row 6
  updated; header summary updated; this Task 26 history entry added.
- **Not done / blocked:** nothing blocked.

### Task 27 — System prompt refinement and price-detector coverage (DONE)
- **Files:** Modified — `Model/Chat/ResponseContractFormatter.php`
  (new leading persona + strict-grounding paragraph; every existing
  paragraph kept verbatim) + `Test/Unit/Model/Chat/
  ResponseContractFormatterTest.php` (+3 tests); `Model/Chat/
  PriceConstraintDetector.php` ("within" added to
  `INCLUSIVE_MAX_PHRASES`; new "around $X" range handling +
  `AROUND_TOLERANCE` constant) + `Test/Unit/Model/Chat/
  PriceConstraintDetectorTest.php` (+7 tests). No files touched in
  `ProductContextFormatter.php` or `PriceConstraint.php` — the former's
  own grounding sentence (Task 26) was deliberately left as-is (see
  Part A below), the latter's `isSatisfiedBy()` logic already supported
  everything the detector change needed. 10 net new tests (1381 → 1391
  unit, 3332 → 3357 assertions).
- **Part A — auditing the real system-message assembly, not assuming
  it was empty:** read both `ResponseContractFormatter` (always
  included, every turn) and `ProductContextFormatter` (included only
  when this turn has candidates) in full before writing anything.
  Neither carried a persona statement ("you are a shopping assistant")
  anywhere, and neither had a rule against inventing a fact absent
  from this turn's data that was guaranteed to reach the model on
  *every* turn — `ProductContextFormatter`'s own "never invent a SKU/
  price/stock/URL" sentence (from Task 26) only ever sends when
  `$candidates !== []`, so a turn where retrieval genuinely found
  nothing had no such reinforcement at all beyond the response
  contract's narrower "only SKUs you were actually shown" line. Added
  a new leading paragraph to `ResponseContractFormatter::INSTRUCTIONS`:
  "You are a shopping assistant for this store... using only the
  retrieved candidates, live tool results, and any product carried
  over from earlier in this conversation... Never invent a product,
  price, SKU, URL, stock status, or attribute that is not present in
  that data... If nothing provided to you for this turn actually
  matches what the customer is asking for, say so plainly instead of
  describing something that merely sounds right." Placed in
  `ResponseContractFormatter` rather than `ProductContextFormatter`
  specifically because it is the one message present unconditionally.
  Deliberately overlaps with `ProductContextFormatter`'s own sentence
  rather than replacing it — the same redundant-validation philosophy
  this codebase already uses elsewhere (e.g. `AbstractEmbeddingProvider`
  re-checking fields its own DTO already guarantees): two independent
  reinforcements of the same rule, one general and one per-turn-data-
  scoped, are cheaper insurance than one. Every existing paragraph in
  `ResponseContractFormatter` (JSON shape, product_skus completeness,
  the "not a tool" warning, reason authenticity) was kept character-
  for-character unchanged — this was strictly additive.
  `OutputValidator`'s fabricated_sku/fabricated_price/fabricated_url
  checks are entirely unchanged and remain the actual enforcement
  boundary; this prompt change can only reduce how often a response
  needs rejecting or reconciling in the first place, never substitute
  for those checks.
- **Part B — "within $X", a real, confirmed gap:** live-reproduced
  "show me price within $50" detecting no constraint at all before
  this fix. Root cause: `OutputValidator`'s own, separately-maintained
  threshold-phrase list has included `'within'` since Task 22, but
  `PriceConstraintDetector` (a new, independent detector built in Task
  25 specifically because sharing regex machinery between the two
  different concerns — response-text fabrication checking vs. query-
  text constraint detection — wasn't judged worth the coupling) never
  had it added to its own list. Fixed by adding `'within'` to
  `INCLUSIVE_MAX_PHRASES`, matching "up to"/"no more than"'s existing
  inclusive-bound semantics.
- **Part B — "around $X", a genuinely new pattern, deliberately not a
  single max bound:** "around"/"about" mean "somewhere near this
  figure," not "up to this figure" — a customer asking for something
  around $50 would still reasonably expect a genuinely close $55 item
  to surface, which folding "around" into `INCLUSIVE_MAX_PHRASES`
  would have incorrectly excluded. Modeled instead as a symmetric
  ±20% band (`AROUND_TOLERANCE`) producing both a min and a max —
  "around $50" detects as $40-$60, inclusive both ends — a simple,
  easily-explained figure, not one derived from UX testing.
  Deliberately "around" only, not also "about": "about" collides with
  its far more common non-price sense ("tell me about $50 gift
  cards" means the $50 gift card product line specifically, not
  "somewhere near $50"), and treating that as a fuzzy range would be a
  real regression, not a coverage improvement — confirmed with a test
  asserting "tell me about $50 gift cards" detects no constraint.
- **Part B — already covered, confirmed rather than assumed:** "budget
  of $X" (backward phrase), "$X budget" (bare "budget" checked in both
  backward and forward context already), and "$X or under" (already
  in `INCLUSIVE_MAX_PHRASES`) were all checked against the existing
  code before touching anything — all three already worked, so no
  change was needed for them; each got a new test instead, to convert
  "should already work" into "verified to work."
- **Live verification:** "show me price within $50" — a first run hit
  a genuine, unrelated `assistant_unavailable` provider hiccup (an
  already-documented local-model flakiness class, not caused by this
  task), but the debug trace still showed `price_constraint.detected:
  {"max":50,"max_inclusive":true,"min":null,"min_inclusive":true}` —
  detection happens before the provider call and is independent of
  whether that call itself succeeds. A retry produced a full real
  response: every one of the 12 real products returned priced at $50
  or under, several added by the existing `PriceConstraintReconciler`
  (Task 25) with its own "Priced at $X.00, matching your requested
  price range" reason — direct proof the "within" fix and the earlier
  reconciler now chain together correctly. Several other varied real
  queries confirmed the persona/grounding change introduces no
  fabrication: "do you sell snowboards" (a product this store
  genuinely doesn't carry) correctly answered "does not carry
  snowboards... I didn't find any snowboard products" with an empty
  products array, rather than inventing one; "what are your yoga pants
  made of" returned a rich, fully real-attribute-grounded answer (8
  named products, 8 matching product_skus entries, real material
  attributes); a fresh two-turn "show me jogging pants" /
  "the cheaper one" conversation confirmed Task 26's carry-over still
  works correctly under the new prompt wording, with no regression.
  `system.log` checked for the Task 24 leakage class of bug across
  every live query this task ran — none found.
- **Verification:** full suite 1381 → 1391 unit tests (+10), 3332 →
  3357 assertions, 0 failures, run before this task's changes and
  again after. `php -l` on every changed file, `setup:di:compile`,
  `cache:flush` clean (no schema change, no new DI-wired class, no
  console command, `setup:upgrade` not needed).
- **Known gaps / TODOs left for later tasks:** the persona/grounding
  paragraph is a prompt-level nudge, exactly like every other
  instruction in this file — it measurably reduces how often the
  model states something ungrounded, per this task's own live
  testing, but cannot *guarantee* it the way `OutputValidator` does;
  a genuinely convincing hallucination could still slip past a purely
  textual instruction and would still need `OutputValidator` to catch
  it, unchanged. `AROUND_TOLERANCE`'s ±20% figure is a simple,
  reasoned choice, not one validated against real customer query logs
  or A/B data — a later task with real usage data could tune it.
  "about $X" remains deliberately undetected, a disclosed trade-off,
  not an oversight.
- **Skill files updated:** `references/progress-log.md` — status rows
  6 and 8 updated; header summary updated; this Task 27 history entry
  added.
- **Not done / blocked:** nothing blocked.

### Task 28 — Fix follow-up-chip voice (customer, not assistant) (DONE)
- **Files:** Modified — `Model/Chat/ResponseContractFormatter.php`
  (new paragraph instructing customer-voice follow_up_questions;
  every existing paragraph kept verbatim) + `Test/Unit/Model/Chat/
  ResponseContractFormatterTest.php` (+1 test); `Model/Chat/Response/
  LlmResponseSchema.php` (new `description` on the
  `follow_up_questions` property — the first this schema has ever
  had) + `Test/Unit/Model/Chat/Response/LlmResponseSchemaTest.php`
  (+1 test). No frontend files changed — see Part A below. 2 net new
  tests (1391 → 1393 unit, 3357 → 3363 assertions).
- **Part A — where follow_up_questions is generated/instructed, and
  whether the widget does anything voice-specific with it, checked
  before changing anything:** `ResponseContractFormatter` is the only
  place instructing the model on this field's content — before this
  task, exactly `"follow_up_questions" (array of strings)`, no
  guidance on phrasing/voice at all. `LlmResponseSchema` mirrors that
  emptiness at the schema level (`type: array, items: {type: string}`,
  no `description`). On the frontend, both themes' click handlers —
  `chat-widget-luma.js`'s `submitMessage(question)` and
  `chat-widget-hyva.js`'s `askFollowUp(question)` — do nothing
  voice-specific at all: a clicked chip's exact text is sent back as
  the next real customer message through the identical code path a
  typed message uses, with no separate "this came from a suggestion
  chip" signal reaching the backend. Confirmed no frontend change was
  needed, exactly as the task anticipated, rather than assumed.
- **The fix:** a new paragraph in `ResponseContractFormatter::
  INSTRUCTIONS` tells the model to write every follow_up_questions
  entry "in the CUSTOMER's own voice, never the assistant's" — a
  short, natural thing the customer might actually say next ("add the
  Tiberius Gym Tank to my cart", "show me other tank tops under $20",
  "what's it made of"), never a question addressed TO the customer
  ("Would you like to add this to your cart?", "Which of these
  interests you most?") — and explains the mechanical reason why:
  each one is sent back verbatim as though the customer had typed it
  themselves, so an assistant-voice suggestion puts the assistant's
  own words in the customer's mouth and confuses the next turn.
  `LlmResponseSchema`'s `follow_up_questions` property also gained a
  matching `description` — added specifically because a real
  OpenAI-compatible provider's structured-output mode does read and
  follow JSON Schema `description` text, giving this one instruction
  a second, provider-native reinforcement alongside the plain-language
  paragraph, mirroring this module's existing redundant-reinforcement
  style (e.g. Task 27's persona paragraph deliberately overlapping
  `ProductContextFormatter`'s own grounding sentence). Not retrofitted
  onto every other schema property — only this one field had a live-
  reproduced voice bug worth the extra guidance. This is a prompt-only
  fix: the response contract's shape, `LlmResponseParser`, and
  `OutputValidator` are all unchanged.
- **Live verification:** reproduced the reported bug first — a real
  "show me gym tank tops" query, before this task's change would have
  produced chips like "Which of these catches your eye? Want to see
  details, compare options, or add something to your cart?" (the
  assistant's own in-message question is a separate, correctly-
  unchanged concern; the bug was specifically the *chip* text). After
  the fix, the same query's real chips were
  `["add MT10 to my cart", "add MT11 to my cart", "add MT08 to my
  cart", ...]` — customer voice. Clicking through one for real (sending
  `"add MT10 to my cart"` as the actual next message) produced "The
  Tiberius Gym Tank (MT10) was added to your cart." with a real
  `add_to_cart` action and a real product card — not the assistant
  getting confused by its own words, and the *next* turn's chips
  (`"add the Sparta Gym Tank to my cart"`, `"what is this made of"`)
  were customer-voice too. Repeated with "show me running shoes"
  across two separate real runs — chips like `"see the Erika Running
  Short"`, `"compare the Erika and Sybil Running Shorts"`, `"is the
  Apollo Running Short in stock"` — consistently customer-voice.
  `system.log` checked for the Task 24 debug-log-leakage class of bug
  across every live query this task ran — none found.
- **Honestly reported, not concealed: the fix does not reach 100%
  compliance for every query shape.** A purely informational query,
  "what are yoga pants made of", produced assistant-voice chips
  ("Are you looking for breathable material for warm weather...",
  "Would you like to see which of these options is currently in
  stock?") in 3 out of 3 repeated real attempts — the model's own
  reliability gap this module has documented as a genuine, unresolved
  limitation in every prior prompt-only fix (Tasks 18, 23, 25, 27),
  not something this task claims to have solved universally. The fix
  is real and live-confirmed for product-search and cart-action
  queries, the two shapes the reported bug's own example matched, but
  is not claimed as a guarantee across every conversational shape.
- **Verification:** full suite 1391 → 1393 unit tests (+2), 3357 →
  3363 assertions, 0 failures, run before this task's changes and
  again after. `php -l` on every changed file, `setup:di:compile`,
  `cache:flush` clean (no schema/DI-wiring change, no console command,
  `setup:upgrade` not needed).
- **Known gaps / TODOs left for later tasks:** the informational-query
  voice gap above is real and unresolved by this task — a later task
  with more live-testing budget could investigate whether a more
  specific instruction, a few-shot example, or a stronger provider
  (per this module's own standing, not-yet-executed "switch primary
  provider" option) closes it. No structural enforcement exists for
  follow_up_questions voice — unlike `OutputValidator`'s fabricated_sku/
  price/url checks, there is no code-level fallback here; an
  assistant-voice chip, if the model still writes one, still renders
  and still sends verbatim on click, exactly as before this task,
  just less often.
- **Skill files updated:** `references/progress-log.md` — status rows
  8 and 12 updated; header summary updated; this Task 28 history entry
  added.
- **Not done / blocked:** nothing blocked.

### Task 29 — Fix empty-products[] responses (retry-budget starvation) (DONE)
- **Files:** Modified — `Model/Chat/ChatEntryPipeline.php` (new
  `MAX_TOTAL_ATTEMPTS` constant; loop bound raised from
  `MAX_STRUCTURED_OUTPUT_ATTEMPTS` to `MAX_TOTAL_ATTEMPTS`; the
  malformed/invalid-response branches now gate on a renamed
  `$complianceAttemptsRemain`, unchanged in behavior/cap; the
  completeness branch gates on a new `$completenessRetryUsed` flag
  instead of the shared attempt counter) + `Test/Unit/Model/Chat/
  ChatEntryPipelineTest.php` (+3 tests). `Model/Chat/
  OutputValidator.php` briefly carried temporary, fully-reverted
  raw-parse diagnostic logging during Step 1 — confirmed clean via
  `grep -rn "file_put_contents"` returning nothing before this entry
  was written. 3 net new tests (1393 → 1396 unit, 3363 → 3376
  assertions).
- **Step 1 — reproduction, via a direct raw-parse capture, not
  assumption:** live-sent "show me some hoodies for men" repeatedly;
  the model's own text reliably named real, verified hoodies, but a
  purely external check (repeated live curl calls) couldn't reliably
  force the exact zero-products failure on demand — local-model output
  is stochastic, and the specific compound sequence this bug requires
  (a malformed response, *then* a valid-but-incomplete one) is rare
  enough that ~20 live attempts across several query phrasings never
  organically produced a fully-empty `product_skus` on their own.
  Rather than continuing to burn live-call budget hoping for luck,
  added a temporary `file_put_contents()` capture directly in
  `OutputValidator::validate()` (this module's established capture-
  then-revert technique) dumping every raw parsed response before any
  processing, then ran several real hoodie queries. Captured, real,
  raw evidence: one turn's attempt produced `product_skus: []` with
  the message text plainly naming "The Oslo Trek Hoodie (MH08)"; the
  very next captured call, same conversation, showed `product_skus:
  ["MH08"]` — the *same* message text, now corrected. This is direct
  proof `ProductMentionCompletenessChecker`'s own name-matching logic
  is not broken for an empty array — it caught this 0-of-1 miss and
  the existing retry corrected it, exactly like a partial miss, when a
  spare attempt was actually available. This *disproved* the most
  literal reading of the task's own hypothesis (a matching-logic gap
  specific to a totally-empty array) rather than confirming it —
  reported honestly, not glossed over.
- **Step 1 — the real structural cause, found by re-tracing the retry
  loop with that finding in hand:** `ChatEntryPipeline`'s single
  `MAX_STRUCTURED_OUTPUT_ATTEMPTS` (2) budget was shared across three
  distinct retry purposes (malformed JSON since Task 16, an invalid/
  empty `ProviderInvalidResponseException` since Task 23, and
  completeness since Task 23). The completeness branch's own guard —
  `if ($missingProducts === [] || !$attemptsRemain) { break; }` —
  unconditionally gave up once `$attempt` reached the shared cap, with
  no retry sent, *including* on the attempt where a completeness gap
  is first evaluated *because* an earlier attempt was already spent
  correcting an unrelated malformed/invalid response. A completeness
  gap that only surfaces on the final allowed attempt therefore had
  exactly zero chance of ever being corrected and shipped as-is — a
  budget-starvation bug, not a matching-logic bug, and one that
  applies to a partial miss exactly as much as a total one; "total"
  miss was simply the shape that got reported and reproduced first.
  Confirmed via `system.log`'s own real notice-level retry logging
  from earlier live testing this session: every "retrying after a
  malformed structured-output response" and "retrying to include
  products" notice captured so far shows `"attempt":1` — meaning the
  compound (attempt-1-consumed-by-something-else) case is real but
  genuinely rare in this environment, consistent with why live
  reproduction alone (Step 1's first approach) wasn't landing it.
- **Step 2 — the fix:** a new `MAX_TOTAL_ATTEMPTS =
  MAX_STRUCTURED_OUTPUT_ATTEMPTS + 1` (3) constant, and a
  completeness-specific `$completenessRetryUsed` flag replacing the
  shared attempt counter as *its* retry gate. The malformed-response
  and `ProviderInvalidResponseException` branches keep gating on a
  renamed `$complianceAttemptsRemain` (`$attempt <
  MAX_STRUCTURED_OUTPUT_ATTEMPTS`, numerically identical to the old
  `$attemptsRemain`) — their own cap and behavior are completely
  unchanged; they can never consume the bonus 3rd attempt. Completeness
  now retries once whenever `$missingProducts !== []` and it hasn't
  already used its one guaranteed shot, up to the new `MAX_TOTAL_
  ATTEMPTS` ceiling — meaning the ordinary case (a completeness gap on
  attempt 1, no compliance issue) is completely unchanged (still
  exactly 2 calls total, proven by the untouched, still-passing
  `testIncompleteProductsAreRetriedOnceAndTheRetryAddsTheMissingSku`),
  and the extra, 3rd call is paid *only* in the specific compound case
  this bug requires — not blanket-raised for every turn's worst case,
  unlike Task 23's own reverted attempt to fix a related latency
  concern by raising `guardrails.max_tool_calls` across the board.
- **Live verification:** re-ran "show me some hoodies for men" 5 times
  post-fix — every one produced real, non-empty, grounded
  `final_product_skus` (`outcome: "generated"` every time, confirmed
  via the real debug log). Re-ran the exact two-turn "hoodies" →
  "cotton materials" sequence from the task's own report 4 times: one
  run showed the real debug trace with `carried_over_skus:
  ["MH01","MH08","WH04","MH07","MH12","MH06","MH13","WH06"]` (all 8
  real turn-1 SKUs, correctly carried forward) and a clean, fully
  grounded turn-2 answer referencing them directly ("Of the hoodies I
  showed you, two are cotton..."). The exact malformed-then-empty
  compound sequence this task fixes did not recur live within this
  session's remaining test budget (consistent with the `system.log`
  evidence above that it's genuinely rare) — deterministic unit tests
  (see Files) instead directly reproduce and prove the fix for that
  specific sequence, which is the appropriate verification for a
  compound event this rare to force live on demand.
- **Honestly reported, not glossed over: a separate, still-present
  limitation surfaced during the same live testing.** One of the four
  two-turn re-runs showed turn 1 succeeding with 8 real products
  (`carried_over_skus` correctly populated with all 8 real SKUs for
  turn 2) yet turn 2 still failed with `fabricated_sku`. Checked
  `system.log` for this exact turn: no retry notice fired at all —
  the model's *first* attempt already selected a SKU outside the
  verified set (including the 8 real carried-over ones), and
  `fabricated_sku` is deliberately never retried by this pipeline's
  own existing design (Task 16: retrying a hallucination risks
  encouraging another one). This is a genuine, pre-existing local-
  model reliability limitation, entirely separate from the bug this
  task fixed — carry-over correctly made real data available, the
  model simply didn't use it correctly on that attempt. Not claimed as
  fixed by this task.
- **Verification:** full suite 1393 → 1396 unit tests (+3), 3363 →
  3376 assertions, 0 failures, run before this task's changes and
  again after. `php -l` on every changed file, `setup:di:compile`,
  `cache:flush` clean (no schema/DI-wiring change, no console command,
  `setup:upgrade` not needed). Confirmed the temporary raw-parse
  capture in `OutputValidator.php` was fully reverted before any of
  this verification ran.
- **Known gaps / TODOs left for later tasks:** the separate
  fabricated_sku-on-first-attempt limitation above remains open and is
  out of this task's scope — it's the same class of "local model
  invents something despite correct data being available" limitation
  this module has documented repeatedly (Tasks 18, 23, 25, 27, 28), not
  something a retry-budget fix can address (retrying a fabrication is
  deliberately not this pipeline's design). The bonus completeness
  attempt is still bounded at exactly one — a turn needing *two*
  separate compliance corrections (e.g. malformed on attempt 1,
  invalid-response on the completeness-bonus attempt, incomplete on a
  hypothetical 4th) would still exhaust its budget and ship
  best-available; judged an acceptably rare compound-of-compound case
  not worth a further, more open-ended budget increase.
- **Skill files updated:** `references/progress-log.md` — status row 6
  updated; header summary updated; this Task 29 history entry added.
- **Not done / blocked:** nothing blocked.

### Task 30 — Fix inconsistent attribute coverage in the RAG index (DONE)
- **Files:** Modified — `etc/config.xml` (`indexing/
  searchable_attribute_codes` default broadened from
  `manufacturer,color,size,material` to `manufacturer,color,size,
  material,climate,pattern,style_general,style_bottom,activity,
  collar,sleeve`). No PHP files changed this task — the diagnosis
  confirmed both candidate code paths (`ProductAttributePolicy`,
  `ProductDocumentNormalizer`) already correctly handle whatever
  attribute list they're given; the gap was purely in what list they
  were given. This environment's own live, already-stored admin
  config override was also updated via `bin/magento config:set`
  (config data, not a code change) and a real `indexer:reindex
  ai_product_rag` was run. No test count change (1396 unit tests
  before and after) — a config-value fix with no new branchable
  logic to unit-test; verification is the real OpenSearch/live-chat
  evidence below.
- **Step 1 — diagnosis, via direct SQL and a real OpenSearch query,
  not assumption:** read `SearchableAttributeValueResolver` first —
  confirmed it draws its attribute code list *entirely* from
  `IndexingConfigInterface::searchableAttributeCodes()` (admin
  config), not a hardcoded set, and correctly handles both scalar and
  multiselect (option-id) attribute storage. Read
  `ProductAttributePolicy` next — a denylist (secrets/internal
  fields), not an allowlist restricted to a fixed set of codes. Read
  `ProductDocumentNormalizer` last — it normalizes whatever attribute
  list it's handed, with no hardcoded subset of its own; this
  directly and completely ruled out the task's third candidate
  hypothesis ("normalizer/embedding text builder only includes a
  fixed subset"), by inspection, not assumption. Checked the real
  configured value: the shipped `etc/config.xml` default was
  `manufacturer,color,size,material` — only 4 codes. Queried the
  catalog's real EAV structure directly via SQL: the dominant "Top"
  (hoodies, 1462 rows) and "Bottom" (pants, 532 rows) attribute sets
  both define real `climate`/`pattern`/`style_general` or
  `style_bottom`/`activity`/`collar`/`sleeve` fields, and — critically
  — an *initial* check against the wrong EAV value table
  (`catalog_product_entity_int`) wrongly suggested these were all
  empty; re-checking against the *correct* table for `multiselect`/
  `backend_type=text` attributes (`catalog_product_entity_text`)
  found them fully, comprehensively populated: `climate` and
  `pattern` on all 147 configurable products catalog-wide, `material`
  likewise, `style_general`/`style_bottom` together covering
  effectively the whole catalog (split by attribute set). This
  reversal — catching and correcting my own initial wrong-table
  conclusion — is recorded here deliberately, not smoothed over.
  Directly fetched MH08's real, live OpenSearch document before any
  fix: `attributes` held only `[{"code":"material",...}]` —
  `climate`/`pattern` were completely absent from both `attributes`
  and `searchable_text`, despite being just as real and just as
  populated as `material`. This is the direct, confirmed proof: the
  gap is the admin config list, not the normalizer, and not
  inconsistent underlying Magento data (the opposite — comprehensively
  populated, simply never configured to be captured).
  Cross-referencing the earlier "Oslo Trek Hoodie made with organic
  cotton, polyester, and nylon" claim from Task 29's own live testing
  against this real data confirmed it was **not** a hallucination as
  briefly suspected mid-investigation — `material=Organic Cotton,
  Polyester, Nylon` is MH08's genuine, real PDP value, correctly
  resolved through `SearchableAttributeValueResolver`'s existing
  multiselect handling once the SQL table mixup above was corrected.
- **Step 2 — the fix:** broadened `searchable_attribute_codes`'
  default to include `climate`, `pattern`, `style_general`,
  `style_bottom`, `activity`, `collar`, `sleeve` alongside the
  existing four codes — chosen specifically because each is a
  genuinely descriptive PDP attribute (matching the task's own
  "Style/Material/Pattern/Climate" framing) with real, broad
  population, not a marketing/merchandising boolean flag. Deliberately
  did **not** add `new`/`sale`/`eco_collection`/`erin_recommends`/
  `performance_fabric` (real but only ~20-30% populated toggle flags,
  a different *kind* of attribute than a descriptive PDP fact) or
  `category_gear`/`features_bags`/`strap_bags`/`style_bags`/`gender`/
  `format`/`country_of_manufacture` (checked and found genuinely 0%
  populated catalog-wide, or niche accessory-only fields) — a
  deliberate, disclosed scope boundary, not an oversight.
  `max_attribute_values_per_product`'s default (100) has ample
  headroom for the added codes; a typical product now resolves
  roughly 8-10 total attribute values, nowhere near the cap.
- **Step 3 — reindex and verify:** discovered, via direct SQL against
  `core_config_data`, that this environment already had an explicit,
  previously-saved admin override at the *old* 4-code value —
  updating only the module's shipped `etc/config.xml` default had
  zero effect on this live environment's actual behavior until this
  was found and fixed too, via the standard `bin/magento config:set`
  (the proper, sanctioned way to change a live admin config value,
  not a direct SQL edit). Confirmed the change actually took effect
  (`readIndexing(1)->searchableAttributeCodes()` returning all 11
  codes) and that it correctly marked the indexer "Reindex required"
  before running a real `indexer:reindex ai_product_rag` (completed in
  6 seconds). Re-fetched MH08's real OpenSearch document post-reindex:
  now carries `climate` (Windy, Cool), `material` (Organic Cotton,
  Polyester, Nylon), and `pattern` (Solid) — `style_general` correctly
  absent for this specific SKU, matching its own real, individually-
  sparse (85/98) coverage rather than a bug. Re-ran the Task 24
  index-coverage command to confirm the full reindex didn't disturb
  overall catalog/index parity: still 181/181, fully covered.
- **Live verification through the real chat pipeline:** a genuinely
  single-turn "what climate are the mens hoodies suited for" returned
  a rich, fully grounded answer directly using real Climate option
  values (All-Weather, Cool, Spring, Windy, Mild, Indoor, Cold,
  Wintry) — a clean, unambiguous success. A two-turn "hoodies" →
  "which ones are cotton" re-run (after recreating this task's own
  scratchpad directory, found missing mid-task and silently breaking
  an earlier cookie-jar-based two-turn attempt — caught and fixed,
  not glossed over) produced turn 1 text now rich with real material/
  pattern/climate facts ("made of wool, polyester, and nylon with
  solid pattern... all-weather and wind-resistant"), and a turn 2
  that honestly, correctly concluded none of those specific hoodies
  are cotton — grounded and accurate, not a hallucination and not a
  blanket data-unavailability decline. Spot-checked OpenSearch
  documents for a yoga pant SKU (`climate,material,pattern,
  style_bottom` — full coverage) and two Gear/Bag-category SKUs
  (`activity,material` — real data reached even outside the two
  dominant attribute sets), confirming the fix generalizes, not just
  the one reported hoodie case.
- **Verification:** full suite unchanged at 1396 unit tests, 3376
  assertions, 0 failures, run before and after (a config-value fix
  introduces no new branchable PHP logic to test). `php -l` not
  applicable (no PHP changed); `etc/config.xml` validated as
  well-formed XML. `setup:di:compile` not needed (no DI wiring
  changed). A real `cache:flush` and real `indexer:reindex
  ai_product_rag` were run as this task's actual container
  verification.
- **Known gaps / TODOs left for later tasks:** `style_general`'s own
  15/98 real gap on the "Top" set (some hoodies genuinely have no
  Style value set in Magento) is real, ordinary catalog data
  sparsity, not a bug — correctly reflected as absent per-product,
  not indexed as a blank/placeholder. The excluded merchandising
  boolean flags and niche Gear/Bag-only fields remain out of the
  indexed set, a deliberate, disclosed scope boundary rather than an
  oversight; a later task with a clearer signal that shoppers actually
  ask about "is this on sale"/"is this eco-friendly" could reconsider
  that boundary. The model's own choice not to always draw on
  available carry-over/context data (seen once mid-verification, a
  turn calling `search_products` fresh instead of using genuinely
  available prior-turn hoodie context) remains the same, already-
  documented local-model reliability class this module has reported
  repeatedly (Tasks 18, 23, 25, 27, 28, 29) — not something an
  indexing-coverage fix can address.
- **Skill files updated:** `references/progress-log.md` — status row 4
  updated; header summary updated; this Task 30 history entry added.
- **Not done / blocked:** nothing blocked.

## Next up

**Phase 1, per architecture.md's own roadmap table ("Module install,
admin config, provider adapters, indexing, RAG search, comparison,
product cards, runtime safety pipeline"), remains functionally complete
as of Task 11 — this task fixed a rough edge, not a missing feature.**
This environment now has, for the first time, a fully configured and
live-verified embedding provider, a real populated OpenSearch index,
and a real chat provider producing genuine responses (Task 15) — every
prior task's "no OpenSearch index configured in this environment" /
"no live LLM configured" caveat no longer applies going forward, though
future tasks should still re-verify current config state rather than
assume it stays this way indefinitely (config is mutable, and prior
tasks' own test-and-revert cycles have caused stale-state bugs before —
see Task 14's Part B). Explicit accounting:

**Done:** module install/config/provider adapters (Tasks 1-2), custom
OpenSearch index + async indexing (Tasks 3-4), RAG hybrid retrieval +
extensible ranking (Task 3), comparison + all other read-only tools
(Task 6), cart tools with confirmation gating (Task 7, now including
configurable-product support via Task 16), output validator + response
contract + live revalidation (Task 4-5, correct even for configurable-
product pricing via Task 16), fallback execution (Task 5),
session/conversation memory (Task 8), admin diagnostics (Task 9),
search_store_content (Task 10), a real, renderable, resizable
storefront chat widget on both default/Luma and (unverified-live) Hyva
(Task 11, Task 16), graceful retrieval-failure handling (Task 12), a
real local/Ollama chat + embedding provider live-verified end to end
against real catalogue data for the first time (Tasks 13-15), and —
Task 16 — measurably more reliable structured-output compliance from a
local model under real conversational load.

**Residual gaps carried forward, none of which block calling Phase 1
done** — each already flagged in the task that found it, repeated
here so they aren't lost:
- Free-text price-fabrication detection's inherent regex limits (Task 5).
- No periodic cron sweep for abandoned conversation rows (Task 8).
- No `Test/Integration/` DB test for `CmsPageContentSearcher`/
  `ProductContentSearcher` (Task 10).
- The Hyva chat widget template has never been rendered against a real
  Hyva theme (Task 11) — no Hyva theme exists in this dev environment.
- No JS unit-test framework or browser-automation tooling exists in
  this project for the widget's JS (Task 11).
- The admin config-page crash report (misattributed to this module,
  actually a different module's invalid XML — see Task 16) remains
  unconfirmed live in a real browser; the underlying XML defect is
  fixed regardless of that module's current disabled state (Task 16).
- The local model's occasional citation of a real-but-not-actually-
  retrieved SKU is reduced, not eliminated, by prompting alone — a
  residual limitation of this specific local model, not this module's
  code (Task 16).
- `FullProductReindexer`'s successful runs appear to leave prior
  run-indices behind in OpenSearch rather than cleaning them up —
  noticed incidentally, not yet investigated (Task 16).

**Open decision, not a gap:** Phase 2 ("Marketing rules, promoted
products, campaign boosting, recommendations, analytics" per
architecture.md's roadmap table) has no task defined against it yet —
this is the next genuinely open question for the sequence, not
something left unfinished.

**Explicitly out of Phase 1 by architecture.md's own roadmap table:**
order assistance, returns, support escalation, voice/image-based
search (Phase 4); marketing rules, promoted products, recommendations
(Phase 2); personalization, customer segments, multilingual queries
(Phase 3); A/B testing, conversion attribution (Phase 5). None of these
are this sequence's remaining work unless a future task explicitly
opens one.

**Process note (from Task 2 feedback):** every task prompt's Step 4 now
requires Claude Code to also write the STATUS REPORT to a uniquely-named
file under `docs/status-reports/`, not just print it — so the user can
upload the file here instead of copy-pasting. See `prompt-template.md`.
