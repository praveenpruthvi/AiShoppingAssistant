# STATUS REPORT — Storefront chat widget

Task 11 of the Aavirbhava_AiShoppingAssistant build sequence: a real,
renderable chat UI on the frontend, talking to Task 8's
`Controller/Chat/Send` endpoint, working on both default/Luma and Hyva.
This is the first task in the sequence producing something a real
shopper actually sees and clicks — every prior task (1-10) was backend
only.

## Files created/changed

**New frontend view (the module's first):**
- `Block/Frontend/ChatWidget.php` — selects the Luma or Hyva template based on whether `Hyva_Theme` is an installed module; gates all rendering on `general.enabled`.
- `view/frontend/layout/default.xml` — adds the widget block to the standard `before.body.end` container (the same container Magento's own persistent footer uses).
- `view/frontend/templates/chat/widget.phtml` — default/Luma template.
- `view/frontend/templates/chat/widget-hyva.phtml` — Hyva template (Alpine.js + Tailwind).
- `view/frontend/web/js/chat-widget-core.js` — shared, dependency-free network/data-shaping module.
- `view/frontend/web/js/chat-widget-luma.js` — Luma's vanilla-JS DOM-manipulation adapter.
- `view/frontend/web/js/chat-widget-hyva.js` — Hyva's Alpine.js data-component adapter.

**Modified response contract (see Deviations below):**
- `Model/Chat/ChatPipelineResult.php` — `generated()` gained an optional `bool $awaitingConfirmation = false`, plus a new `isAwaitingConfirmation()` getter.
- `Model/Chat/ChatEntryPipeline.php` — computes it by scanning this turn's tool round-trip.
- `Model/Chat/ChatResponseSerializer.php` — new `awaiting_confirmation` JSON key on both response branches.

**Tests:** 7 net new tests (full suite 1197 → 1204) — a new `Test/Unit/Block/Frontend/ChatWidgetTest.php` (5), plus extensions to `ChatEntryPipelineTest`/`ChatResponseSerializerTest` (1 each) for the new field.

## Conventions followed

- `ChatWidget` extends `Magento\Framework\View\Element\Template`, the standard frontend Block base class.
- Layout/template naming (`view/frontend/layout/default.xml`, `templates/chat/*.phtml`, `web/js/*.js`) matches Magento's own conventions exactly.
- The widget positions in `before.body.end`, the same standard container Magento's own `Magento\Theme\Block\Html\Footer` uses for persistent, page-wide elements.
- `$block->escapeHtml()`/`escapeHtmlAttr()`/`escapeUrl()`/`escapeJs()` (confirmed as this Magento version's actual convention by inspecting a real core template, `Magento_Theme::text.phtml`, rather than assuming a newer `$escaper` variable is auto-injected).
- The widget never reimplements business logic: it sends raw message text to the existing endpoint and renders exactly what comes back, matching this task's own explicit instruction.
- The one backend change (`awaiting_confirmation`) follows this module's established "verify every caller, add an optional trailing parameter, never change an existing return type's meaning" discipline used since Task 6.

## Deviations from existing conventions

**One, deliberate, and explained in detail below:** the response contract (`ChatPipelineResult`/`ChatResponseSerializer`) gained a new field, `awaiting_confirmation`. This wasn't anticipated going in — Task 7's confirmation mechanic was designed so the `confirmation_token` (a security-relevant value) never leaves the backend/LLM conversation context, which also means, as built, *nothing* told an HTTP consumer that a response was proposing a cart change. Without this, "render a confirm/cancel UI when a response indicates confirmation_required" (this task's own explicit requirement) was not implementable without either a fragile client-side text-sniffing heuristic (rejected — against this module's whole philosophy of never trusting LLM free text for structured decisions) or this minimal, additive surfacing of an already-computed fact. `AddToCartTool`/`RemoveFromCartTool`/`CartMutationConfirmationService` — the only place that actually *decides* whether confirmation is required — are completely unchanged.

## Hyva compatibility findings

**No Hyva theme or `hyva-themes/*` package is installed in this environment.** Confirmed via `composer.json`, `vendor/` (no `hyva-themes` directory), and — checked again immediately before writing this report, not assumed — `bin/magento module:status Hyva_Theme`, which reports "Module does not exist." Active theme is `Magento/luma` (theme_id 3).

**What was built for Hyva:** `chat/widget-hyva.phtml` using Alpine.js directives (`x-data`, `x-show`, `x-for`, `@click`, `x-model`, `x-transition`) and Tailwind utility classes throughout (no custom CSS file — Hyva sites already load Tailwind), plus `chat-widget-hyva.js` registering a global `aavirbhavaChatWidget()` factory function (the simplest, most portable way for a third-party module to add an Alpine component to a Hyva page without depending on Hyva's own `Alpine.data()` registry timing). `ChatWidget::__construct()` selects this template automatically whenever `Hyva_Theme` is an installed module — no separate compatibility package or merchant action required.

**What could and couldn't be verified:** the Luma template/JS were live-verified rendering on the real storefront (see Container verification). **The Hyva template/JS could not be rendered against a real Hyva theme, because none exists in this environment** — this is stated plainly rather than claimed as proven. The Hyva markup/JS were built to Hyva's documented conventions and pass Node's JS syntax check, but their actual behavior on a real Hyva page (Alpine reactivity, Tailwind class resolution, script-load ordering relative to Hyva's own Alpine bootstrap) is unverified.

## Shared JS design

`chat-widget-core.js` is a dependency-free, plain-script (non-AMD) module owning exactly two things: the `fetch()` call to `Controller/Chat/Send` and normalizing its JSON response into a plain view-model (`normalizeResponse`/`normalizeProduct`/`formatPrice`) — pure data shaping, no DOM/reactive-state code. Both theme layers load this same file unmodified. Rendering itself is **not** unified: Luma's imperative DOM manipulation (`chat-widget-luma.js`, `document.createElement`/`innerHTML`) and Hyva's declarative Alpine reactive bindings (`chat-widget-hyva.js`, an Alpine data object with `x-for`/`x-show` in the template) are different enough paradigms that forcing one shared rendering layer would make one theme use the other's idioms — a real, considered tradeoff, not an oversight. Behavior (what happens on each interaction: toggle open/close, send a message, show a loading state, render products/follow-ups/confirm-cancel) is equivalent between the two.

## Product card / cart-confirmation rendering

**No fabricated data, confirmed by construction and live check:** `normalizeProduct()` only ever reads `sku`/`name`/`price`/`special_price`/`url`/`reason`/`recommendation_type` — exactly the fields `ChatResponseSerializer::serializeProduct()` sends, all sourced from `RevalidatedProduct` (Task 4's live-revalidation contract). No image is rendered at all — there's no already-built safe data source for one (Task 4's report explicitly left image URLs out of the contract for this reason), and inventing a new fetch path was out of this task's explicit scope. A live check (direct invocation, real live-revalidated catalogue data through the real, unmodified `ChatResponseSerializer`) confirmed the exact JSON shape the widget's JS expects, including a real price (`$34.00`), a real product URL, and a real `verified_at` timestamp.

**Confirmation flow, confirmed by construction and unit test:** when `awaiting_confirmation: true`, both templates render Yes/No buttons that send the literal text "Yes, please go ahead."/"No, please cancel that." as the next chat message — a quick-reply convenience over the existing conversational confirmation mechanic (Task 7), not a second pathway. `ChatEntryPipelineTest::testConfirmationRequiredToolResultInTheRoundTripMarksTheResultAsAwaitingConfirmation` proves the detection logic fires correctly on a real `confirmation_required` tool-result shape (the exact JSON `AddToCartTool`/`RemoveFromCartTool` actually produce); `ChatResponseSerializerTest` proves it serializes correctly in both directions.

## Container verification

- `bin/cli php -l` on every new/modified PHP/phtml file: clean.
- `node --check` on all 3 new JS files: clean (no JS test framework exists in this project — checked `package.json`, found none — this is the substitute, stated plainly rather than skipped).
- `bin/magento setup:upgrade` / `setup:di:compile` / `cache:flush`: all clean.
- Full suite: **1204 tests / 2936 assertions / 0 failures**, up from 1197/2925.

**Live checks**, all against the real running docker-magento storefront:

1. **Disabled by default.** This store's actual `general.enabled` value is `0` (the shipped default). A real `curl` against the live homepage contains zero widget markup — confirmed before touching any config, the store's actual current state.
2. **Renders when enabled.** `general.enabled` was temporarily set to `1`; the real homepage response then contained the widget's HTML with the correct send URL (`https://magento.test/aichat/chat/send/`), and both Luma JS assets (`chat-widget-core.js`, `chat-widget-luma.js`) resolved with real HTTP 200s at their deployed static URLs.
3. **A genuine, unscripted HTTP round trip.** A real `curl -X POST` to the real `/aichat/chat/send` with an off-topic message (which short-circuits before retrieval — this environment has no OpenSearch index configured, the same limitation Tasks 9-10 documented, so no in-scope message can reach a generated response through a real HTTP request here) returned a real 200 JSON response, including the new `awaiting_confirmation: false` field — proving the endpoint, routing, and new serializer field all work through the actual browser-facing path, no scripting involved.
4. **Products[]/confirmation shape, direct invocation.** Since no in-scope message can reach a generated response via real HTTP here, a script fed a real, live-revalidated product (`24-MB01`, real $34.00 price, real URL) through the real, unmodified `ChatResponseSerializer`, confirming the exact JSON shape (including `awaiting_confirmation: true`) the widget's JS is built to parse.
5. **Reverted cleanly.** `general.enabled` was set back to `0`; a final `curl` reconfirmed zero widget markup on the live homepage.

## Test results

1197 → 1204 tests (+7), 2925 → 2936 assertions (+11), 0 failures. New: `ChatWidgetTest` (5). Modified: `ChatEntryPipelineTest` (+1), `ChatResponseSerializerTest` (+1).

## Known gaps / TODOs left for later tasks

- **Retrieval-layer failures still propagate as an uncaught exception**, not a graceful chat error — flagged already in Task 5's own report and reconfirmed live during this task's verification (an in-scope message against this environment's unconfigured OpenSearch index produces a raw PHP exception page, not JSON). A real customer would see a broken page if this happened on a live, otherwise-working store. Out of this task's scope (a widget-rendering task, not a pipeline-robustness one) but worth flagging prominently since it's now directly visible to an actual UI for the first time.
- The Hyva template/JS are unverified against a real Hyva theme (see Hyva compatibility findings).
- No JS unit-test framework or browser-automation tooling exists in this project for the new JS files.
- `actions[]` (architecture.md's generic suggested-follow-up-action field) has no widget UI — not named in this task's required feature list, deliberately left unbuilt.

## Skill files updated

- `references/progress-log.md` — status table row 1 updated, new row 12 added ("Storefront chat widget"), rows 6/8 updated for the new endpoint consumer and `awaiting_confirmation` field; full Task 11 history entry added; "Next up" rewritten (and a pre-existing duplicated paragraph from an earlier task's edit cleaned up) to state Phase 1 is functionally complete per architecture.md's own roadmap table, with an explicit done/residual-gap/explicitly-later-phase accounting.

## Not done / blocked

Nothing blocked. The Hyva live-verification gap is an environment limitation (no Hyva theme installed), not something left undone by choice — stated plainly rather than worked around.
