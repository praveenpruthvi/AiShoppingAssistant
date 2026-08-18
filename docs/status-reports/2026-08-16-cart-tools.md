# STATUS REPORT — Cart tools: get_cart, add_to_cart, remove_from_cart

Task 7 of the Aavirbhava_AiShoppingAssistant build sequence: implement the
first WRITE-capable tools in the system — get_cart (read), add_to_cart, and
remove_from_cart — gated by `guardrails.cart_mutations_enabled` and, for the
two mutating tools, a server-verified confirmation gate.

## Files created/changed

**New Cart domain (mirrors the existing `Api/Revalidation` + `Model/Revalidation` shape):**
- `Api/Cart/CartResolverInterface.php` / `Model/Cart/CartResolver.php` — resolves a masked quote id to a real, store-scope-checked `Magento\Quote\Api\Data\CartInterface` via Magento's own public APIs (`MaskedQuoteIdToQuoteIdInterface` + `CartRepositoryInterface`).
- `Model/Cart/Exception/CartNotAvailableException.php` — one exception for every "no cart" failure mode (no id, malformed id, wrong store).

**New tools + shared confirmation infrastructure:**
- `Model/Tool/GetCartTool.php` — read-only, gated only by `cart_mutations_enabled`.
- `Model/Tool/AddToCartTool.php` — gated by `cart_mutations_enabled`; revalidates the SKU live before adding; confirmation-gated when `require_cart_confirmation` is on.
- `Model/Tool/RemoveFromCartTool.php` — gated by `cart_mutations_enabled`; checks presence before proposing/executing; confirmation-gated when present.
- `Model/Tool/CartMutationConfirmationService.php` — the server-side confirmation-token store (see Confirmation gate mechanism below).

**Modified:**
- `Model/Tool/ToolContext.php` — added `?string $cartId = null` and an auto-generated `string $turnId` (both default, so every existing call site kept compiling).
- `Api/Config/GuardrailConfigInterface.php` / `Model/Config/GuardrailConfig.php` / `Model/Config/ConfigurationReader.php` — new `requiresCartConfirmation(): bool`, default `true`.
- `Model/Config/Path.php` — 1 new constant (`GUARDRAILS_REQUIRE_CART_CONFIRMATION`).
- `etc/adminhtml/system.xml` — 1 new field in the existing `guardrails` group (with a comment naming its effect); `cart_mutations_enabled`'s existing field also got a clarifying comment.
- `etc/config.xml` — new field defaults to `1`.
- `etc/module.xml` + `composer.json` — added `Magento_Quote` module dependency (`>=101.0 <102.0`, matching the installed `101.2.6-p12`).
- `etc/di.xml` — new `CartResolverInterface` preference; 3 new tools added to `CommerceToolRegistry`'s DI array.

**Tests:** 43 net new tests (full suite 1075 → 1118) across: `CartMutationConfirmationServiceTest`, `CartResolverTest`, `GetCartToolTest`, `AddToCartToolTest`, `RemoveFromCartToolTest` (all new); `ToolContextTest` (+4), `ConfigurationReaderTest` (+3 assertions across existing tests, no new test methods needed since coverage already iterates every guardrail field).

## Conventions followed

- `CartResolver`'s exception-normalization pattern (every failure mode → one sanitized exception) matches `LiveRevalidationService`'s "not found is an ordinary outcome, not an exception to leak detail from" philosophy.
- `CartMutationConfirmationService` reuses `Model\Chat\Fallback\CacheCircuitBreaker`'s exact shape: `Magento\Framework\App\CacheInterface`, JSON blob, prefixed cache id, TTL — the same "simple state-with-TTL doesn't need a new database table" reasoning from Task 5.
- Every tool still authorizes via a single `authorize(ToolContext): void` check reused for both "offer to the model" and "defense-in-depth before execute" (Task 6's pattern), and every failure mode returns a clean `ToolResult` rather than throwing, exactly like the 5 read-only tools.
- `AddToCartTool`/`RemoveFromCartTool` reuse `LiveRevalidationServiceInterface`/`CommerceToolRegistry`/`ProductFormatter` exactly as Task 6's tools do — no second stock-check path, no second product-formatting shape.
- Logging (new to this module — see Deviations) uses plain `Psr\Log\LoggerInterface` injection, Magento's own zero-config default (writes to `var/log/system.log`/`var/log/debug.log`), not a bespoke logger.

## Deviations from existing conventions

1. **`requiresCartConfirmation()` was added to `GuardrailConfigInterface`, not `CapabilitiesConfigInterface`.** architecture.md's own sketch groups "require-confirmation-before-cart-changes" under "Assistant Capabilities" alongside the 5 Task 6 toggles. I placed it next to its sibling `areCartMutationsEnabled()` in `guardrails` instead, because it is fundamentally a safety/guardrail behavior (how a mutation is allowed to happen), not a feature-availability toggle (whether a tool exists at all) — the same distinction that already separates `guardrails.cart_mutations_enabled` from the `capabilities.*` group. Verified via Step 1's "confirm the exact field name, or say so plainly" instruction that no such field existed anywhere in code before this task (`grep` across `Path.php`/`system.xml`/`config.xml` found nothing) — it had to be added, not merely wired up.
2. **This module had no logging convention anywhere before this task.** `grep -rn "LoggerInterface" --include="*.php"` across the entire module's production code returned zero hits (one test file's mock setup for an unrelated `Magento\Framework\Model\Context` constructor argument was the only match, not a real logging call site). Cart mutations are the first place in this module where "traceable enough to debug after the fact" genuinely matters (Step 3's explicit instruction), so I introduced plain `Psr\Log\LoggerInterface` injection into `AddToCartTool`/`RemoveFromCartTool` — Magento's standard, zero-configuration default — rather than inventing a bespoke logging abstraction. `info` on every successful mutation, `error` (with the sanitized exception message, store id, cart id, sku, qty) on a failed one.
3. **`ToolContext` gained a `turnId` the caller never supplies in practice.** Every other field on `ToolContext` (`storeId`, `customerGroupId`, and now `cartId`) is supplied by the caller. `turnId` instead defaults to a fresh `bin2hex(random_bytes(8))` generated once per `ToolContext` instantiation. This is deliberate: `ToolCallingChatService::converse()` already constructs exactly one `ToolContext` per customer turn and reuses it across every round and tool call within that turn — so an auto-generated default gives every tool a reliable same-turn identifier with **zero changes to `ToolCallingChatService`'s code**, which is what let the confirmation gate be built without touching the round-trip loop (see Confirmation gate mechanism).

## Cart/session context design

**Confirmed, plainly: this module has no real cart-id or customer-session concept anywhere.** Step 1 asked me to inspect for one rather than invent something under time pressure — I found none. `ChatEntryPipeline::handle()` and `ToolCallingChatService::converse()` only ever carry `?int $customerGroupId` (already flagged as unpopulated since Task 3); there is no `Controller/`, `Session`, or cart-id parameter anywhere in the module, and no dependency on `Magento_Quote` existed before this task.

Given that, I threaded `?string $cartId = null` through `ToolContext` exactly the way `customerGroupId` was threaded in Task 4: nullable, with no caller populating it yet, and every cart tool treats a null (or non-resolving) `cartId` as an honest `{"status":"cart_not_available"}` result — never an invented fallback cart, never a crash. `cartId` is documented as a **masked quote id** (Magento's own standard opaque cart identifier, the same shape used by the guest-cart REST API), resolved via `CartResolverInterface` → `MaskedQuoteIdToQuoteIdInterface` → `CartRepositoryInterface`, with a same-store check as extra defense (a masked id resolving to a different store's cart is treated as unavailable, not silently used).

**Practical consequence, stated directly:** none of the 3 cart tools can be exercised end-to-end by a real customer conversation today, because nothing supplies a real `cartId`. All six live checks below construct `ToolContext` directly with a real masked quote id obtained from a real throwaway guest cart (created via `GuestCartManagementInterface::createEmptyCart()`), bypassing the (nonexistent) session layer — the same "verify the real DI-resolved pieces directly, since no live LLM/session layer exists in this environment" methodology every prior task's live checks have used for whatever piece of infrastructure doesn't exist yet. This is a real, load-bearing gap for whichever task eventually builds the storefront Controller/session layer — flagged clearly in `progress-log.md`'s "Next up," not silently worked around.

## Confirmation gate mechanism

This is the load-bearing design decision, so the exact mechanism, spelled out:

**What the model can and cannot control.** The model can only ever cause a mutation by supplying a `confirmation_token` string argument. It never supplies a boolean "confirmed" flag, and free text is never inspected for confirmation language anywhere in this code path — the *only* thing that matters is whether a token redeems successfully.

**Token lifecycle (`CartMutationConfirmationService`, cache-backed like `CacheCircuitBreaker`):**
1. On a mutating tool's first call for a given proposed change (or any call whose token fails to redeem), the tool computes a **proposal** — the exact identifying fields of the change (`action`, `cart_id`, `sku`, and `qty` for add; `action`, `cart_id`, `sku` for remove) — and calls `createToken(turnId, proposal)`. This generates `bin2hex(random_bytes(16))` (128 bits, unguessable), stores `{turnId, proposal}` as JSON in Magento's cache under a hashed cache id, with a 300-second TTL, and returns the raw token. The tool returns it to the model inside a `confirmation_required` result — **the mutation itself never runs on this call.**
2. To execute, a later call must supply that exact token as `confirmation_token`. `redeem(token, turnId, proposal)` loads the cached entry, **immediately deletes it regardless of outcome** (single-use, no replay), then checks three things: the entry existed and hadn't expired; its stored `turnId` is **different** from the redeeming call's `turnId`; and its stored `proposal` **exactly matches** the redeeming call's freshly-computed proposal (same sku, same qty, same cart). Only if all three hold does the tool proceed to the real mutation.

**Why the `turnId` check specifically matters — the part that isn't optional.** `ToolCallingChatService`'s round-trip loop can run several rounds automatically within one customer message, with no real customer interaction between rounds. Without the turnId check, a model could receive `confirmation_required` (with the token) in round 1, and simply call the same tool again with that same token in round 2 of the *same* `converse()` invocation — an "auto-confirmation" with no genuine customer reply ever having happened. Since `ToolCallingChatService` constructs exactly one `ToolContext` per `converse()` call and reuses it for every round, every tool call within one customer turn shares the same `turnId` automatically. Redemption refuses a token whose stored `turnId` matches the current call's `turnId` — meaning **a token can only ever be redeemed from a later, separate `converse()` invocation**, i.e., a genuinely different customer message, never from a later round of the same automated loop. This closes the specific gap a naive "just check the token matches" design would have left open, without adding a single line to `ToolCallingChatService` itself — `ToolContext` already had to change for `cartId`, so giving it a free per-turn identifier came at zero additional cost to the loop.

**Honest limitation, stated plainly:** because there is no persistent conversation history across separate `ChatEntryPipeline::handle()` calls at all (a second finding from Step 1's inspection — each call builds a brand-new `[context, one user message]` array with no memory of prior turns), a real customer conversation cannot exercise this confirmation flow end-to-end *yet* — the model has no way to recall a token from an earlier customer message once that request has completed, since nothing threads conversation history between separate `handle()` calls. The mechanism is correctly built and unit/live-tested by constructing the two calls directly (exactly as a future conversation-history-aware entry point would), but it is currently unreachable through the full customer-facing round trip, for the same underlying reason `cartId` is: no session/conversation-memory layer exists yet. This is not a flaw in the confirmation design — it is the same, single architectural gap (no real request-to-request state) surfacing twice.

## Stock/salability enforcement

`AddToCartTool::execute()` calls `LiveRevalidationServiceInterface::revalidate($storeId, $customerGroupId, [$sku])` — the exact same call Task 6's `GetProductDetailsTool`/`CheckPriceTool` already make, no second implementation. If the result is empty (not found, disabled, not visible, not on this website, out of stock, or not salable — `LiveRevalidationService` doesn't distinguish which), the tool returns `{"status":"rejected","reason":"not_purchasable","sku":...}` and **stops before any confirmation is even proposed** — there is nothing to confirm if the item can never be added. This check runs unconditionally, before the confirmation-gate logic, so it cannot be bypassed by supplying a (still-invalid) confirmation token either.

## Container verification

- `php -l` on every new/changed file, both on host and via `bin/cli` — clean.
- `bin/magento setup:upgrade` — clean (picks up the new `Magento_Quote` module dependency).
- `bin/magento setup:di:compile` — clean; validates `CartResolverInterface → CartResolver`, all 3 new tools in `CommerceToolRegistry`'s array, and the auto-generated `CartItemInterfaceFactory` all resolve correctly.
- `bin/magento cache:flush` — clean.
- Full test suite: before this task, 1075 tests / 2636 assertions / 0 failures. After: **1118 tests / 2726 assertions / 0 failures.**
- **Live check 1 — confirmation toggle ON, first `add_to_cart` call does not mutate:** with `cart_mutations_enabled=1`/`require_cart_confirmation=1`, a real throwaway guest cart (created via `GuestCartManagementInterface::createEmptyCart()`), and real sample SKU `24-MB01`: the first call returned `confirmation_required`; re-fetching the real cart via `CartRepositoryInterface` showed **0 items** — **PASS**.
- **Live check 2 — completing confirmation executes the mutation:** a second call, in a different turn, with the exact token from check 1, returned `status: added`; the real cart then contained exactly one line item for `24-MB01` — **PASS**.
- **Live check 3 — confirmation toggle OFF, executes immediately:** with `require_cart_confirmation=0`, the very first `add_to_cart` call (real SKU `24-MB02`, a fresh throwaway cart) returned `status: added` immediately; the real cart showed the item — **PASS**.
- **Live check 4 — a SKU failing live stock/salability is rejected, no mutation:** created a real throwaway product, forced out-of-stock via `StockRegistryInterface`; `add_to_cart` against it returned `{"status":"rejected","reason":"not_purchasable"}`; the real cart's item count stayed unchanged (still 1, from check 2) — **PASS**. The throwaway product was deleted afterward (`isSecureArea` registry flag, same technique as Task 4).
- **Live check 5 — `remove_from_cart` for an absent SKU:** returned a clean `{"status":"not_in_cart","sku":...}` with no exception — **PASS**.
- **Live check 6 — `cart_mutations_enabled` OFF excludes all 3 cart tools:** at the documented default (disabled), a real `ToolCallingChatService` (stub chat-generation service capturing the offered `tools` argument) offered `search_products`/`get_product_details`/`compare_products`/`check_price`/`check_inventory` but **none** of `get_cart`/`add_to_cart`/`remove_from_cart` — **PASS**.
- Every throwaway guest cart and the throwaway product were deleted after their respective checks; independently verified via direct DB queries afterward (`catalog_product_entity` has no `AI-ASSISTANT-CART-TEST%` rows; no active leftover quotes). Config was restored to its documented defaults (`cart_mutations_enabled=0`, `require_cart_confirmation=1`) and independently re-verified in a fresh process.

## Test results

- New tests: 43 net new (1075 → 1118), across `CartMutationConfirmationServiceTest` (7), `CartResolverTest` (6), `GetCartToolTest` (5), `AddToCartToolTest` (11), `RemoveFromCartToolTest` (9), `ToolContextTest` (+4), plus `ConfigurationReaderTest` assertion additions to existing tests.
- Full module suite: **1118 tests, 2726 assertions, 0 failures** — zero regressions.

## Known gaps / TODOs left for later tasks

- **Admin UI is still not built** — confirmed unchanged from every prior task's gap list.
- **No real customer/guest cart-id concept exists** (this task's own central finding, detailed above under Cart/session context design) — `ToolContext::$cartId` is ready to carry a real masked quote id the moment a Controller/session layer exists, but nothing populates it today, so all 3 cart tools are currently only reachable by direct construction (as the live checks do), not through a real customer conversation.
- **No persistent conversation history across separate customer messages** (a second, related finding from this task's Step 1 inspection, not previously flagged in this log) — `ChatEntryPipeline::handle()` builds a fresh `[context, one message]` array every call with no memory of prior turns. This means the confirmation flow's two calls must currently be driven directly rather than through two real, separate customer messages — the mechanism is correct and tested, but end-to-end reachability depends on the same future session/conversation layer that owns `cartId`.
- `search_store_content` and order-assistance tools from architecture.md's broader tool list remain unbuilt — no data source or scope was ever defined for them, and were outside this task's explicit boundary.
- Real customer-group threading (flagged since Task 3) and free-text price-fabrication detection's regex limits (flagged in Task 5) remain unowned, unchanged.

## Skill files updated

- `references/progress-log.md` — updated in place: status table rows 3 (guardrails now includes `require_cart_confirmation`) and 6 (all 8 architecture.md tools now built; cart-id gap called out explicitly); added the Task 7 history entry; "Next up" now points at the admin Playground (the last item in architecture.md's dependency chain before Phase 2), with the cart-id/session gap called out as the thing most worth prioritizing for whoever builds the storefront Controller/session layer next.
- This file: `docs/status-reports/2026-08-16-cart-tools.md`.

## Not done / blocked

Nothing was left incomplete relative to this task's explicit scope (get_cart, add_to_cart, remove_from_cart, their confirmation gate, and their verification). The two gaps called out above (no cart-id/session concept, no cross-turn conversation memory) are pre-existing architectural gaps this task's Step 1 inspection surfaced and documented — per the task's own instruction, they were reported rather than papered over with an invented session mechanism, and the cart tools were built to degrade honestly (a clean `cart_not_available` result) in their absence rather than pretending they don't exist.
