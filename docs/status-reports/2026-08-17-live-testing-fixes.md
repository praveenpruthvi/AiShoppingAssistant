# STATUS REPORT — Live-testing fixes

Four independent fixes from real live testing: (A) real in-scope queries
were falling back to a generic response because the local Ollama-served
model wasn't reliably honoring the structured-JSON contract once a tool
round-trip was in the conversation; (B) configurable products showed
$0.00 because price resolution used the parent's own raw (unset) price
attribute instead of Magento's real pricing framework; (C) `add_to_cart`
had no support at all for a product needing size/color selection; (D) the
storefront widget panel was a fixed small size. Also fixed, found by
chance while investigating a user-reported admin crash: invalid XML in an
entirely different, currently-disabled module was the real cause, not
this module.

## Files created/changed

**New:**
- `Model/Chat/ResponseContractFormatter.php` + its unit test — an
  always-included system message spelling out the required JSON shape.

**Modified (production):**
- `Model/Chat/ChatEntryPipeline.php` — new `ResponseContractFormatter`
  dependency; a bounded, 2-attempt self-correction retry specifically
  for `malformed_response`.
- `Model/Chat/Response/LlmResponseParser.php` — strips a wrapping
  markdown code fence before attempting to parse.
- `Model/Chat/ProductContextFormatter.php` — strengthened instruction
  wording against citing a product recognized from outside the given
  list.
- `Model/Revalidation/LiveRevalidationService.php` — price resolution
  now goes through `Product::getPriceInfo()` instead of
  `getPrice()`/`getFinalPrice()`.
- `Model/Tool/AddToCartTool.php` — configurable-product option
  resolution: `needs_options`/`invalid_option` outcomes, free-text
  option matching, real cart-item `configurable_item_options`.
- `view/frontend/templates/chat/widget.phtml`,
  `view/frontend/templates/chat/widget-hyva.phtml` — resizable panel
  CSS.
- `app/code/Aavirbhava/ProductReports/etc/email_templates.xml` —
  unrelated real bug fix (see below); a different module, not this
  one's own code.

**Modified (tests):**
- `Test/Unit/Model/Chat/ChatEntryPipelineTest.php` — message-count/
  index assertions updated for the new leading system message; 3 new
  retry-behavior tests.
- `Test/Unit/Model/Chat/Response/LlmResponseParserTest.php` — 3 new
  tests.
- `Test/Unit/Model/Revalidation/LiveRevalidationServiceTest.php` —
  price mocking rebuilt around a mocked `PriceInfoInterface` chain
  (was mocking `getPrice()`/`getFinalPrice()` directly, no longer
  called); 1 new test.
- `Test/Unit/Model/Tool/AddToCartToolTest.php` — new
  `ProductRepositoryInterface`/`Configurable`/`StockRegistryInterface`/
  option-value-factory dependencies wired into the test factory; 5 new
  configurable-flow tests.

**Tests:** 13 net new (full suite 1240 → 1253).

## Conventions followed

Every fix follows this module's established fail-closed philosophy:
an unmatched/ambiguous/incomplete configurable-option selection never
mutates the cart, a structured-output retry is bounded and never
applied to a content-fabrication rejection, and the price fix is a
strict generalization of the existing simple-product code path, not a
new special case. The self-correction retry, the option-resolution
logic, and the price-fix reasoning were each validated by direct
reproduction against the real local Ollama/Magento services *before*
being written into code — the same "verify, don't guess" discipline
this module's status reports have followed since Task 3.

## Deviations from existing conventions

None.

## Root cause for A (structured-output fallback)

Reproduced the reported symptom directly through the real
`/aichat/chat/send` endpoint and the real admin Playground's
debug-collector plumbing: "show me latest men's wear" correctly passed
scope classification, correctly retrieved 30 real candidates with real
BM25/vector scores (8 real men's-wear products ranked among the top),
and correctly reached the LLM — none of retrieval, ranking, or scope
classification were the problem. The failure was
`OutputValidator::REASON_MALFORMED_RESPONSE`: the LLM's final-round raw
text was free-form markdown prose, not the requested JSON.

Reproduced the underlying cause directly against the real Ollama
instance by replaying the exact captured request payload: an identical
`response_format: json_schema` request produces clean, correctly-
shaped JSON for a short single-turn prompt, but the same schema request
is ignored — plain prose, or JSON wrapped in a code fence with an
invented shape — once a prior assistant tool-call and tool-result
message are present, which every real product-search turn always has.
This is a genuine limitation of this specific local model (`qwen3.5`
via Ollama) under this environment's real, current conditions, not a
defect in how the request itself was built.

## Fix design for A

Three complementary changes, each validated independently against the
real Ollama instance before being written into code:

1. **`ResponseContractFormatter`** — a new, always-included system
   message (unlike `ProductContextFormatter`'s conditional one) that
   spells out the exact required JSON field names in plain language.
   `response_format`/`ChatRequest::responseSchema` alone is sufficient
   for OpenAI's real API (which enforces the schema at the sampling
   level) but was proven, live, not to reliably hold for this local
   model — an explicit natural-language reinforcement restores
   compliance in the cases tested.
2. **A bounded, single self-correction retry in `ChatEntryPipeline`**
   — on a `malformed_response` result specifically (never on
   `fabricated_sku`/`fabricated_url`/`fabricated_price`, which are
   content problems the model already got the *format* right on;
   retrying those risks encouraging another hallucination, not fixing
   a format issue), the model's own bad output plus a corrective
   instruction are appended to the conversation and the call is
   retried once (2 attempts total — this is a compliance repair, not a
   resilience mechanism; `FallbackChatGenerationService` already owns
   retrying on provider *availability* failures, a different concern).
3. **Markdown-code-fence tolerance in `LlmResponseParser`** — defense
   in depth for the "valid JSON but wrapped in a ```json fence"
   failure mode observed in a separate reproduction; only strips a
   fence around what's presumed to be the entire payload, never
   attempts to extract JSON from surrounding prose (which would risk
   silently accepting genuine non-compliance).

**A secondary, distinct finding surfaced during this work, not part of
the original symptom:** this same local model sometimes cites a real,
well-known Magento Luma demo-catalogue product it recognizes from its
own training data rather than one actually present in this store's
retrieved candidates — confirmed directly (`MH01`/"Chaz Kangeroo
Hoodie" cited, never in the real candidate set). `OutputValidator`'s
existing `fabricated_sku` check correctly caught and rejected this
every time it occurred — this is the safety mechanism working as
designed, not a bug. Strengthened `ProductContextFormatter`'s
instruction wording to explicitly rule out citing anything recognized
from outside the given list; this measurably reduced, but did not
eliminate, how often this specific local model still tried — reported
honestly as a residual, accepted limitation of this model under this
environment, not something believed fixable by further prompting
alone within this task's scope.

## Price-fix design for B

`Product::getPrice()` returns a configurable product's own raw `price`
attribute, which is `0`/unset — only its child simple products carry a
real price. `LiveRevalidationService::revalidateOne()` used that value
directly as `RevalidatedProduct::$price`. Confirmed live against a real
configurable product (`MT07`) that `getPrice()` returns `0` while
`Product::getPriceInfo()->getPrice(RegularPrice::PRICE_CODE|
FinalPrice::PRICE_CODE)->getAmount()->getValue()` correctly resolves to
`$22.00` — dispatching through the type-specific pricing model
(`ConfigurablePriceResolver` for configurable, resolving the minimum
salable child's price, the exact "As low as" value Magento's own
PDP/catalog listing already show) — and resolves to the identical value
`getPrice()`/`getFinalPrice()` already returned for a simple product
(`24-MB01`, `$34.00` either way). Replacing both calls with the
`PriceInfo`-based resolution is therefore a strict generalization, not
a configurable-specific branch — simple-product pricing is provably
unchanged.

## Configurable-product cart flow design for C

This is the most involved change in this task, so the full flow:

**Product/type loading.** `execute()` now loads the product once
(after the existing SKU revalidation succeeds) via
`ProductRepositoryInterface::get()`, and checks
`$product->getTypeId() === Configurable::TYPE_CODE`. For any other
type, behavior is byte-for-byte unchanged from before this task.

**No selection yet.** A call with no `option_selection` argument
returns `{"status": "needs_options", "sku": ..., "option_types": [...]}`
listing every real configurable attribute (label + every real value
label) via `Configurable::getConfigurableAttributesAsArray($product)`
— never a guess, never a mutation.

**Matching free text to real values.** `option_selection` (e.g. "M,
gray", or the task's own example "XL, pink one") is split on commas
into phrases. Each phrase is matched, case-insensitively, against every
real attribute value's label — either an exact match, or the phrase
*containing* the label (so "pink one" matches "Pink"; deliberately only
this direction, never the reverse, so a shorter phrase can never
spuriously match a longer, more specific label). A phrase matching more
than one distinct (attribute, value) pair — whether across two
different attributes or two values of the *same* attribute — is
rejected as ambiguous rather than resolved by guessing; a phrase
matching nothing real is rejected as `invalid_option`, naming exactly
which phrase didn't match anything; a phrase that would set a *second*,
different value for an attribute an earlier phrase already resolved is
also rejected (a conflicting selection, never silently overwritten).

**Incomplete selection.** If not every required attribute resolved to
a value (e.g. only Size given, Color still missing), the result is the
same `needs_options` shape as the no-selection case — the model can ask
for what's still needed.

**Resolving the real child, and why it's checked separately from
`LiveRevalidationServiceInterface`.** Once every attribute resolves to
one value, `Configurable::getUsedProducts($product)` is scanned for the
one real child whose attribute data (`$child->getData($attributeCode)`)
matches every resolved value simultaneously. If no such child exists,
the combination itself doesn't correspond to anything real —
`invalid_option`, not a mutation. If a real child is found, its stock
and salability are checked *directly* (`StockRegistryInterface` +
`Product::isSalable()`) rather than reusing
`LiveRevalidationServiceInterface::revalidate()` on the child's own
SKU — a configurable child is legitimately not individually visible in
the catalogue (it's sold *as* the parent with this selection, per
standard Magento configuration), and that service's visibility gate
would incorrectly reject a real, purchasable combination. The parent
being salable only guarantees *some* combination is — not necessarily
the one the customer picked, so this per-child check is real, not
redundant.

**Actually adding it.** Only once a specific, real, salable child is
resolved does the tool proceed through the *existing*, unchanged
confirmation gate, and — on confirmation — build the cart item using
Magento's own real configurable mechanism:
`CartItemInterface::setSku()` stays the **parent** SKU throughout (not
the child), and `CartItemInterface::setProductOption()` carries a
`ConfigurableItemOptionValueInterface` entry per resolved attribute
(option id = attribute id, option value = value index) via the
extension-attributes path — the identical shape
`Magento\ConfigurableProduct\Model\Quote\Item\CartItemProcessor` uses
for the real `POST /V1/carts/mine/items` REST endpoint. This is why the
parent SKU is used throughout rather than ever substituting the child's
own SKU: Magento's cart-item API expects the parent + selections, not
a bare child SKU, and using the parent also keeps the item
revalidatable by the existing, unchanged `LiveRevalidationServiceInterface`
path used earlier in `execute()`.

**Confirmation-proposal identity.** The confirmation proposal (compared
verbatim between the propose and confirm calls, per Task 7's existing
mechanism) now also includes the raw `option_selection` text — the
model is expected to pass the same sku/qty/option_selection on both
calls, exactly mirroring how sku/qty already worked; no new complexity
was added to the confirmation service itself.

## Widget resize approach for D

Pure CSS, both templates: default size raised from 320×480px to
400×600px, `min-width: 300px`/`min-height: 360px`/
`max-width: calc(100vw - 2rem)`/`max-height: calc(100vh - 6rem)`
bounds, and native `resize: both` (Luma's `<style>` block) / Tailwind's
`resize` utility class (Hyva's arbitrary-value Tailwind classes).
`overflow` changed from `hidden` to `auto` on the resizable element
itself (required for `resize` to take effect at all) — the panel's own
inner `.aavirbhava-chat-log`/message-list area already handles its own
scrolling via `overflow-y: auto`, so this doesn't introduce a
redundant outer scrollbar in normal use. No JS changes — neither
`chat-widget-core.js` nor the Luma/Hyva presentation-layer JS files
ever manipulate panel width/height.

## Unrelated bug found and fixed: admin config-page crash

While starting Part A's diagnosis, the user shared a screenshot of a
real admin error. It had nothing to do with this module.
`app/code/Aavirbhava/ProductReports/etc/email_templates.xml` declared
a `<template>` element with an invalid `subject` attribute (not allowed
by `Magento_Email`'s own XSD) and a missing required `module`
attribute. Email-template configuration is merged globally on essentially
every admin page load (reached via `Magento\Framework\Mail\Template\Factory`,
triggered here by the Two-Factor-Auth module's own email notifier, not
anything section-specific) — so this invalid XML crashed the *entire*
admin config editor, not just anything in this module's own section.
The real exception stack trace in the user's screenshot named the file
directly, immediately resolving what several prior sessions' server-side
diagnostic attempts against *this* module could never have found, since
the bug was never in this module at all.

Fixed the XML: removed the invalid `subject` attribute (the real
subject line is already defined inside the referenced `.html` template
file itself via Magento's own `<!--@subject ... @-->` convention),
added the required `module="Aavirbhava_ProductReports"` attribute, and
corrected `area="adminhtml"` to `area="frontend"` to match where the
referenced template file actually lives
(`view/frontend/email/outofstock_report.html`). That module is
currently disabled in this environment (apparently as an earlier,
unrelated workaround for this same crash, found via `bin/magento
module:status`) — so the live symptom isn't actually reproducible in
this environment's current state either way — but the XML defect
itself is real and now fixed, so the crash won't recur if the module
is ever re-enabled. Left the module's own enabled/disabled state
untouched, since flipping that is a separate decision outside this
task's scope.

## Live verification for all four parts

- **A:** repeated real calls to `/aichat/chat/send` for "show me latest
  men's wear" no longer return `malformed_response` — outcomes are now
  either a genuine, correctly-shaped, product-specific answer (one real
  run returned 8 real products with `reason_code: null`) or a
  legitimate `fabricated_sku`/`fabricated_price` content rejection
  (this local model's own remaining, honestly-documented limitation),
  never the original format failure.
- **B:** a real, DI-resolved `LiveRevalidationServiceInterface::revalidate()`
  call against `MT07` (a real configurable product) now returns
  `price: 22` instead of `0`, matching the real "As low as" price
  Magento's own pricing framework independently resolves for the same
  product.
- **C:** a real guest cart (created via
  `CartManagementInterface::createEmptyCart()`, deleted afterward)
  proved the full flow against real data, bypassing the LLM layer
  entirely (per the "swap only the leaf" methodology, chosen here
  because the local LLM was under real, verified load — see the
  infrastructure note below): a call with no `option_selection`
  returned the real Size (`XS, S, M, L, XL`) and Color (`Gray`) values
  this specific product actually has; a call with `"M, gray"` (tolerant
  lowercase phrasing) resolved to and added the real child — confirmed
  directly via `quote_item` table rows: one `configurable`-type parent
  row plus one `simple`-type child row for SKU `MT07-M-Gray`,
  Magento's own standard shape for a configurable cart addition; a call
  with `"purple, XXXL"` (neither a real option for this product) was
  correctly rejected `invalid_option` with zero additional cart rows.
- **D:** the real storefront homepage now serves the resizable panel
  CSS (`resize: both`, the new 400×600px default, the new min/max
  bounds) in place of the old fixed-size rule.

## Container verification

`php -l`, `setup:upgrade`, `setup:di:compile`, `cache:flush` all clean
— run twice (once before, once after an unrelated mid-task
infrastructure event, see below), both times clean.

## Test results

1240 → 1253 tests (+13), 3010 → 3060 assertions (+50), 0 failures.

## Known gaps / TODOs left for later tasks

- The local model's occasional citation of a real-but-not-actually-
  retrieved SKU (Part A's secondary finding) is reduced, not
  eliminated, by the strengthened instruction wording — an honestly-
  reported residual limitation of this specific local model, not
  something believed fixable by further prompting alone within this
  task's scope.
- `FullProductReindexer`'s successful runs appear to leave prior
  run-indices behind in OpenSearch (5 accumulated
  `aavirbhava_ai_product_rag_store_1_run_*` indices were observed
  during this task, only the alias-pointed one live) — noticed
  incidentally during this task's own verification, not investigated
  or fixed, since it's unrelated to any of this task's four parts.
- The admin config-page crash's real root cause is now fixed at the
  XML level, but the affected module remains disabled in this
  environment, so the original live symptom is not currently
  reproducible either way to give a final "confirmed fixed" browser
  check — the fix is confirmed correct by direct inspection against
  the relevant XSD and by the exact exception stack trace naming this
  file, not by a live re-crash-then-fix cycle.

## Skill files updated

`references/progress-log.md` — status rows 6, 8, 9, and 12 updated;
header summary updated; a full Task 16 history entry added,
including the incidental infrastructure event; "Next up" section's
"Done"/residual-gaps lists updated.

## Not done / blocked

Nothing blocked. All four parts (A-D) were completed and live-verified,
plus the unrelated admin-crash root cause was found and fixed.

## Incidental infrastructure event

Partway through this task's live verification, all Docker containers
exited unexpectedly — OpenSearch specifically with signal 137
(SIGKILL), the classic OOM-kill signature. This was real, sustained
host memory pressure from this task's own repeated real local-LLM
inference calls: a trivial single-word completion measured at ~50
seconds under load in this environment during this task's own testing,
confirming the local model was under genuine resource strain, not
merely slow to respond over the network. Restarted cleanly via
`bin/start`; confirmed no data loss (the real OpenSearch index's 811
documents were intact afterward, alias correctly still pointing at the
live physical index) and re-ran the complete container verification
sequence again after the restart — all clean, no regressions from the
restart itself. Not a defect in this module's code. Flagged here since
local LLM inference load is a real, current constraint on this specific
host: future live-check-heavy tasks against this environment should
pace real LLM calls deliberately and prefer direct DI-resolved
verification of non-LLM pipeline stages where the LLM boundary itself
isn't what's being tested — exactly the approach Part C's cart
verification used here once this constraint became apparent.
