# Aavirbhava_AiShoppingAssistant — Claude Code Instructions

## What this is
Composer-installable Magento 2 module (app/code/Aavirbhava/AiShoppingAssistant),
NOT a standalone app. RAG-based AI shopping assistant, provider-agnostic
LLM/embedding adapters behind interfaces. Local dev: docker-magento
(markshust/docker-magento).

## Non-negotiable architectural rules
- The LLM NEVER supplies price/URL/stock/SKU facts directly to the shopper —
  only sku+reason. All product facts come from live Magento revalidation
  (LiveRevalidationService) at response time, every time.
- Every response goes through OutputValidator (fabricated_sku/price/url/
  discount/malformed_response checks) before it reaches the user.
  Fail-closed: any violation invalidates the WHOLE response, falls back
  to safe non-AI response. Do not weaken this to "fix" a bug — find the
  real cause upstream.
- New product-fact-bearing features (ratings, promotions, etc.) must add
  their own OutputValidator check (e.g. fabricated_discount, added Task
  34) mirroring the existing pattern, not bypass it.
- Ranking signals implement RankingSignalInterface and are additive to the
  existing pipeline (currently: text relevance, vector similarity,
  attribute match, rating, merchandising boost, availability —
  AvailabilitySignal always runs last as the authoritative gate). Never
  replace/skip existing signals.
- Everything is provider-agnostic behind existing interfaces
  (ChatProviderInterface, EmbeddingProviderInterface). Don't hardcode
  provider-specific behavior outside the adapter classes.

## Environment realities (don't rediscover these)
- Local LLM is Ollama on host 127.0.0.1:11434 — NOT reachable via
  host.docker.internal from inside docker-magento containers on this
  Linux setup. Verify the reachable address per-task if networking is
  involved.
- Real Ollama chat completions: ~13-19s normal, up to ~50s under host
  memory pressure. This is a real ceiling, not a bug, unless proven
  otherwise via the debug log.
- Ollama's JSON Schema handling silently rejects PHP empty-array
  `properties: []` — must be `{}` (stdClass). Check this first for any
  "tool schema silently ignored" symptom.
- No Hyva theme is installed in this dev environment. Hyva-path code
  must be built to spec but flag clearly that it's unverified live —
  don't claim it's tested.
- Admin login has a CAPTCHA enforced in this environment — a scripted
  curl login (even with real credentials from env/magento.env) gets
  rejected with "Incorrect CAPTCHA," so authenticated-admin-UI curl
  verification (grid rendering, mass actions, form submits) isn't
  possible without a real browser. No browser-automation tool exists
  in this session either. Verify admin PHP/DI/schema/ORM correctness
  instead via setup:di:compile, direct DB checks, and a real
  Integration test against the actual AbstractModel/AbstractDb stack —
  and disclose the browser-UI gap rather than silently skipping it or
  attempting to disable the CAPTCHA to work around it.
- `bin/magento setup:upgrade` reliably fails on
  `Magento\CatalogSampleData\Setup\Patch\Data\InstallCatalogSampleData`
  ("Rolled back transaction has not been completed correctly") on every
  run in this environment, confirmed via the `patch_list` table to have
  never successfully applied — a pre-existing, unrelated
  `Magento_CatalogSampleData` issue, not something any task here caused.
  It does not block `setup:di:compile`, schema upgrades, or reindexing;
  don't treat it as a new regression.
- Real outbound email in this environment lands in a Mailcatcher
  instance (container hostname `mailcatcher`, SMTP port 1025, web/API on
  port 1080 — `msmtp` is configured as PHP's `sendmail_path`, relaying
  there) — `curl http://mailcatcher:1080/messages` from inside a
  container lists captured messages, and `/messages/<id>.html` fetches
  one's real rendered body. This makes genuine, real-transport email
  live-verification possible (used for Task 35's cost-cap alert) — don't
  assume email sending is unverifiable in this environment the way
  admin-UI-through-a-real-browser is (CAPTCHA-gated, no browser tool).

## Required workflow
- Diagnose from evidence, not guesses: use
  var/log/aavirbhava_ai_shopping_assistant_chat.log (per-request debug
  log: scope decision, retrieval query+candidates+scores, availability
  filter counts, price_constraint detection, carried_over_skus,
  final_product_skus) before proposing a fix.
- Update references/progress-log.md at the end of every task.
- Write a STATUS REPORT to a uniquely-named
  docs/status-reports/<date>-<slug>.md file at the end of every task —
  this is how results get reviewed, don't skip it or summarize only in
  chat output.
- Run the full test suite before reporting done; report the real
  pass/fail/assertion counts, not "tests pass."
- Additive-only changes to prompts/instructions unless a task
  explicitly asks for a rewrite — preserve existing instruction text
  verbatim when adding new constraints.

## Known open issues (check before assuming a new bug)
- [keep this list current — pull from references/progress-log.md;
  don't let CLAUDE.md and the log drift out of sync]
- Local model (Ollama) occasionally fails to use correctly-available
  carried-over context on first attempt — known reliability ceiling,
  not a retry-budget bug.
- FullProductReindexer's successful runs leave prior run-indices
  behind in OpenSearch rather than cleaning them up (flagged Task 16,
  still unaddressed).

## What NOT to do
- Don't add retries around fabricated_sku/fabricated_price rejections —
  this is deliberate (retrying a hallucination risks reinforcing it).
- Don't unify the Luma and Hyva widget renderers — kept
  dependency-free and separate on purpose.
- Don't sync reindexing to product save for anything that doesn't need
  freshness-critical accuracy (price/stock do; ratings/reviews
  probably don't — batch/async is fine).

## Ranking signal: product rating (Phase 2)
- Implemented in Task 31 (see references/progress-log.md) — kept here
  as the binding design constraints for maintaining it, not a pending
  spec.
- RatingSignal implements RankingSignalInterface, additive to
  the existing 4 (text relevance, vector similarity, attribute match,
  availability). Never replaces or reorders the existing signals' role.
- Score MUST be a Bayesian/weighted average (blend product's average
  rating with catalog-wide average, weighted by review count) — NOT
  raw average. A single 5-star review must not outrank a 500-review
  4.7-star product. This is the single most important correctness
  rule for this feature.
- Products with 0 reviews get the catalog-wide average as their prior
  (falls out naturally from the Bayesian formula — no separate
  special-case branch).
- Signal weight is admin-configurable (consistent with existing
  ranking signal config pattern), default weight kept modest — rating
  should nudge ordering, not override relevance. A well-matching
  low-rated product should still generally outrank a well-rated
  irrelevant product.
- Reindex trigger: batch/async refresh (e.g. nightly cron), NOT
  synced to individual review submission. Ratings are not
  freshness-critical like price/stock — don't add a new sync-on-save
  path for this.
- Rating data (average, review count) must be indexed into OpenSearch
  alongside existing product fields, sourced from Magento's native
  review system (Magento_Review module).

## Ranking signal: merchandising boost (Phase 2)
- Implemented in Task 32 (see references/progress-log.md) — kept here as
  the binding design constraints for maintaining it, not a pending spec.
- New signal: MerchandisingBoostSignal implements RankingSignalInterface,
  additive to the existing 5 (text relevance, vector similarity,
  attribute match, rating, availability). AvailabilitySignal remains
  the last, authoritative gate — boost never overrides it.
- Boost data is NOT indexed into OpenSearch. Unlike rating (uniform,
  low-priority, batch-refreshed), boosts are sparse, merchant-intent,
  time-scoped, and expected to take effect immediately on save. Signal
  reads live from MySQL (or a short-TTL/request-scoped cache), filtered
  to only the SKUs already present in the current candidate set
  (10-30 candidates typically — cheap at that scale). No reindex, no
  MAPPING_VERSION bump, no expiry cron: start/end dates are evaluated
  against real current time at read.
- Boost can only reorder candidates that already passed retrieval,
  live revalidation, and availability. It must NEVER inject a product
  into results that retrieval didn't already surface as relevant —
  same non-negotiable as every other product-fact-adjacent feature in
  this module. An irrelevant-but-boosted product must not outrank a
  genuinely relevant one for an unrelated query.
- Boost weight has more influence than RatingSignal's conservative 0.1
  default (this is explicit merchant intent, not a soft nudge) but
  stays additive — not a hard override of relevance, and never bypasses
  AvailabilitySignal.
- Admin save invalidates any boost cache immediately — this must be a
  genuinely live control, not eventually-consistent.
- Disclosure to the shopper (e.g. a "Sponsored" label) is an open,
  explicitly deferred decision — not yet made. Do not add or assume
  a disclosure field without it being in task scope.
- Scope for the first task: per-product boosting only, admin mass-action
  UI on the existing product grid. Category-level boosting is an
  explicitly deferred v2, not in scope yet.
- The product-grid mass action is a real, standard Magento full-page-form
  flow (Magento_Ui/js/grid/massactions.js's own default callback POSTs
  `selected[]` to the action's `url`), mirroring core's own "Update
  attributes" mass action exactly — not a bespoke JS modal, even though
  an earlier draft of this spec said "modal." Core itself has no
  modal-with-form mass-action precedent to safely mirror, and this
  module's own admin UI convention (Playground) is already a simple
  hand-rolled server-rendered page, not Ui Component-driven forms.
- Boost weight is capped at MerchandisingBoostRow::MAX_BOOST_WEIGHT
  (1.0) — both at save time (constructor validation) and defensively
  again inside MerchandisingBoostSignal itself. This is what makes the
  "boosted-but-irrelevant must not outrank genuinely relevant" guardrail
  actually hold: without a cap, an admin could type an arbitrarily large
  weight and defeat it. Don't remove the cap to satisfy a "let admins
  boost harder" request without re-deriving the guardrail math first.
- `aavirbhava_ai_merchandising_boost` has no store_id column
  (deliberate, matches the task's own explicit schema) — a boost is
  catalog-wide across every store view, not store-scoped like most of
  this module's other config.

## Admin Playground UI (visual-only redesign)
- Implemented in Task 33 (see references/progress-log.md) — kept here
  as the binding design constraints for maintaining it, not a pending
  spec. The collapsible panels use Magento's real native
  `mage/collapsible` widget (`data-mage-init='{"collapsible": {...}}'`
  on a `fieldset-wrapper admin__collapsible-block-wrapper`), the exact
  markup `Magento\Catalog\Block\Adminhtml\Product\Edit\Tab\ChildTab`'s
  own template uses for the product-edit page — not the older,
  Prototype.js-based `Fieldset.toggleCollapse()` pattern (system config
  groups use that one; it needs an AJAX round-trip to persist collapse
  state server-side, which this diagnostic page has no reason for).
  Requires zero custom JS for the accordion itself.
- Playground is a developer/debug tool, not customer-facing — optimize
  for scannability, not polish for its own sake.
- Pure CSS/markup pass: same sections, same data fields, same
  underlying PHP/data flow. Do NOT add filtering, re-run-without-retype,
  or any new backend capability in this task — that's explicitly
  deferred.
- Use Magento's own admin design system classes (admin__fieldset,
  data-grid, message message-success/error, existing admin collapsible
  panel pattern) rather than bespoke CSS — matches native admin look
  for free and avoids inventing new patterns.
- Client-side JS stays framework-free/vanilla, matching this module's
  existing "dependency-free" convention for the storefront widget
  (chat-widget-core.js) — no new JS dependency for the accordion or
  JSON highlighting.
- No live browser-automation verification tool is available in this
  session (same CAPTCHA/tooling gap as Task 32) — any claim that the
  UI "renders correctly" must be disclosed as inspected via code/markup
  review, not an actual browser screenshot, unless the user manually
  verifies and reports back.

## Discount/promotion tool (Phase 2)
- Implemented in Task 34 (see references/progress-log.md) — kept here as
  the binding design constraints for maintaining it, not a pending spec.
- New read-only tool `get_active_promotions`, following the same
  CommerceToolInterface pattern as existing commerce tools.
- Covers BOTH Magento discount types — do not implement only one:
  - Catalog Price Rules (Magento\CatalogRule) — automatic, applies to
    a product's displayed price directly, no coupon needed
  - Cart Price Rules (Magento\SalesRule) — may require a coupon code,
    applies at cart level, can be percent/fixed/free-shipping/etc.
- Same live-grounding discipline as price/stock: the LLM NEVER states
  a discount, percentage, or "on sale" claim from its own text. Every
  discount claim in a response must trace back to a real rule lookup
  at request time, scoped to the real product + real customer group +
  real current date. This is treated as a HARD requirement, not
  best-effort — a fabricated discount is a worse trust/compliance
  failure than a fabricated SKU (real money, real customer expectation
  at checkout).
- Rule data is read LIVE at request time, not indexed into OpenSearch
  and not denormalized — same reasoning as MerchandisingBoost (Task
  32): rules are merchant-controlled, date-scoped, and must reflect
  right-now reality, not a batch-refreshed snapshot. Scope any rule
  query to only the candidate SKUs already in play — never
  unconditionally load every active rule in the store.
- MUST add a new OutputValidator check: fabricated_discount, mirroring
  the existing fabricated_price pattern exactly (same fail-closed
  behavior: any violation invalidates the WHOLE response, falls back
  to the safe non-AI response — no partial-response patching).
- Discount facts get woven into response TEXT, not just a product-card
  badge — treated as a hard requirement per the original Phase 2 scope
  decision, not accepted debt like the earlier "not all reconciled
  products woven into text" tradeoff.
- Cart Price Rules that require a coupon code: the tool must
  distinguish "automatically applied" vs "requires a coupon code" as
  two explicit, separate facts (`CartPromotionInterface::
  requiresCoupon()`/`couponCode()`) — never collapsed into one
  "discount available" flag. A `COUPON_TYPE_AUTO` rule (many
  per-use auto-generated codes) correctly reports `requiresCoupon()
  === true` with `couponCode() === null` — there is no single real
  code to give, and inventing one would itself be a fabrication.
- Catalog Price Rules are read via `Magento\CatalogRule\Model\
  ResourceModel\Rule::getRulePrices()` — the same real, precomputed
  `catalogrule_product_price` table Magento's own indexer keeps fresh
  — deliberately NOT `RevalidatedProduct::specialPrice` (which already
  blends catalog rules in via Magento's own `FinalPrice`/
  `ProcessFrontFinalPriceObserver`), to correctly attribute a
  discount's source rather than conflating it with a plain
  `special_price` attribute.
- Cart Price Rules are read via `Magento\SalesRule\Model\
  ResourceModel\Rule\Collection::addWebsiteGroupDateFilter()` — the
  same real active/in-range/website/group filter cart-rule application
  itself is built on. This tool never evaluates a rule's condition
  tree against a real cart (that's `Magento\SalesRule\Model\
  Validator`'s job, a heavier cart-mutating operation) — it only
  reports a rule's own definition (coupon requirement, discount
  amount/type, dates).
- Promotion facts are surfaced to the LLM two ways: proactively, via a
  `PromotionContextFormatter` system message built from this turn's
  already-live-revalidated candidates whenever any of them has a real
  catalog-rule discount (kept as a separate message from
  `ProductContextFormatter`, since that formatter's own instructions
  already forbid price-adjacent facts); and on-demand, via the
  `get_active_promotions` tool call for a direct "any deals?" question.
  Both paths are gated by one capability flag,
  `capabilities.promotion_awareness_enabled` — disabling it turns off
  promotion awareness end-to-end, not just the explicit-ask path.

## Status reports — this is not optional
- The STATUS REPORT file is a deliverable, not a nice-to-have summary.
  A task that changes code but doesn't produce
  docs/status-reports/<date>-<slug>.md is NOT complete, regardless of
  what's said in chat. This was skipped once (Task 34) — do not repeat it.
- Write the file BEFORE announcing the task is done, not as an
  afterthought after the "complete" message.

## LLM cost cap
- Implemented in Task 35 (see references/progress-log.md) — kept here as
  the binding design constraints for maintaining it, not a pending spec.
- Admin config (System Configuration, new `cost_cap` group): cost cap
  amount (0 = disabled), cap period (daily/weekly/monthly, `Model\Config\
  Source\CapPeriod`), warning threshold % (default 80), "Allow Cost
  Override" (Yes/No), comma-separated notification email addresses. A
  separate new `provider_cost` group holds price-per-1k-input/output-
  tokens fields for each of the two currently-registered LLM providers
  (`openai`, `openai_compatible` — Local/Ollama), keyed off `Model\
  Provider\ProviderIdentifiers`, not a dynamic per-registry-entry
  repeater UI (no precedent for one existed in this module, and two
  known providers didn't justify inventing one). Local/self-hosted
  (`openai_compatible`) defaults to 0/0 — "local usage doesn't count
  toward spend" falls out of that default, not a special-case branch in
  the cap logic itself. Adding a future paid provider requires adding
  its own pair of pricing fields here too — an unconfigured/unknown
  provider identifier costs 0.0, a disclosed limitation, not a crash.
- `ChatResponse`/`TokenUsage` already carried real token usage parsed
  from the actual provider HTTP response (`AbstractChatProvider::
  parseUsage()`) before this task — nothing needed adding to
  `LlmProviderInterface` itself for this.
- Usage is accumulated in `aavirbhava_ai_cost_cap_usage`, keyed by a
  computed period-start string (`Model\CostCap\PeriodCalculator` —
  `Y-m-d` for daily, the Monday of the ISO week for weekly, `Y-m-01` for
  monthly); period rollover is just "a new key" falling out of this
  scheme, no explicit reset/cron needed. `period_key` is `varchar(20)`
  — real keys are always 10 chars, don't widen this without checking
  every caller still fits.
- Recording happens in exactly one seam: `Model\Chat\
  CostTrackingChatGenerationService` decorates `FallbackChatGenerationService`
  (by concrete class, avoiding a DI cycle the same way that class itself
  wraps the undecorated `ChatGenerationService`), swapped in as the real
  `ChatGenerationServiceInterface` preference — this transparently covers
  every real provider call in the module (the main chat pipeline's
  tool-call rounds AND the Admin Playground's query runner) with zero
  changes to either caller. Recording only happens after a call actually
  returns a `ChatResponse` — a thrown exception means nothing was spent,
  nothing is recorded.
- Atomic increments use `insertOnDuplicate()` + `Zend_Db_Expr` arithmetic
  (`Model\CostCap\DbCostUsageTracker`, mirroring `DbIncrementalWorkLedger`/
  `DbRebuildFence`'s own ResourceConnection-direct style) — no read-then-
  write race window. Threshold-notification dedup is a single compare-
  and-swap `UPDATE ... WHERE notified_threshold_rank < :rank`, with ranks
  ordered `NONE(0) < WARNING(1) < CAP(2)` so a single large usage jump
  that crosses both at once correctly fires both notifications in
  sequence, each still exactly once.
- Enforcement is a pure read at chat-widget render time
  (`Model\CostCap\CostCapEnforcer`, consulted from `ChatWidget::_toHtml()`)
  — never a client-side JS fetch, matching this module's existing
  live-read pattern (MerchandisingBoost/ActivePromotionReader) for "must
  reflect right-now state." **This check fails OPEN** (any error →
  not blocking) — the deliberate opposite fail-safe direction from
  `ChatWidget::isAssistantEnabled()`'s own fail-closed check right next
  to it; don't "fix" cap-check error handling by copying that method's
  behavior.
- Cap reached + override=No → the widget renders '' (server-side, same
  mechanism `isAssistantEnabled()` already uses, not CSS-hidden). Cap
  reached + override=Yes → renders normally, but the cap-threshold
  notification still fires — override affects serving, never the alert.
- Email notifications are claim-before-send: `claimThresholdNotification()`'s
  compare-and-swap persists BEFORE `CostCapNotifierInterface::notify()`
  is even called. This means a transient email-transport failure after a
  successful claim permanently forfeits that one threshold's notification
  for that period (no retry) — a deliberate tradeoff favoring "never
  double-send under concurrent requests" over "never miss a send," not
  an oversight. `Model\CostCap\EmailCostCapNotifier` uses Magento's
  native `TransportBuilder`, the first email this module has ever sent.
  **A real bug live-caught via an actual captured email (Mailcatcher)**:
  a `Phrase` object (from `__()`) passed directly into `setTemplateVars()`
  renders as empty by the `{{var}}` template directive, despite `Phrase`
  itself implementing `__toString()` — every translated var must be
  explicitly `(string)`-cast before reaching `setTemplateVars()`. Don't
  reintroduce a raw `Phrase` into that array without re-verifying against
  a real captured email, not just a unit test (the unit test alone would
  not have caught this — it only asserts what `setTemplateVars()` receives,
  not how the real template filter renders it).