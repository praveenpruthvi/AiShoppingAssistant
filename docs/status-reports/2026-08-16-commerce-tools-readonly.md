# STATUS REPORT — Read-only commerce tools + tool-calling round-trip

Task 6 of the Aavirbhava_AiShoppingAssistant build sequence: implement
`CommerceToolInterface` and the five read-only commerce tools
(search_products, get_product_details, compare_products, check_price,
check_inventory), and wire them into the chat pipeline as allowlisted,
capability-gated tools. Cart mutations were explicitly out of scope —
deferred to a separate, more carefully reviewed task.

## Files created/changed

**Tool contract + registry:**
- `Api/Tool/CommerceToolInterface.php` (new) — `name()`, `description()`, `inputSchema()`, `authorize()`, `execute()`.
- `Api/Tool/CommerceToolRegistryInterface.php` / `Model/Tool/CommerceToolRegistry.php` (new) — allowlist-of-registered-tools, mirrors `LlmProviderRegistry`'s validation shape exactly.
- `Model/Tool/{ToolContext,ToolResult}.php` (new) — per-call scope and per-call outcome DTOs.
- `Model/Tool/Exception/{ToolAuthorizationException,ToolNotFoundException}.php` (new) — outside the `ProviderException` hierarchy (policy/allowlist rejections, not provider I/O failures).
- `Model/Tool/{ProductFormatter,SkuListParser}.php` (new) — shared formatting/argument-parsing helpers reused by every tool.

**The five tools (all new):**
- `Model/Tool/SearchProductsTool.php` — reuses `ProductContextResolver` (retrieval + ranking, Task 3), then live-revalidates the ranked SKUs.
- `Model/Tool/GetProductDetailsTool.php` — one SKU → `LiveRevalidationService::revalidate()`.
- `Model/Tool/CompareProductsTool.php` — up to 5 SKUs, reports `products`/`not_found` separately.
- `Model/Tool/CheckPriceTool.php` — up to 10 SKUs, reports `prices`/`not_found`.
- `Model/Tool/CheckInventoryTool.php` — up to 10 SKUs, uses the new `checkAvailability()` for a positive "out of stock" answer.

**Tool-calling round-trip:**
- `Api/Chat/ToolCallingChatServiceInterface.php` / `Model/Chat/ToolCallingChatService.php` (new) — sits above `ChatGenerationServiceInterface`; offers authorized tools, executes what the model requests, feeds results back, repeats up to `guardrails.max_tool_calls` rounds, then forces a final tools-less call if still not done.
- `Model/Chat/ToolCallingResult.php` (new) — carries the final `ChatResponse` plus every `RevalidatedProduct` any tool call touched.
- `Model/Dto/ChatMessage.php` (modified) — added `toolCallId`/`toolCalls` so an assistant tool-call request and its tool-result messages can be represented in conversation history; relaxed "content must not be empty" to "unless tool calls are present," mirroring `ChatResponse`'s existing rule.
- `Model/Provider/Llm/OpenAiProvider.php` (modified) — `buildMessage()` now serializes `tool_calls`/`tool_call_id` onto assistant/tool messages. `buildTool()` and response `tool_calls` parsing needed no changes — already correct from Task 1.

**Live revalidation extension:**
- `Api/Revalidation/LiveRevalidationServiceInterface.php` / `Model/Revalidation/LiveRevalidationService.php` (modified) — added `checkAvailability()`, reusing `revalidate()` internally for the available subset and a lighter existence-only check for the rest.
- `Model/Revalidation/AvailabilityStatus.php` (new) — one entry per *requested* SKU (unlike `revalidate()`'s drop-on-failure), so "out of stock" and "not found" are distinguishable.

**Assistant Capabilities config (new — did not exist before this task):**
- `Api/Config/CapabilitiesConfigInterface.php` / `Model/Config/CapabilitiesConfig.php` (new).
- `Api/Config/ConfigurationReaderInterface.php` / `Model/Config/ConfigurationReader.php` (modified) — added `readCapabilities()`.
- `Model/Config/Path.php` (modified) — 5 new path constants.
- `etc/system.xml` (modified) — new "Assistant Capabilities" group (5 Yesno fields, each naming its tool).
- `etc/config.xml` (modified) — all 5 default to `1` (enabled).

**Pipeline wiring:**
- `Model/Chat/ChatEntryPipeline.php` (modified) — depends on `ToolCallingChatServiceInterface` instead of `ChatGenerationServiceInterface`; merges retrieval-verified and tool-verified products (dedup by SKU, tool-verified wins) before calling the Output Validator.
- `etc/di.xml` (modified) — new preferences (`CommerceToolRegistryInterface`, `ToolCallingChatServiceInterface`) and `CommerceToolRegistry`'s 5-tool DI array.

**Tests:** 74 net new tests (full suite 1001 → 1075) across 20 new/modified test files: `CommerceToolRegistryTest`, `ToolContextTest`, `ToolResultTest`, `ProductFormatterTest`, `SkuListParserTest`, `SearchProductsToolTest`, `GetProductDetailsToolTest`, `CompareProductsToolTest`, `CheckPriceToolTest`, `CheckInventoryToolTest`, `ToolCallingChatServiceTest` (all new); `ChatMessageTest` (+4), `OpenAiProviderTest` (+1), `LiveRevalidationServiceTest` (+6), `AvailabilityStatusTest` (new), `CapabilitiesConfigTest` (new), `ConfigurationReaderTest` (+2), `ChatEntryPipelineTest` (rewritten for the constructor change, +1 net test for tool-verified-product merging).

## Conventions followed

- `CommerceToolRegistry` mirrors `LlmProviderRegistry`'s exact validation shape: DI-array key must be valid snake_case, the tool's own `name()` must match its key, every entry must implement the interface — "the registry IS the allowlist."
- Every tool's fact-bearing output comes from `LiveRevalidationServiceInterface`, never the assistant index directly — the same discipline established for the response contract in Task 4.
- `ToolAuthorizationException`/`ToolNotFoundException` sit outside the `ProviderException` hierarchy, matching the precedent (`ChatInputException`, `StoreScopeException`) that only genuine provider I/O failures join that hierarchy.
- `ToolCallingChatService` is a new orchestration layer above `ChatGenerationServiceInterface`, not a change to that interface's contract — mirrors how `FallbackChatGenerationService` (Task 5) wrapped it via a decorator instead of changing its shape.
- Fail-closed throughout: an unrecognized tool name, an unauthorized tool, and a tool that throws mid-execute all produce a sanitized error message fed back to the model rather than crashing the turn or silently succeeding.
- `AvailabilityStatus` follows the same "immutable readonly DTO with constructor validation" pattern as every other DTO in this module (`RevalidatedProduct`, `SearchCandidate`, etc.).

## Deviations from existing conventions

1. **Added `description()` to `CommerceToolInterface` beyond architecture.md's sketch.** OpenAI's function-calling wire format requires a `description` sibling to `name`/`parameters` (confirmed against `OpenAiProvider::buildTool()` from Task 1); the architecture doc's sketch didn't show it, but no real implementation could work without it.
2. **Added a new "Assistant Capabilities" config group that did not exist before this task**, unlike Task 3's decision not to invent config for a nice-to-have. Verified (Step 1's "confirm exact field names" instruction) that no such toggle existed anywhere — only `guardrails.cart_mutations_enabled`/`guardrails.max_tool_calls` were capability-adjacent. Capability-gated tool offering is this task's core functional requirement, not optional, so this was judged necessary rather than scope creep.
3. **`ChatMessage`'s validation was relaxed, not tightened**: content may now be empty when `toolCalls` is non-empty (previously content could never be empty). This is required to represent an assistant's tool-call request in conversation history (OpenAI's API expects this shape) and mirrors `ChatResponse`'s own pre-existing text-or-toolCalls rule, so it's a consistency fix, not a new pattern.
4. **`check_inventory` performs two revalidation-service lookups per call** (`checkAvailability()` then `revalidate()` for the same SKU set) — a small, deliberate, documented inefficiency. `AvailabilityStatus` intentionally carries no price/url (that information isn't needed for a stock question, and exposing it would blur the tool's purpose), but `ToolResult::$verifiedProducts` needs full `RevalidatedProduct`s for the Output Validator's merged set — there was no way to get both without either two calls or a wider DTO, and the wider DTO was judged the worse trade.

## Tool interface + tool-call loop design

**`CommerceToolInterface`:** `name()` (stable, must match its DI-array key), `description()` (model-facing, OpenAI wire format), `inputSchema()` (JSON-schema `parameters` object), `authorize(ToolContext): void` (throws `ToolAuthorizationException` when the store's capability toggle is off), `execute(ToolContext, array $arguments): ToolResult`.

**The round-trip (`ToolCallingChatService::converse()`):**
1. Compute the offered tool list once per turn by calling every registered tool's `authorize()` and keeping only the ones that don't throw.
2. Call `ChatGenerationServiceInterface::chat()` with the offered tools. If the response has no tool calls, return immediately.
3. Otherwise append an `assistant` message carrying the requested `toolCalls` to the conversation, then for each requested call: reject closed if the name isn't registered (`unknown_tool`), reject closed if `authorize()` now throws (`tool_not_authorized`, defense in depth), catch any exception from `execute()` (`tool_execution_failed`) — in every case append a `tool` role message with a small JSON error object rather than crashing the turn. On success, accumulate the tool's `RevalidatedProduct`s and append its JSON result as the tool message.
4. Loop back to step 2, up to `guardrails.max_tool_calls` rounds (**configurable, reusing the existing field** — bounds 1-10, default 4, reserved since Milestone 1B and unconsumed until this task). If the round cap is reached and the model is still requesting tools, force one final call with an empty `tools` array so the model must answer in text.
5. Return `ToolCallingResult{response, verifiedProducts}` — the caller (`ChatEntryPipeline`) merges `verifiedProducts` with its own retrieval-derived set before calling the Output Validator, so a SKU only a tool looked up is just as eligible to appear in the final answer as one retrieval originally surfaced.

**Why the Output Validator itself needed no logic changes:** the task asked me to justify this explicitly. The validator's fabrication checks are unchanged; only what gets fed into it changed — `ChatEntryPipeline` now validates against `retrievalVerified ∪ toolVerified` (deduplicated by SKU) instead of `retrievalVerified` alone. Without this, a legitimate tool-sourced answer (e.g. the model calling `get_product_details` for a SKU the customer named directly, never part of retrieval) would be wrongly rejected as `fabricated_sku`.

## Per-tool design

| Tool | Capability toggle | Input | Reuses | Notes |
|---|---|---|---|---|
| `search_products` | `product_discovery_enabled` | `{query}` | `ProductContextResolver` (Task 3) + `revalidate()` | Same retrieval+ranking path as up-front product context, not a second search implementation |
| `get_product_details` | `product_details_enabled` | `{sku}` | `revalidate([sku])` | Returns `{found:false}` for anything not currently available |
| `compare_products` | `comparison_enabled` | `{skus}`, max 5 | `revalidate(skus)` | `{products, not_found}` — a missing SKU is reported, not silently dropped |
| `check_price` | `price_checking_enabled` | `{skus}`, max 10 | `revalidate(skus)` | `{prices, not_found}` — reuses drop-on-failure directly; "no price" is an honest answer for something not purchasable |
| `check_inventory` | `stock_checking_enabled` | `{skus}`, max 10 | `checkAvailability(skus)` + `revalidate(skus)` | Only tool where "unavailable" is itself the answer, not an omission — needed the new `checkAvailability()` |

## Capability-toggle enforcement

Each tool's `authorize()` reads exactly its own `CapabilitiesConfigInterface` flag for the request's store and throws `ToolAuthorizationException` when disabled. This is checked in two places, by design: once by `ToolCallingChatService` when deciding what to offer the model (a disabled tool is never in the request's `tools` array — the primary, task-mandated behavior), and again immediately before `execute()` as defense in depth against a model somehow requesting a tool it wasn't offered. Both checks share the exact same `authorize()` method — there is no separate "offer" vs. "execute" permission logic to keep in sync.

## Container verification

- `php -l` on every new/changed file (host, interim) and again via `bin/cli` — clean.
- `bin/magento setup:upgrade` — clean (after resolving an unrelated infrastructure issue, see below).
- `bin/magento setup:di:compile` — clean; this specifically validates the new DI wiring (`CommerceToolRegistry`'s 5-tool array all resolve to real classes implementing `CommerceToolInterface`; `ToolCallingChatServiceInterface → ToolCallingChatService` resolves without a cycle).
- `bin/magento cache:flush` — clean.
- Full test suite: before this task, 1001 tests / 2459 assertions / 0 failures. After: **1075 tests / 2636 assertions / 0 failures.**
- **Live check 1 (disabled capability excludes its tool from what's offered):** disabled `capabilities/price_checking_enabled` for the default store via the real `bin/magento config:set` CLI (a separate process — writing and reading Magento config within one PHP script hits an in-process cache, a gotcha already known from Task 5). A real DI-resolved `CommerceToolRegistry` + `ConfigurationReader` confirmed `check_price::authorize()` now throws, and — the behavior that actually matters — a real `ToolCallingChatService` (constructed with a stub `ChatGenerationServiceInterface` capturing its `tools` argument) offered `search_products`/`get_product_details`/`compare_products`/`check_inventory` but **not** `check_price` — **PASS**.
- **Live check 2 (a real tool's `execute()` against real DI-resolved data):** re-enabled the capability, then called `check_price::execute()` (real DI-resolved `LiveRevalidationService`) against real sample-data SKU `24-MB01` and a nonexistent SKU. Returned the live price (`$34.00`) sourced from a real `RevalidatedProduct`, and correctly reported the nonexistent SKU in `not_found` — **PASS**.
- **Live check 3 (unrecognized tool-call name rejected without executing anything):** a stubbed LLM response requested `delete_all_products` (never registered). The real `ToolCallingChatService` returned `{"error":"unknown_tool"}` as the tool-result message, produced no verified products, and the round-trip still completed normally to a final text response — **PASS**.
- Config was restored to its documented default (`1`/enabled) via CLI afterward and independently re-verified.

## Test results

- New tests: 74 net new (1001 → 1075), across 20 new/modified test files (see Files section for the full list).
- Full module suite: **1075 tests, 2636 assertions, 0 failures** — zero regressions.
- One environment issue hit and resolved during live verification (see below) — not a code bug.

## Known gaps / TODOs

Explicitly confirmed **not** built, per this task's own scope boundary:
- **Cart mutation tools** (add_to_cart, remove_from_cart, get_cart) — deliberately deferred to a separate, more carefully reviewed task, since these write rather than read. `guardrails.cart_mutations_enabled` already exists (defaults off) and is reserved for exactly this.
- **`search_store_content`** (named in architecture.md's tool list) — not built; architecture.md doesn't specify its data source yet and this task's own instructions named only the five read-only product tools.
- **Admin UI / Playground** — unchanged, still not built.
- **Residual limits carried over, unowned:** real customer-group threading (still always defaults to `NOT_LOGGED_IN`, flagged since Task 3) and the free-text price-fabrication regex's known limits (flagged in Task 5).

## Skill files updated

- `references/progress-log.md` — updated in place: status table row 3 (Assistant Capabilities now gates tool offering) and row 6 (tool-calling done, cart tools deferred); added the Task 6 history entry; "Next up" now points at cart mutation tools, then the admin Playground.
- This file: `docs/status-reports/2026-08-16-commerce-tools-readonly.md`.

## Not done / blocked

Nothing was left incomplete relative to this task's scope. Every Step 1–5 instruction was completed and verified live.

**Incidental infrastructure issue (not code, disclosed for the record):** `bin/magento setup:upgrade` initially failed with a DNS resolution error (`getaddrinfo for db failed`) even though `bin/status` showed every container healthy. Investigation found `magento-db-1` had silently dropped off the `magento_default` Docker network (likely fallout from an earlier host-side port-3306 conflict with an unrelated project's container, resolved by the user in an earlier turn of this session) — `docker inspect` showed an empty network map for a container that was otherwise running and healthy. Fixed non-destructively by reconnecting the already-running container to its network with the correct compose-style aliases (`docker network connect --alias db --alias magento-db-1 magento_default magento-db-1`); no container was restarted, recreated, or had its data touched. `setup:upgrade` succeeded immediately afterward.
