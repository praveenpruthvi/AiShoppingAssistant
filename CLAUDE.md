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
- This environment's cache backend is Redis (`app/etc/env.php`'s
  `cache/frontend/default/backend` is `Magento\Framework\Cache\Backend\Redis`,
  container `redis`), not the filesystem — `rm -rf var/cache/*` (or a
  crashed `bin/magento cache:flush`) does NOT clear it. Confirmed as a
  real, reproducible cause of a totally broken `bin/magento` bootstrap
  (every single CLI command failing with "Cannot instantiate interface"
  for a brand-new DI preference, even immediately after adding a
  correct `<preference>` for it) — `Magento\Framework\ObjectManager\
  Config\Config::extend()` hash-caches the merged DI preferences map,
  and that cache entry survives a filesystem-only cache clear. If a
  newly-added interface/preference mysteriously "isn't picked up" even
  after confirming the XML is correct and `var/cache`/`var/generation`/
  `generated/*` are all genuinely empty, flush Redis directly next:
  `docker exec magento-redis-1 redis-cli -n 0 FLUSHALL`. Don't assume
  the DI wiring itself is broken before ruling this out.
- **RESOLVED (2026-08-21):** `bin/magento setup:upgrade` used to
  reliably fail on `Magento\CatalogSampleData\Setup\Patch\Data\
  InstallCatalogSampleData` with "Rolled back transaction has not been
  completed correctly." Root-caused by bypassing `Executor::exec()`'s
  own catch-all (it swallows the real exception and only logs "Sample
  Data error: ..." to `system.log`, which is why the CLI's own error
  message was misleading) and calling the installer directly: the real
  underlying error was `SQLSTATE[23000]... Duplicate entry '1' for key
  'PRIMARY'` on `INSERT INTO catalog_product_entity` — the sample
  catalog (2,040 Luma products, 40 categories, 3,416 gallery images)
  had ALREADY been fully and successfully installed once, but
  `patch_list` was missing its one completion row for
  `InstallCatalogSampleData` specifically (every one of the other 18
  sample-data patches WAS correctly recorded), so every subsequent
  `setup:upgrade` tried to re-run the entire install and collided with
  its own already-inserted `entity_id=1`. Fixed by inserting the
  missing row directly (`INSERT INTO patch_list (patch_name) VALUES
  ('Magento\\CatalogSampleData\\Setup\\Patch\\Data\\
  InstallCatalogSampleData')` — a single backslash per separator; check
  `HEX(patch_name)` against an existing row's `5C` bytes if re-doing
  this by hand, since shell escaping easily doubles it). Confirmed
  fixed via two clean, back-to-back `setup:upgrade` runs. If this ever
  recurs (e.g., after a database restore that captures product data
  from mid-list without the corresponding `patch_list` row), re-run
  this same diagnosis before assuming new corruption — don't reach for
  the workaround below, which is for genuinely NEW patches in THIS
  module, not for re-fixing this specific historical gap.
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
- **RESOLVED (2026-08-21, see the environment-realities entry above):**
  the `Magento_CatalogSampleData` patch failure used to silently abort
  the REST of that `setup:upgrade` run's data-patch queue too,
  including every module ordered after it — confirmed at the time
  (Task 38) by two full `setup:upgrade` runs both stopping at the
  identical point with a brand-new data patch in this module never
  running either time. Now that the real root cause is fixed, new data
  patches in this module apply normally through a plain
  `setup:upgrade` — no more need for the object-manager
  construct-and-call-`apply()`-directly workaround Task 38/40 used
  while this was still broken. If a new Setup data patch still doesn't
  seem to have applied, check `patch_list` first regardless — don't
  assume the patch class itself is broken before ruling that out.

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

## Attribute indexing selection (admin-controlled, not automatic)
- Implemented in Task 38 (see references/progress-log.md) — kept here as
  the binding design constraints for maintaining it, not a pending spec.
- Attributes are NOT all indexed by default. Merchant explicitly
  selects which product attributes feed the AI assistant.
- TWO synchronized entry points, both writing to the same underlying
  is_indexed data — neither is a duplicate source of truth:
  1. A new "Indexed for AI Assistant" Yes/No column + mass action on
     Magento's native Stores > Attributes > Product grid (fine for
     one-off/new-attribute toggling).
  2. A dedicated bulk-select screen (checkbox/multiselect list of all
     attributes) in this module's own admin config section — this is
     the primary real-world entry point, since an existing install
     may need ~40-50 attributes selected at once and doing that
     one-by-one via the native grid is real friction. Saving here must
     update the same data the grid column reads, and vice versa —
     changing one must be immediately reflected in the other, not
     eventually-consistent or requiring a cache flush to see.
- A catalog can have 200+ attributes, most internal-only. Indexing
  everything wastes embedding space and dilutes retrieval signal —
  this is a deliberate, merchant-controlled allowlist, not a gap to
  "complete."
- Selected attributes feed BOTH the embedding/searchable text AND the
  structured data used by AttributeMatchSignal/ProductContextFormatter
  — a shopper asking "is this waterproof?" should get a grounded
  answer from a selected attribute, not just better search relevance.
- Toggling selection requires a full reindex (MAPPING_VERSION bump),
  same discipline as Task 31 — this is not incremental-sync-safe.
- Before changing anything, audit what attributes are already flowing
  into indexing today (this predates admin control) and default the
  new "is_indexed" table to match current real behavior — do not
  silently drop existing coverage merchants may already rely on.
- The audit found exactly ONE choke point already feeding both the
  embedding text and the structured AttributeMatchSignal/
  ProductContextFormatter path (`IndexingConfigInterface::
  searchableAttributeCodes()`) — `ConfigurationReader::readIndexing()`
  now sources that same method from `AttributeIndexingSelectionRepositoryInterface`
  instead of the old free-text field, so `SearchableAttributeValueResolver`/
  `ProductSnapshotProvider`/`ProductDocumentNormalizer` needed zero
  changes. If a future task needs to touch attribute selection, start
  at `ConfigurationReader::readIndexing()`, not further downstream.
- The old free-text `searchable_attribute_codes` system.xml field/
  config.xml default were REMOVED entirely, not kept alongside the new
  mechanism — this task's own wording ("replacing") was an explicit
  instruction to replace, and a dead, now-inert admin field would be
  actively misleading to a merchant.
- `ProductAttributePolicy` (the security denylist for codes like
  `cost`/`api_key`) is untouched and still independently re-applied —
  this feature's merchant-controlled allowlist is layered ON TOP of
  that existing security boundary, never a substitute for it. The
  bulk-select screen also filters denylisted codes out of what it even
  offers as an option, so a merchant is never shown something selecting
  it would silently do nothing for.
- `aavirbhava_ai_attribute_indexing_selection` has no store_id column
  (deliberate, matches the task's own explicit schema and Merchandising
  Boost's precedent) — a selection is catalog-wide, not per-store-view.
- Entry Point A (the native product-attribute grid) is a legacy
  `Backend\Block\Widget\Grid\Extended` block, not a Ui Component —
  confirmed by reading the real core class, not assumed. It's extended
  via a `<preference>` on the concrete
  `Magento\Catalog\Block\Adminhtml\Product\Attribute\Grid` class in
  `etc/adminhtml/di.xml` (not a layout `<referenceBlock>` — that block
  has no stable, addressable layout name to target). Don't "simplify"
  this to a layout override without re-confirming a stable block name
  actually exists to hook.

## OpenSearch index retention (Task 39)
- Implemented in Task 39 (see references/progress-log.md) — kept here as
  the binding design constraints for maintaining it, not a pending spec.
- FullProductReindexer builds a new physical index, switches the alias
  atomically, then must clean up old physical indices — it did NOT do
  this before Task 39 (19 real leftover indices for store 1 by the time
  this was fixed, going back to the module's earliest reindexes; the
  original bug report cited 17, which had grown to 19 by the time this
  task actually ran, from ordinary use in between).
- Cleanup (`OpenSearchProductDocumentWriter::pruneOldIndexes()`) runs
  once per store, right after that store's alias switch inside the same
  atomic `activateRun()`, using three new
  `AssistantSearchClientInterface` methods: `listIndices()` (enumerates
  physical indexes by wildcard pattern — there is no locally-remembered
  list of past runs' index names to consult, since the writer only ever
  tracks the CURRENT run's own indexes), `indexAliases()` (every alias
  currently pointing at one exact index, in either direction — used to
  confirm a candidate isn't still referenced by ANYTHING, not just this
  store's own canonical alias, before it's ever deleted), and
  `indexCreatedAt()` (OpenSearch's own native `creation_date` index
  setting, read fresh per candidate — never a custom `_meta` field,
  since that wouldn't exist on any of the real pre-existing leftover
  indices this fix also had to clean up retroactively).
- `INDEX_RETENTION_COUNT = 2` (a class constant, not an admin field —
  the task's own wording accepted either): total physical indexes kept
  per store including the one just activated, i.e. exactly one
  immediately-previous index survives as a rollback margin. Never
  delete down to just the live index — that removes any ability to
  roll back a bad activation.
- A pruning candidate must independently pass its own `_meta`
  assistant-ownership proof (the same `metaProvesAssistantOwnership()`
  check `abortRun()` already used) AND have zero aliases currently
  pointing at it — failing either check skips that candidate rather
  than deleting it. This is deliberately NOT the same as
  `abortRun()`'s stricter same-run-id check: retention pruning
  legitimately considers indexes from many DIFFERENT past runs, not
  just the run currently being aborted.
- Pruning is entirely best-effort and never fails the run: a
  `pruneOldIndexes()` failure (whether from a listing failure, an
  unverifiable candidate, or a failed delete) is logged via the
  standard `Psr\Log\LoggerInterface` and swallowed — the alias switch
  that already happened is the correctness-critical operation, and a
  storage-hygiene failure afterward must never look like or cause a
  failed reindex.
- Live-verified against the real 19 leftover indices for store 1: one
  real `indexer:reindex ai_product_rag` dropped the count from 19 to 2
  (the newly-activated index plus its immediate predecessor); a second
  real reindex immediately afterward confirmed the steady state holds
  — still exactly 2, with the correct oldest-of-the-remaining index
  pruned each time, not an ever-growing or ever-shrinking count.
  `index-coverage` still reported full 181/181 coverage after both.

## Per-provider cost config (dynamic, replaces Task 35's static fields)
- Implemented in Task 40 (see references/progress-log.md) — kept here as
  the binding design constraints for maintaining it, not a pending spec.
- REPLACED Task 35's static provider_cost system.xml field-pairs
  (openai/openai_compatible only) with a dynamic, provider-keyed admin
  screen — same pattern as Task 38's AttributeIndexingSelectionRepository
  precedent: one shared repository, admin selects a provider from a
  dropdown (sourced from the real provider registry, Model\Config\
  Source\Provider — same list both LLM dropdowns already use), sets
  price per 1k input/output tokens, saves; repeat per provider.
- CostCalculator reads pricing by provider identifier at call time —
  switching Primary/Fallback LLM never requires re-configuring cost,
  since pricing is keyed to the provider identifier, not the role.
- Existing real config from Task 35 (openai/openai_compatible) is
  migrated into the new table via a data patch, preserving whatever a
  merchant already configured — never silently reset to 0.
- New/unconfigured providers (including the 3 added in Task 40) default
  to 0.0 cost, matching the existing fail-safe convention — no
  hardcoded real-world pricing guesses, since a wrong guess actively
  undermines the cap's purpose. Admin notice/reminder should surface
  when a selected LLM provider has no cost configured, so this isn't
  silently invisible.
- Old static system.xml fields removed entirely once migrated (same
  "replace, don't leave dead" convention as Task 38).
- The "no cost configured" notice fires on VALUE (`pricePerThousand{Input,
  Output}Tokens() === 0.0` for the currently-selected provider), not on
  row-presence in `aavirbhava_ai_provider_cost` — a provider with an
  explicit, real, saved `0/0` row (e.g. openai/openai_compatible right
  after migration) is exactly as "still $0.00" as a provider with no row
  at all, and the task's own wording ("still 0.0") called for exactly
  that. Don't "optimize" this to an `isset()`/row-presence check — it
  would silently stop warning about a real, already-priced-at-zero
  provider.
- The admin screen's single add/edit form doubles as the edit UI for an
  already-configured provider via a plain `?provider=<identifier>` query
  param (no JS/AJAX) — the review grid's "Edit" link and the Save
  controller's own post-save redirect both use it, matching this
  module's established "hand-rolled, JS-framework-free admin page"
  convention. `Block\Adminhtml\ProviderCost\Index::getEditingProviderIdentifier()`
  only trusts a query-param value that's a REAL, currently-registered
  provider identifier (checked against `Model\Config\Source\Provider`'s
  own option list) — an unrecognized value in the query string is
  treated the same as none at all, not echoed back into the form.
- `Model\Config\Source\Provider` (the concrete admin option-source class
  reused here) is `final` and can't itself be mocked in a unit test —
  `Test/Unit/Block/Adminhtml/ProviderCost/IndexTest.php` constructs a
  REAL instance backed by mocked `LlmProviderRegistryInterface`/
  `ProviderLabelRegistryInterface`, the same "use the real deterministic
  collaborator instead of trying to mock a final class" precedent
  `ConfigurationReaderTest` already established for `ColorContrast`.

## Live Gemini verification (Task 42) — 3 real bugs found and fixed
- Task 37 built `GeminiProvider` to spec with no live API key to verify
  against, disclosed as such at the time. Task 42 got a real key and,
  in the process of actually driving a real multi-round tool-calling
  conversation through it, found and fixed THREE genuine, real bugs —
  none of these were guessable from documentation alone; each needed a
  real failing response to root-cause.
- **Bug 1 — shared HTTP transport bug, affects every non-local
  provider, not just Gemini:** `Magento\Framework\HTTP\Adapter\Curl`
  (LaminasClient's own default adapter) passes headers to
  `CURLOPT_HTTPHEADER` as a raw associative array instead of
  `"Key: Value"` strings, so curl silently drops/mangles every header
  — including `Content-Type` and any provider auth header. Ollama's
  local server tolerates a missing `Content-Type`; Google's real API
  does not (returned "Cannot bind query parameter" because it tried to
  parse the bodyless-looking request as a query string). This is a
  REAL, confirmed Magento core bug (`vendor/magento/framework/HTTP/
  Adapter/Curl.php`), not something to patch in vendor — fixed by
  forcing `ChatHttpTransport` and `ProviderHttpTransport` (the shared
  transports for ALL chat and embedding providers) to use Laminas's
  own, correctly-implemented `Laminas\Http\Client\Adapter\Curl`
  instead, via `setOptions(['adapter' => ...])`. This was latent for
  Anthropic and xAI too (never live-verified with a real key either)
  and for any real, non-local embedding provider — not Gemini-specific,
  even though Gemini's strict API is what exposed it.
- **Bug 2 — Gemini's schema dialect rejects `additionalProperties`:**
  confirmed via a real 400 ("Unknown name additionalProperties ...
  Cannot find field") for both tool parameter schemas and the
  structured-output response schema. Every tool in this module (and
  `LlmResponseSchema`) sets `additionalProperties: false` at every
  object level as a genuine, deliberate strict-mode convention other
  providers (OpenAI in particular) need — that must never change.
  `GeminiProvider` now recursively strips ONLY this one keyword from
  the COPY of the schema sent to Gemini (both
  `buildFunctionDeclaration()`'s tool parameters and
  `buildRequestBody()`'s `responseSchema`), never touching the tool's
  own canonical definition or any other provider's request.
- **Bug 3 — Gemini's "thinking" model family requires a
  `thoughtSignature` round trip on replayed tool calls:** confirmed via
  a real 400 ("Function call is missing a thought_signature ...")
  on the SECOND round of a real multi-round tool-calling conversation.
  Gemini returns a `thoughtSignature` as a sibling key of `functionCall`
  in its response; if a later request replays that same functionCall
  (normal conversation-history reconstruction) without echoing that
  exact value back as a sibling key in the same request part, Gemini
  rejects the whole request. Fixed by adding `ToolCall::$providerMetadata`
  (a generic, nullable, provider-opaque round-trip field — deliberately
  NOT named "thoughtSignature" in the shared DTO, since every other
  provider must keep ignoring it entirely) and threading it through:
  `GeminiProvider` captures it on parse, echoes it back on build;
  `DbConversationHistoryStore` persists/restores it too, since a real
  storefront conversation spans multiple HTTP requests, not just
  multiple rounds within one. Also discovered while fixing this: Gemini
  DOES include a real `id` on `functionCall` for this model family,
  correcting Task 37's original "Gemini gives no id" assumption — the
  real id is now used when present, with the synthesized
  `gemini-call-<index>` kept only as a fallback.
- **A real, hard environment constraint, not a bug:** the free-tier
  Gemini API key used for this verification has a `20 requests/day`
  quota for `gemini-3.6-flash` (`generate_content_free_tier_requests`).
  Root-causing 3 real bugs required many real calls and exhausted it —
  a genuinely complete, successful multi-round trace with a final
  structured response was NOT obtained in this session as a result
  (see this task's own status report for exactly how far real
  verification got, and what remains for a future session once quota
  resets). This does not diminish the 3 fixes above, each independently
  confirmed via its own real failing/passing request-response pair.
- The real, live-configured model name changes over time (Google
  deprecates old ones) — `gemini-2.5-flash` returned a real 404 telling
  this session to use `gemini-3.6-flash` instead. Don't assume a model
  name from an earlier task's docs/config is still valid; a live 404
  with a suggested replacement is Google's own real signal, not a bug.

## Assistant-unavailable widget hide (Task 44)
- Implemented in Task 44 (see references/progress-log.md) — kept here as
  the binding design constraints for maintaining it, not a pending spec.
- **The "REAL BUG (found live)" this section originally asserted here
  does NOT reproduce.** Task 44 live-tested "fallback disabled + primary
  fails" three separate, real ways against the actual current code — a
  raw `ChatEntryPipelineInterface::handle()` call with an invalid API
  key, the same call against a genuinely unreachable endpoint (a real
  connection-level failure, not just a clean 401), and the full real
  HTTP `Controller\Chat\Send` path for the unreachable-endpoint case —
  and every one of them correctly returned a real `SafeResponse`
  (`reason_code: assistant_unavailable`), never an uncaught exception,
  never silence. `FallbackChatGenerationService`'s own existing test
  (`testNoFallbackConfiguredPropagatesThePrimaryFailure`) already
  proved the propagation half of this; `ChatEntryPipeline`'s own
  existing test (`testGenuineProviderUnavailabilityIsNeverRetriedUnlikeAnInvalidResponse`)
  already proved the catch-and-convert half. If this symptom is ever
  reported again, re-verify live against the CURRENT code before
  assuming it's still broken — don't skip straight to a "fix" for a
  claim that didn't hold up under direct investigation last time either
  (see also Task 41/42's own precedent for a reported-but-unreproducible
  discrepancy). One genuinely new, permanent regression test was added
  anyway (`FallbackChatGenerationServiceTest::
  testConverseNeverSwallowsAPrimaryFailurePropagatedFromFallbackChatGenerationServiceWhenFallbackIsDisabled`)
  since it closed a real, previously-untested integration seam between
  `FallbackChatGenerationService` and the real, un-mocked
  `ToolCallingChatService` — genuinely new coverage, not proof of a bug.
- The new safeguard itself IS implemented as specified: `ChatWidget`'s
  render-gate (already had fail-closed `isAssistantEnabled()` and
  fail-open cost-cap checks) gains a third check,
  `isAssistantConfirmedDown()` — hides the widget when the assistant is
  confirmed genuinely down (primary provider's circuit open, with no
  fallback available/enabled or the fallback's own circuit also open).
- Reuses the existing `CircuitBreakerInterface` state
  `FallbackChatGenerationService` already maintains — no second,
  separate health-tracking mechanism. A single transient failed request
  never trips this, since `CircuitBreakerInterface::isOpen()` only
  turns true after `failureThreshold` real CONSECUTIVE failures
  accumulate (its own existing contract) — reading the circuit's
  aggregate state, never a single request's own outcome, is what makes
  "don't hide on one bad call" fall out naturally with no extra logic
  in `ChatWidget` itself.
- Primary's circuit alone being open is deliberately NOT enough to
  hide the widget: if a fallback provider is configured, enabled, and
  its OWN circuit is still closed, the assistant is genuinely still
  usable (a real chat request in that exact state gets a real AI
  response via fallback, not a SafeResponse) — hiding the widget there
  would be wrong, not merely overcautious.
- Fail-direction is deliberately the OPPOSITE of the cost-cap check
  right next to it: cost-cap fails OPEN on its own internal error (a
  tracking glitch must never block a working channel); this new check
  fails CLOSED on its own internal error (e.g., a circuit-breaker read
  failure) — matching `isAssistantEnabled()`'s own existing fail-closed
  precedent in the very same class, not cost-cap's. An assistant this
  safeguard cannot even confirm the health of is treated the same as
  one confirmed down, never silently assumed healthy.
- Live-verified together in one real forced-down state: 3 real
  consecutive primary failures (unreachable endpoint, fallback
  disabled) genuinely tripped the circuit breaker
  (`CircuitBreakerInterface::isOpen()` confirmed `true` via the real
  object manager); a 4th real chat request then completed in 0.4s
  (skipping retries entirely, confirming the breaker is actually being
  consulted) and still produced a real `SafeResponse`; the real
  `Block\Frontend\ChatWidget::toHtml()` — constructed through the real
  object manager, not mocked — returned a genuinely empty string in
  that exact same state.

## Hard vs. transient provider failures (Task 45)
- Implemented in Task 45 (see references/progress-log.md) — kept here as
  the binding design constraints for maintaining it, not a pending spec.
- User-reported real problem (with a screenshot): a rate-limited/down
  provider made the storefront widget repeat the exact same generic
  out-of-scope text ("I can help you search, compare...") for every
  message, indistinguishable from a genuine "that's out of scope for
  me" answer — confusing and frustrating, with no signal to the
  customer that the assistant was actually broken rather than just not
  understanding them.
- `HardFailureClassifierInterface`/`HardFailureClassifier` draws one
  narrow, deliberate line: `ProviderRateLimitException`/
  `ProviderAuthenticationException` (and their embedding-provider
  siblings, `EmbeddingRateLimitException`/`EmbeddingAuthenticationException`,
  used during retrieval's query-embedding step) are "hard" — an
  exhausted quota or an invalid/revoked key will fail identically on
  the very next request, so retrying cannot help. Every other
  `ProviderException` (timeout, transport, invalid response, generic
  unavailability) stays "transient" — a fresh request has a genuine
  chance of not hitting the same problem again. Do not casually widen
  this set — the whole point is that only genuinely unrecoverable,
  account-level failures skip retry and stop the chat; a widened set
  would stop the chat on failures a customer's very next message might
  have sailed through.
- On a hard failure, `FallbackChatGenerationService` (a) skips the
  local same-provider backoff-retry loop (`attemptPrimaryWithRetry`) —
  retrying a 429/401 three times in ~1.4s only burns quota/latency, it
  cannot change the outcome — and (b) forces the affected role's
  circuit open on this SINGLE occurrence (`recordFailure(..., 1, ...)`)
  rather than waiting for the configured `failure_threshold`'s usual
  multiple consecutive failures, so `ChatWidget`'s Task 44 hide
  safeguard reacts immediately rather than after several more customers
  each hit the identical guaranteed failure. Rate limit stays
  fallback-eligible (a different provider isn't subject to the same
  account's quota); authentication stays NOT fallback-eligible (a bad
  primary key must never itself trigger a fallback attempt — a
  pre-existing, deliberate safety boundary, unchanged) — but Task 45
  changed authentication to still call `recordFailure` (with threshold
  1) even though ineligible, since the circuit breaker is now ALSO
  `ChatWidget`'s only health signal and a bad key must be visible there
  even though it must never cause a fallback attempt.
- `ChatEntryPipeline` picks the customer-facing reason code/message
  from whether the TERMINAL exception (the one left after every retry
  and fallback attempt is exhausted) is hard or transient — captured
  across the tool-calling loop as `$terminalProviderException`, reset
  to null on any attempt that succeeds converse() so it only ever
  reflects the actual last failure, not a stale earlier one. Transient
  gets `REASON_ASSISTANT_UNAVAILABLE` + the new, admin-configurable
  "Assistant Temporarily Unavailable" message (guardrails config) — the
  customer can reasonably try again. Hard gets the new
  `REASON_ASSISTANT_DOWN` + the new "Assistant Down" message, applied
  identically at both short-circuit sites (the LLM tool-calling loop
  and the retrieval/embedding-failure catch) — the exact same reason
  code and message regardless of which backend produced the hard
  failure, since the customer-facing meaning ("stop, don't retry") is
  identical either way; only the debug log/trace still distinguishes
  which backend actually failed.
- Both new messages are genuinely admin-configurable
  (`guardrails/assistant_unavailable_message`,
  `guardrails/assistant_down_message`, alongside the pre-existing
  `out_of_scope_message`) — deliberately NOT reusing
  `outOfScopeMessage()` any more for either case, which is what
  produced the user-reported confusing behavior in the first place.
  `outOfScopeMessage()` itself is untouched and still used only for
  genuine scope decisions (assistant disabled, classified out-of-scope,
  output-validator rejection) — never for a provider failure of either
  kind.
- On the frontend, a `reason_code: "assistant_down"` response (exposed
  from `chat-widget-core.js` as the shared `REASON_ASSISTANT_DOWN`
  constant so both presentation layers reference the identical string)
  permanently disables the input/send controls for the rest of that
  visit in both the Luma (`chat-widget-luma.js`) and Hyva
  (`chat-widget-hyva.js` + `widget-hyva.phtml`'s `:disabled="loading ||
  stopped"` binding) layers — the widget itself stays open/closeable,
  only the ability to send another message is removed, with the
  placeholder text changed to say the conversation has ended. This is
  deliberately NOT persisted client-side (no sessionStorage entry) — a
  page reload re-evaluates `ChatWidget`'s own server-side render gate
  (Task 44), which by then hides the widget entirely once the same
  now-force-opened circuit-breaker state is visible there too; the
  in-page "stopped" state only covers the gap between the hard failure
  happening and the customer's next reload.
- **A real, separate bug was found live while verifying this feature**:
  Gemini's actual Generative Language API returns a genuine HTTP 400
  ("INVALID_ARGUMENT", body reason `API_KEY_INVALID`) for an invalid or
  revoked API key, confirmed via a direct `curl` against the real
  endpoint — not the 401/403 `HttpStatusMapper` otherwise expects for
  an authentication failure. Left alone, this meant a bad Gemini key
  was silently misclassified as `ProviderInvalidResponseException` (a
  retryable compliance problem), never even reaching
  `HardFailureClassifier` as the authentication failure it actually
  was — the "invalid key stops the chat" safeguard would have silently
  never worked for Gemini specifically. Fixed narrowly in
  `GeminiProvider::assertNotApiKeyFailure()`: only a 400 whose body
  contains the literal, documented `API_KEY_INVALID` string is
  reclassified to `ProviderAuthenticationException`; every other 400
  (a genuine malformed request/schema issue) is left for
  `HttpStatusMapper`'s normal, unchanged handling. If another
  provider's error-status mapping is ever reported wrong, verify the
  REAL response (a direct `curl`, not the docs) before assuming the
  documented status code is what the API actually returns — this is
  the second time in this module a provider's real behavior diverged
  from its own published spec (see Task 42's `additionalProperties`/
  `thoughtSignature` findings).
- Live-verified end-to-end with a real invalid Gemini key: one real
  `ChatEntryPipelineInterface::handle()` call returned
  `reason_code: assistant_down` with the new "Assistant Down" message
  (not the old generic out-of-scope text); the real
  `CircuitBreakerInterface::isOpen()` for PRIMARY flipped `true` after
  that SINGLE call (not three); a real `Block\Frontend\ChatWidget::
  toHtml()` immediately afterward, in that same forced-down state,
  returned a genuinely empty string.

## Token efficiency (2 separate mechanisms — do not conflate)
- Prompt caching (Anthropic cache_control breakpoints on system prompt
  + tool definitions; confirming OpenAI's automatic prefix caching
  actually triggers via a stable request prefix) is UNCONDITIONAL
  infrastructure — always enabled where the provider supports it, never
  gated behind the Token Optimization toggle below. It has no quality
  tradeoff: identical content, just billed differently when cached.
- "Token Optimization" admin toggle (Yes/No, default No) governs a
  SEPARATE, growing set of techniques that DO trade something away for
  lower cost — trimmed context fields now, potentially conversation
  history summarization later. Default No preserves today's full-
  context behavior; a merchant explicitly opts into cost-over-accuracy
  trimming, never the reverse.
- Do not add new trimming behavior to the codebase without gating it
  behind this toggle — an ungated trimming "optimization" silently
  changes accuracy for every merchant, which defeats the toggle's
  entire purpose.