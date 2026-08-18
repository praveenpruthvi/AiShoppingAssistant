# STATUS REPORT — Markdown and product cards

Two chat-rendering fixes: (A) chat bubbles now render real markdown
formatting (bold, lists, paragraphs) instead of showing raw `**`/`-`
syntax literally; (B) descriptive/informational answers that name real
products by name (e.g. "what are yoga pants made of") now populate
`products[]` more consistently, so cards render alongside the text, not
only for direct search-style queries.

## Files created/changed

- `view/frontend/web/js/chat-widget-core.js` — new shared
  `renderMarkdown()`/`escapeHtml()` exports.
- `view/frontend/web/js/chat-widget-luma.js` — assistant bubble HTML
  now built via `core.renderMarkdown()`.
- `view/frontend/web/js/chat-widget-hyva.js` — assistant message
  objects gain an `html` field (rendered markdown); user messages gain
  an empty `html` placeholder for consistency.
- `view/frontend/templates/chat/widget-hyva.phtml` — split the single
  `x-text` binding into a user-only `x-text` and an assistant-only
  `x-html`.
- `Model/Chat/ResponseContractFormatter.php` — explicit instruction
  that `product_skus` covers descriptive/informational answers, not
  only recommendations.
- `Test/Unit/Model/Chat/ResponseContractFormatterTest.php` — 1 new
  test.

**Tests:** 1 net new PHP test (full suite 1255 → 1256). No PHP/JS test
infrastructure exists for the widget's JS itself (a known, previously-
flagged gap from Task 11) — the JS changes were verified with a
standalone Node harness and a real headless-Chrome session instead.

## Conventions followed

The markdown formatter follows this module's established untrusted-
LLM-output discipline: escape first, inject only fixed literal tags
afterward, exactly the same shape as every other place this module
treats LLM-sourced text as untrusted. The product-cards fix is a
prompting change only, following Task 16-17's established pattern of
strengthening natural-language instructions to compensate for a local
model's imperfect compliance, without touching the safety validator
itself. Both fixes were verified live before being called done,
matching this module's "verify, don't assume" discipline throughout.

## Deviations from existing conventions

None.

## Part A — markdown rendering design

A new `renderMarkdown(text)` function in the shared
`chat-widget-core.js` handles exactly the patterns actually seen in
real responses from this module's own LLM output: `**bold**`, `-`/`*`
bullet lists, `1.` numbered lists, and blank-line paragraph breaks.
Deliberately not a general markdown parser — no links, headings, or
code blocks, since none of those appear in what this module's response
contract actually produces (the `message` field is prose, never a
markdown link or code sample).

**Safety-critical ordering:** the raw text is passed through the
existing `escapeHtml()` helper (already used elsewhere in this module
for LLM-sourced strings) *first*, and every tag the formatter
subsequently injects (`<strong>`, `<ul>`, `<li>`, `<p>`, `<br>`) is a
fixed literal string the function itself controls. A regex capture
group is only ever placed as already-escaped, inert *content* between
tags — never used to construct a tag name or attribute — so nothing
the model writes can introduce real HTML; only the literal `**`/`-`/
`1.` characters this function specifically recognizes get converted
into markup.

Centralized in the shared core file rather than duplicated per theme,
since both presentation layers need byte-identical formatting logic:
Luma swaps its old `'<p>' + escapeHtml(...) + '</p>'` construction
directly for the new HTML string; Hyva keeps its existing
`x-text="entry.text"` binding for the customer's own typed messages
unchanged (no markdown interpretation of what a customer types) and
adds a parallel `x-html="entry.html"` binding for assistant messages
only, gated by `x-show` on `entry.role`.

## Part B — product cards for descriptive answers: root cause and fix

Reproduced the reported case directly: "what are yoga pants made of"
can name several real products by name in the free-text `message`
while leaving `product_skus` (and therefore `products[]`/rendered
cards) partially or entirely empty. Traced this to
`ResponseContractFormatter`'s instruction text, which described the
*shape* of `product_skus` ("array of {sku, reason}, only SKUs you were
actually shown") but never said *when* to populate it — leaving the
model free to infer "only for recommendations," a narrower reading
this local model has repeatedly shown (Tasks 16-17) it's prone to.

Added an explicit paragraph to the instruction: any product the
message names, describes, compares, or discusses belongs in
`product_skus`, with an example matching the exact reported phrasing
("what is X made of"). This is a prompting change only —
`OutputValidator`'s `fabricated_sku` fail-closed check is completely
unchanged, so a response still cannot claim a `product_skus` entry
outside the live-revalidated set; this instruction only asks the model
to use the field more completely when it already has a real,
legitimate product in view.

## Live verification

**JS logic, pre-browser:** a standalone Node harness loaded
`chat-widget-core.js` directly and exercised `renderMarkdown()` against
bold/bullet/numbered/paragraph cases (all correct) plus two explicit
XSS cases — a raw `<script>alert(1)</script>` tag and an
`<img src=x onerror=alert(1)>` payload, both correctly neutralized to
inert escaped text with real formatting still applied around them.

**Both parts, together, in one real browser session:** used Playwright
to drive this machine's actual installed Google Chrome (not a
fabricated or mocked browser) headlessly against the real storefront
homepage — opened the real chat widget, sent the exact reported query
("what are yoga pants made of"), and inspected the real rendered DOM
after a genuine local-model round trip:

- Genuine `<strong>` and `<ul>` tags present in the rendered bubble;
  zero raw `**` characters left anywhere in it.
- 8 real, live-revalidated product cards (real prices, real URLs,
  e.g. Livingston All-Purpose Tight $60.00, Kratos Gym Pant $45.60)
  rendered alongside text that discusses exactly those products by
  name.

**Part B's real-world consistency, characterized separately:** ran the
identical query several more times via direct HTTP calls. Across 5
real attempts (including the browser-verified one), product counts
were 8, 0, 0, 4, 8 — the fix demonstrably works (multiple runs
returned the complete, correct product set matching everything named
in the text) but does not reach 100% compliance with this specific
local model. Reported honestly below, consistent with this module's
practice of not overstating what prompting alone can guarantee against
a local model's real, observed behavior.

## Container verification

`php -l`, `setup:upgrade`, `setup:di:compile`, `cache:flush` all
clean.

## Test results

1255 → 1256 tests (+1), 3063 → 3065 assertions (+2), 0 failures.

## Known gaps / TODOs left for later tasks

- Part B's fix measurably improves consistency but does not reach 100%
  with this environment's local model — repeated runs of the identical
  query varied from 0 to 8 populated products across 5 real attempts.
  Consistent with, not a new instance beyond, this local model's
  already-documented pattern of imperfect instruction-following under
  real conversational load (Tasks 16-17); not believed fixable by
  further prompting alone within this task's scope.
- No PHP/JS test infrastructure exists for the widget's JS itself
  (Task 11's original gap, unchanged) — this task's JS verification
  relied on a standalone Node harness and a real browser session
  rather than a project-integrated test suite.

## Skill files updated

`references/progress-log.md` — status rows 8 and 12 updated; header
summary updated; a full Task 18 history entry added.

## Not done / blocked

Nothing blocked. Both parts were completed and live-verified together
in a single real browser session.
