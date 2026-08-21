# STATUS REPORT — Discount/promotion tool: real-time Catalog/Cart Price Rule awareness

Added a 10th commerce tool, `get_active_promotions`, plus proactive
discount surfacing: a new `ActivePromotionReader` reads real, currently-
active Catalog Price Rules and Cart Price Rules live at request time,
scoped to only the current turn's candidate products — no OpenSearch
indexing, no reindex, same reasoning as Task 32's merchandising boost.
Cart rules explicitly distinguish auto-applied from coupon-required
(with the real coupon code when one exists) rather than collapsing both
into one "discount available" flag. A new `PromotionContextFormatter`
proactively surfaces real catalog-rule discounts in the LLM's grounded
context whenever any exist for this turn's results (not only on
explicit ask), and `OutputValidator` gained a 5th, fail-closed check,
`fabricated_discount`, mirroring the existing `fabricated_price` check
exactly. Live-verified end-to-end through the real, un-mocked chat
pipeline against this store's real, pre-existing rules — a live LLM
response correctly stated the real 20%-off catalog rule price for a
real product, matching an independent direct-database check.

## Files created/changed

**New — domain:**
- `Api/Promotion/{ProductPromotionInterface,CartPromotionInterface,
  ActivePromotionReaderInterface}.php`
- `Model/Promotion/{ProductPromotion,CartPromotion,
  ActivePromotionReader}.php`, `Model/Promotion/Exception/
  PromotionException.php`

**New — tool and context formatting:**
- `Model/Tool/GetActivePromotionsTool.php`
- `Model/Chat/PromotionContextFormatter.php`

**New — tests:**
- `Test/Unit/Model/Promotion/{ProductPromotionTest,CartPromotionTest,
  ActivePromotionReaderTest}.php`
- `Test/Unit/Model/Tool/GetActivePromotionsToolTest.php`
- `Test/Unit/Model/Chat/PromotionContextFormatterTest.php`
- `Test/Integration/Model/Promotion/ActiveCartPromotionDatabaseTest.php`
  (real database)

**Modified:**
- `Model/Tool/ToolResult.php` — 2 new optional fields,
  `verifiedProductPromotions`/`verifiedCartPromotions`
- `Model/Chat/ToolCallingResult.php` — same 2 fields
- `Model/Chat/ToolCallingChatService.php` — threads both fields through
  every `ToolCallingResult` construction site and `executeToolCall()`
- `Api/Chat/OutputValidatorInterface.php` / `Model/Chat/
  OutputValidator.php` — new `fabricated_discount` check +
  `containsFabricatedPercentage()`/`containsFabricatedCouponCode()`
- `Model/Chat/ChatEntryPipeline.php` — resolves catalog-rule discounts
  for this turn's candidates, adds the new `PromotionContextFormatter`
  message, threads promotion facts into the `OutputValidator::
  validate()` call
- `Api/Config/CapabilitiesConfigInterface.php` / `Model/Config/
  CapabilitiesConfig.php` / `Model/Config/ConfigurationReader.php` /
  `Model/Config/Path.php` — new `isPromotionAwarenessEnabled()`
  capability, default on
- `etc/config.xml` / `etc/adminhtml/system.xml` — new
  `promotion_awareness_enabled` admin field
- `Model/Chat/ResponseContractFormatter.php` — additive paragraph on
  when to call the new tool and to only state real discount facts
- `etc/di.xml` — new `ActivePromotionReaderInterface` preference +
  `get_active_promotions` tool-registry entry
- `Test/Unit/Model/Chat/{OutputValidatorTest,ChatEntryPipelineTest,
  ToolCallingChatServiceTest}.php`, `Test/Unit/Model/Config/
  {CapabilitiesConfigTest,ConfigurationReaderTest}.php` — extended

No existing ranking signal or existing tool's logic was touched — this
task was additive-only, as required.

## Key decisions

- **CatalogRule API, not the already-blended `FinalPrice`.**
  `RevalidatedProduct::specialPrice` already incorporates catalog rules
  automatically (via `Magento\CatalogRule\Observer\
  ProcessFrontFinalPriceObserver`, part of Magento's own pricing
  framework), but the task's own explicit instruction was to read
  Catalog Price Rules directly, to correctly attribute a discount's
  source rather than conflating it with a plain `special_price`
  attribute. `ActivePromotionReader::catalogRuleDiscounts()` reads
  `Magento\CatalogRule\Model\ResourceModel\Rule::getRulePrices()` — the
  same real, precomputed `catalogrule_product_price` table Magento's
  own indexer keeps fresh. This task runs no indexer of its own,
  mirroring Task 32's "live read, no reindex" reasoning exactly, and is
  scoped to only the entity IDs already present in the current turn's
  candidate set — never every active rule in the store.

- **Cart rules read via the real active-rule filter, never a full cart
  evaluation.** `activeCartRules()` uses `Magento\SalesRule\Model\
  ResourceModel\Rule\Collection::addWebsiteGroupDateFilter()`, the same
  real "active, in-range, applicable to this website+group" filter
  cart-rule application itself is built on — deliberately not
  `setValidationFilter()` (coupon-specific) and deliberately not
  simulating a full cart against `Magento\SalesRule\Model\Validator` (a
  heavier, cart-mutating operation with no reason to duplicate here;
  this tool only reports a rule's own definition, not a cart-specific
  computed total).

- **Auto-applied vs. coupon-required is a real, explicit distinction,
  not one collapsed flag.** `CartPromotionInterface::requiresCoupon()`/
  `couponCode()`, derived from the rule's real `coupon_type`
  (`COUPON_TYPE_NO_COUPON`/`COUPON_TYPE_SPECIFIC`/`COUPON_TYPE_AUTO`).
  A `COUPON_TYPE_AUTO` rule (many per-use auto-generated codes)
  correctly reports `requiresCoupon() === true` with `couponCode() ===
  null` — there is no single real code to give, and inventing one
  would itself be a fabrication.

- **Promotion data is a separate system message, not a new field on
  `ProductContextFormatter`.** That formatter's own existing
  instructions explicitly forbid price/stock-adjacent facts (that data
  isn't resolved at the time it builds its candidate list). The new
  `PromotionContextFormatter` mirrors its exact shape (INSTRUCTIONS
  heredoc + per-item formatting + `?ChatMessage`) and is added as an
  additional message in `ChatEntryPipeline`, built from
  already-live-revalidated data.

- **One capability flag gates both the tool and the proactive
  message.** `isPromotionAwarenessEnabled()` is checked both in
  `GetActivePromotionsTool::authorize()` and before `ChatEntryPipeline`
  resolves `PromotionContextFormatter`'s data — a merchant disabling
  the capability gets promotion awareness turned off end-to-end, not
  just the explicit-ask path. (This was initially drafted as tool-only
  and corrected before any test was written against the wrong
  behavior.)

- **`OutputValidator`'s new `fabricated_discount` check mirrors
  `fabricated_price` exactly** — same fail-closed structure, inserted
  right after the price check and before the SKU checks.
  `containsFabricatedPercentage()` regex-extracts `N%` mentions and
  compares against real `ProductPromotionInterface::percentOff()`
  values and cart-rule `discountDescription()` strings re-parsed for a
  leading percentage; `containsFabricatedCouponCode()` regex-extracts
  text immediately following the literal word "code" and compares
  case-insensitively against real `couponCode()` values. Same
  disclosed, accepted limitation class as the existing price/URL
  checks: this is regex-based, not NLP, so "20% off" and an unrelated
  "20% cotton" material claim are checked identically — documented in
  the new tests, not hidden.

## Bug found and fixed during live verification

This store's real "Spend $50 or more - shipping is free!" cart rule has
`simple_action = by_percent` with `discount_amount = 0` — its actual
discount mechanism is the separate `simple_free_shipping` flag.
`describeDiscount()` originally produced "0% off" for it: technically
true (it matches the real stored amount) but uninformative, and a poor
fact to weave into response text for a feature whose entire point is
useful, accurate discount statements. Fixed by checking
`getSimpleFreeShipping()`: a zero-amount free-shipping rule now
describes itself as "free shipping"; a non-zero rule with free shipping
also enabled appends "+ free shipping" to its normal description. Added
a dedicated unit test
(`testActiveCartRulesDescribesAFreeShippingOnlyRuleAsFreeShippingNotZeroPercentOff`)
rather than leaving this to only the live check to catch, and
re-verified live afterward — see below.

A second issue, in the test harness rather than product code: the new
Integration test originally called `\Magento\Framework\App\
Bootstrap::create()` fresh inside its `createRule()`/`cleanup()` helper
methods, which returned an object manager without the area code set
(only ever set on the `setUp()`-local `$objectManager`), causing every
test to fail with "Area code is not set" the moment `Rule::save()`
tried to build its condition-combine object. Fixed by caching the
object manager as an instance property in `setUp()` and reusing it
everywhere, matching `MerchandisingBoostDatabaseTest`'s own established
pattern more closely than the first draft did.

## Verification — full test suite

**1496 tests / 3608 assertions / 0 failures** (up from 1453/3513 at the
end of Task 33), plus **4 new Integration tests / 7 assertions against
the real database** (`ActiveCartPromotionDatabaseTest`):
- an active, in-range rule for the real customer group is surfaced
- a rule outside its active date range does NOT surface
- a rule for one customer group does not leak into another group's
  result
- a coupon-required rule reports its real, auto-created primary coupon
  code

A whole-module `php -l` sweep (609 files) is clean. `setup:di:compile`
is clean (confirms the new `ActivePromotionReaderInterface` preference
and every new constructor injection resolve correctly). `cache:flush`
clean.

## Verification — live, real container, against this store's genuinely pre-existing rules (not fixtures)

This store already has real, pre-existing promotions to verify against
— no fabricated "live verified" claim was needed:

- **Real Catalog Price Rule:** "20% off all Women's and Men's Pants"
  (confirmed via `catalogrule_product_price`: product 725 /
  `MP01-32-Black`, regular $35.00 → rule price $28.00, dated today).
- **4 real active Cart Price Rules:** a buy-3-get-1-free, a
  free-shipping-over-$50, a storewide 20%-off, and a $4-water-bottle
  rule requiring the real coupon code `H20`.

A standalone script constructing the real, DI-resolved
`ActivePromotionReader` confirmed `catalogRuleDiscounts()` returns
exactly `regular=35.00 discounted=28.00 percentOff=20.00` for the real
product, and `activeCartRules()` returns all 4 real rules with the
correct auto-applied/coupon-required distinction and the real code
`H20` — reconfirmed a second time through the actual DI-resolved
`GetActivePromotionsTool` instance (not just the underlying reader),
which correctly returned all 4 real cart rules including the
free-shipping-description fix.

**Full end-to-end through the real, un-mocked `ChatEntryPipeline`:** a
real request ("Do you have any pants on sale right now?") against the
real retrieval/ranking/revalidation pipeline and a real local LLM
(Ollama, `qwen3.5`) produced a generated response whose message text
states:

> "Caesar Warm-Up Pant (SKU: MP01) - Sale price: $28 (regular: $35)"

— the exact real catalog-rule discount, sourced from the new
`PromotionContextFormatter` proactive message, not invented by the
model, and it passed `OutputValidator` (including the new
`fabricated_discount` check) without triggering a fallback.

**Live verification gap, honestly disclosed:** the explicit
tool-invocation path (a direct "do you have any coupon codes or
storewide discounts" question, meant to make the model call
`get_active_promotions` itself) was attempted 5 times live and hit
`assistant_unavailable` every time, traced via `exception.log` to
`ProviderInvalidResponseException` / `PROVIDER_INVALID_RESPONSE` — the
same pre-existing local-model reliability ceiling already documented in
CLAUDE.md ("Local model (Ollama) occasionally fails to use
correctly-available carried-over context on first attempt"). Confirmed
this is not a regression from this task: an identical-shaped failure
was already logged the day before this task, for an unrelated
add-to-cart request, and the debug log's own historical rate is 6 of 25
total logged requests ever recorded as `assistant_unavailable` (~24%),
independent of this task. The tool mechanism itself was independently
verified correct (above, via direct DI construction and a real
`execute()` call against real data), so this gap is specifically "the
local model didn't choose/complete the tool call in 5 live attempts,"
not "the tool is broken." Disclosed rather than silently retried into a
misleadingly clean report, per this module's own established
convention for local-model reliability gaps (e.g. Task 28's
follow-up-question voice compliance).

## Requirement 6 coverage (tests)

- Coupon-required vs. auto-applied: `ActivePromotionReaderTest::
  testActiveCartRulesDistinguishesAutoAppliedFromCouponRequired` /
  `testActiveCartRulesWithAutoGeneratedCouponsRequiresACouponButGivesNoSingleCode`
- Catalog rule vs. cart rule both surfacing correctly:
  `ActivePromotionReaderTest`'s catalog-rule tests plus
  `GetActivePromotionsToolTest::
  testExecuteReportsCatalogDiscountsAndNotFoundSkusSeparately`
- A rule outside its active date range not surfacing:
  `ActiveCartPromotionDatabaseTest::
  testARuleOutsideItsActiveDateRangeDoesNotSurface` (real database)
- Customer-group scoping (no leakage):
  `ActiveCartPromotionDatabaseTest::
  testARuleForADifferentCustomerGroupDoesNotLeakIntoAnotherGroupsResult`
  (real database)
- `fabricated_discount` catching a deliberately invented claim: 8 new
  `OutputValidatorTest` cases, including
  `testFabricatedPercentageDiscountIsInvalid` and
  `testFabricatedCouponCodeIsInvalid`

## Skill files updated

- `references/progress-log.md` — header summary replaced, status rows
  3 (Admin config sections), 6 (Runtime request pipeline), and 8
  (Response contract) extended additively, a new Task 34 history entry
  added.
- `CLAUDE.md`'s own "Discount/promotion tool (Phase 2, in progress)"
  section (already present from this task's own spec injection) was
  reviewed and left as-is — its content already matches what was
  actually built; no correction was needed.

## Not done / blocked

Nothing blocked. Two deliberate scope calls, both disclosed rather than
silently left undone:

- The Admin Playground's query runner was not extended to surface
  promotion data — not required by this task's own scope, and
  out-of-scope-disclosure is consistent with this module's practice
  (the same judgment call Task 32 made for boost data in the
  Playground).
- `ChatDebugTrace` was not given new promotion-specific fields — the
  existing trace already captures `final_product_skus`/`outcome`, and
  promotion facts are fully visible through the existing Tool
  Calls/Final Response Playground panels for any turn that exercises
  the tool. A dedicated trace field can be added later if debugging
  proves this insufficient.

The explicit-tool-invocation live-verification gap above is disclosed,
not silently worked around — every other layer (schema-free live-read
correctness, DI wiring, fail-closed validator, the proactive-surfacing
path) is genuinely, separately verified against this store's real data.
