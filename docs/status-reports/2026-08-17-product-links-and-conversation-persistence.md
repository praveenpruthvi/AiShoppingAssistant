# STATUS REPORT — Product links and conversation persistence

Two fixes: (A) product cards in chat now open their link in a new tab,
safely; (B) a page reload — or a brand new tab, as long as the browser
still holds the same session — no longer erases the visible chat
transcript.

## Files created/changed

**New:**
- `Model/Chat/ConversationHistoryViewBuilder.php` — filters raw stored
  history down to exactly what a customer actually saw.
- `Test/Unit/Model/Chat/ConversationHistoryViewBuilderTest.php` — 3 tests.
- `Controller/Chat/History.php` — read-only `GET /aichat/chat/history`.
- `Test/Unit/Controller/Chat/HistoryTest.php` — 4 tests.

**Modified:**
- `Block/Frontend/ChatWidget.php` — new `getHistoryUrl()`.
- `Test/Unit/Block/Frontend/ChatWidgetTest.php` — 1 new test.
- `view/frontend/web/js/chat-widget-core.js` — new `fetchHistory()`.
- `view/frontend/web/js/chat-widget-luma.js` — history restoration on
  init; `target="_blank" rel="noopener noreferrer"` on the product link.
- `view/frontend/web/js/chat-widget-hyva.js` — same two changes.
- `view/frontend/templates/chat/widget.phtml` — new `data-history-url`
  attribute.
- `view/frontend/templates/chat/widget-hyva.phtml` — `historyUrl`
  threaded into the Alpine component config; `target="_blank"
  rel="noopener noreferrer"` on the product link.

**Tests:** 8 net new PHP tests (full suite 1256 → 1264). No PHP/JS test
infrastructure exists for the widget's JS itself (Task 11's original
gap, unchanged) — the JS changes were verified with a standalone Node
harness and two real headless-Chrome sessions instead, the same split
this module has used for its JS since Task 18.

## Conventions followed

The new `History` controller mirrors `Send.php`'s thin-controller
shape exactly (identity/config resolution delegated to real
collaborators, controller only orchestrates and serializes). The
history-filtering logic follows this module's Api/Model split with a
dedicated, single-purpose class, matching `ProductContextFormatter`/
`ChatResponseSerializer`'s existing precedent of small, focused
transformation classes with no separate interface where only one
implementation will ever exist. Every design decision below was
verified against real, live behavior before being written into code —
this module's standing discipline.

## Deviations from existing conventions

None.

## Product links in a new tab

`target="_blank" rel="noopener noreferrer"` added to the product-title
anchor in both Luma's `renderProductCard()` and Hyva's template.
`rel="noopener noreferrer"` is not optional decoration: without it, a
page opened via `target="_blank"` retains a live `window.opener`
reference back to the storefront tab, which the opened page's own
script — fully outside this module's control, since it's whatever the
product's real, live URL points to — could use to navigate the
original tab elsewhere. `noopener` (`noreferrer` for older browser
compatibility) closes that well-known phishing vector.

## Conversation persistence design

The actual gap was never "nothing remembers the conversation" — the
backend has persisted every turn since Task 8
(`ConversationHistoryStoreInterface`, keyed by a `ChatSession`-held
conversation id already living in Magento's own session cookie). The
gap was purely on the frontend: nothing ever asked the backend for
that history when the widget's JS state started fresh on every page
load, so a real, remembered conversation looked erased the moment the
page reloaded.

Closed with a new read-only `GET /aichat/chat/history` endpoint plus a
JS restore call on init — deliberately not any new client-side storage
(no localStorage, no BroadcastChannel). Since the session cookie is
already shared by every tab of the same browser, this single mechanism
naturally covers both "reload the current tab" and "open a new tab"
(the second scenario called out explicitly, alongside the new
product-link tabs) with zero extra coordination.

**Why the raw stored history isn't served directly.**
`recentMessages()` returns every persisted `ChatMessage`, including
the intermediate `assistant` messages that carry `toolCalls` and empty
content (a tool-call request) and `tool`-role messages (the raw tool
result JSON) a real round-trip produces — internal plumbing a customer
never actually saw, kept only so the LLM has full context on a later
turn. Serving that raw list to the frontend would both leak internal
tool-call arguments/results and render as visible noise in the
transcript. `ConversationHistoryViewBuilder` filters to exactly the
two kinds of message a customer actually saw — their own `user`
messages, and the final `assistant` message of each turn (content
non-empty, no `toolCalls` — mutually exclusive by `ChatMessage`'s own
constructor invariant) — reconstructing the real transcript, not a
debug dump of it.

**Scope decision, stated explicitly.** Restoring past turns'
structured product cards, follow-up-question buttons, or the
confirmation affordance was deliberately not attempted — only the
final response *text* is persisted per turn, never the full
`AssistantResponse` (with its live-revalidated prices/URLs) those UI
elements were built from at the time. A reload/new tab restores the
conversation's readable text faithfully; it does not replay a
point-in-time product card whose price/stock could since be stale.
Doing so would require persisting the full structured response per
turn — a real schema change, out of this task's scope.

**Why `History` never allocates a fresh conversation id.**
`Controller/Chat/History` deliberately reads
`ChatSession::getConversationId()` directly rather than going through
`ChatIdentityResolverInterface::resolve()` (Task 8). `resolve()`
always returns *some* id, minting a brand new one via `random_bytes()`
if none exists yet, and — when `cart_mutations_enabled` — auto-vivifies
a real guest quote as a side effect. Since the widget calls this
endpoint on every single page load, going through `resolve()` would
mean every storefront pageview from every visitor, including one who
has never opened the chat widget, would silently create session state
and, for many stores, a real database row. No conversation id yet
means nothing to restore, by definition — the controller returns an
empty list without touching identity allocation at all. Every other
failure mode (assistant disabled, any unexpected exception anywhere in
the chain) also degrades to an empty list rather than an error — a
restore failing is a lost convenience, never a broken page.

## Live verification

**JS logic, pre-browser:** a standalone Node harness exercised
`fetchHistory()` directly — correctly filters out `tool`-role and
empty-content messages, and degrades to an empty array (never throws)
on a simulated network failure.

**End to end, real data, no scripting at any layer:**

1. A real, fresh session's `GET /aichat/chat/history` returned
   `{"messages": []}`.
2. After a real message sent through `/aichat/chat/send` in the same
   cookie jar, the same history call returned exactly the real user
   message and the real assistant reply — nothing else, no tool-call
   plumbing.
3. A full real-browser session (Playwright driving this machine's
   actual installed Chrome) sent a real message, reloaded the page,
   reopened the widget, and found the identical two-message transcript
   restored. Opening a brand new tab in the same browser context
   showed the exact same transcript again, with zero extra client-side
   wiring.
4. The same session confirmed a real rendered product-card link
   carries `target="_blank"` and `rel="noopener noreferrer"` in the
   live DOM, pointing at the product's real URL.

## Container verification

`php -l`, `setup:upgrade`, `setup:di:compile`, `cache:flush` all
clean.

## Test results

1256 → 1264 tests (+8), 3065 → 3077 assertions (+12), 0 failures.

## Known gaps / TODOs left for later tasks

- Past turns' product cards/follow-ups/confirmation affordance are not
  restored on reload — a real, deliberate scope boundary (see above),
  not an oversight. A future task wanting full-fidelity restoration
  would need to persist the structured `AssistantResponse` per turn,
  not just its message text.
- Still no PHP/JS test infrastructure for the widget's JS itself
  (Task 11's original gap, unchanged).

## Skill files updated

`references/progress-log.md` — status rows 1, 6, and 12 updated;
header summary updated; a full Task 19 history entry added.

## Not done / blocked

Nothing blocked. Both parts were completed and live-verified,
including a real reload and a real new tab in the same browser
session.
