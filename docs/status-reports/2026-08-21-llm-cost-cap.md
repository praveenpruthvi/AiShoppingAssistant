# STATUS REPORT — LLM usage cost cap: admin controls, enforcement, email alerting

Added an admin-configurable spend cap on real LLM usage, enforced
server-side at the point the storefront chat widget renders. Real
per-provider token usage (already parsed from actual provider HTTP
responses — nothing needed adding there) × configured per-1k-token
pricing accumulates atomically into a new period-keyed database table,
recorded from exactly one seam — a new decorator wrapping the existing
fallback chat service — so every real provider call across the module
is covered with no changes to any caller. The widget fails open on any
tracking error and fails closed only on cap-reached-with-no-override;
threshold-crossing emails (warning % and 100%, each once per period)
use a compare-and-swap claim before sending, via Magento's native mail
system. Live-verified end-to-end in the real container, including two
real emails captured by this environment's Mailcatcher instance — which
is how a real bug (a `Phrase` object rendering as empty text in the
actual sent email) was found and fixed.

## Files created/changed

**New — config:**
- `Api/Config/{CostCapConfigInterface,ProviderCostConfigInterface}.php`
- `Model/Config/{CostCapConfig,ProviderCostConfig}.php`
- `Model/Config/Source/CapPeriod.php`

**New — cost-cap domain:**
- `Api/CostCap/{CostUsageSnapshotInterface,CostUsageTrackerInterface,
  CostCapNotifierInterface,CostCapCheckerInterface}.php`
- `Model/CostCap/{CostUsageSnapshot,DbCostUsageTracker,PeriodCalculator,
  CostCalculator,CostCapThreshold,CostCapEnforcer,CostUsageRecorder,
  EmailCostCapNotifier}.php`
- `Model/CostCap/Exception/CostCapException.php`

**New — recording seam and email:**
- `Model/Chat/CostTrackingChatGenerationService.php`
- `etc/email_templates.xml`
- `view/adminhtml/email/cost_cap_alert.html`

**New — tests:**
- `Test/Unit/Model/Config/{CostCapConfigTest,ProviderCostConfigTest}.php`
- `Test/Unit/Model/CostCap/{PeriodCalculatorTest,CostCalculatorTest,
  CostUsageSnapshotTest,CostCapEnforcerTest,CostUsageRecorderTest,
  EmailCostCapNotifierTest}.php`
- `Test/Unit/Model/Chat/CostTrackingChatGenerationServiceTest.php`
- `Test/Integration/Model/CostCap/DbCostUsageTrackerDatabaseTest.php`
  (real database)

**Modified:**
- `Model/Config/Path.php` — 9 new constants
- `Api/Config/ConfigurationReaderInterface.php` / `Model/Config/
  ConfigurationReader.php` — `readCostCap()`/`readProviderCost()`
- `etc/adminhtml/system.xml` — 2 new groups, `cost_cap`/`provider_cost`
- `etc/config.xml` — defaults for both new groups
- `etc/db_schema.xml` — new `aavirbhava_ai_cost_cap_usage` table
- `etc/di.xml` — `ChatGenerationServiceInterface` preference swapped to
  the new decorator; 3 new preferences (tracker/checker/notifier)
- `Block/Frontend/ChatWidget.php` — new render-gate
- `Test/Unit/Model/Config/ConfigurationReaderTest.php`, `Test/Unit/Block/
  Frontend/ChatWidgetTest.php` — extended

No existing ranking signal, existing tool, or existing OutputValidator
check was touched — this task was additive-only, as required.

## Key decisions

- **Recording lives in exactly one seam, not scattered across
  callers.** `Model/Chat/CostTrackingChatGenerationService` decorates
  the concrete `FallbackChatGenerationService` class — the same
  DI-cycle-avoiding technique that class itself uses to wrap the
  undecorated `ChatGenerationService` — and is swapped in as the real
  `ChatGenerationServiceInterface` preference. Every real provider call
  in the module (the main pipeline's tool-call rounds via
  `ToolCallingChatService`, and the Admin Playground's own query runner)
  already goes through this one interface, so usage tracking reaches
  both with zero changes to either caller. Recording only happens after
  `chat()` actually returns a response; a thrown exception means
  nothing was spent, so nothing is recorded.

- **Real token usage needed no new plumbing.** `AbstractChatProvider::
  parseUsage()` was already parsing real `prompt_tokens`/
  `completion_tokens` from the actual provider HTTP response into
  `TokenUsage`/`ChatResponse.usage` before this task — confirmed by
  reading the code first, per the task's own "confirm whether... already
  surfaces" instruction. `LlmProviderInterface` needed no changes.

- **Atomic increments via `insertOnDuplicate`/`Zend_Db_Expr`, no
  read-then-write.** `DbCostUsageTracker` mirrors `DbIncrementalWorkLedger`/
  `DbRebuildFence`'s own `ResourceConnection`-direct style, simplified
  since nothing here needs lease/generation/claim machinery — only an
  increment and a one-time notification claim (a single compare-and-swap
  `UPDATE`). Threshold ranks (`NONE`/`WARNING`/`CAP` = 0/1/2) are
  monotonically increasing specifically so a single large usage jump
  that crosses both the warning threshold and the cap in one call
  correctly claims and fires both notifications in sequence, each still
  exactly once.

- **Period rollover is a keying scheme, not a reset step.**
  `PeriodCalculator::periodKey()` computes a period-start string
  (`Y-m-d` daily, the Monday of the ISO week for weekly, `Y-m-01`
  monthly) from real current time; a new period is just a different
  primary-key value, so "usage resets at a new period" falls out of the
  table's own keying rather than needing an explicit cron/reset job.

- **The render-time cap check fails OPEN, deliberately the opposite
  direction from its neighbor.** `ChatWidget::_toHtml()` already had one
  fail-closed check (`isAssistantEnabled()`). The new
  `costCapChecker->isBlocking()` check is fail-OPEN by design
  (`CostCapEnforcer` catches every `Throwable`, including store
  resolution, and resolves to "not blocking") — a broken cost tracker
  must never silently take down a working, revenue-relevant customer
  channel, per the task's own explicit instruction. Both checks live
  side by side in the same method, each failing in its own correct
  direction.

- **Per-provider pricing is 2 static field-pairs, not a dynamic
  per-registry-entry UI.** This module's existing provider-config
  convention (`llm`/`fallback` groups) is one flat field set for
  whichever single provider is currently selected, not one row per
  registered provider, and no repeater-style admin UI precedent exists
  anywhere in this module. With only 2 providers actually registered
  today (`openai`, `openai_compatible`), a static `provider_cost` group
  with one price-pair per known identifier is simpler and more
  Magento-idiomatic than inventing a new dynamic pattern for 2 rows. A
  provider identifier with no configured pricing (including a future,
  not-yet-wired-up one) costs 0.0 — disclosed as a real limitation, not
  a silent undercount hidden from the merchant.

## Bugs found and fixed via live verification

- **A real `Phrase`-rendering bug, caught only by inspecting an actual
  captured email, not by the unit test suite.** A `Phrase` object (from
  `__()`) passed directly as a template var rendered as completely empty
  text in the real sent email — `threshold_label`/`override_status` were
  blank, and the subject line (which embeds `threshold_label`) was
  truncated to just "AI Shopping Assistant:" — despite
  `Magento\Framework\Phrase` implementing `__toString()`. Fixed by
  explicitly `(string)`-casting every translated value before it reaches
  `setTemplateVars()`. Re-verified with a second real request: both
  emails (warning and cap) rendered every field correctly, subject
  included. Added a matching regression assertion in
  `EmailCostCapNotifierTest` and a code comment warning against
  reintroducing a raw `Phrase` here without re-verifying against a real
  captured email — the unit test alone asserts what reaches
  `setTemplateVars()`, not how the real template filter renders it, so
  it would not have caught this on its own.

- **A test-data bug (not a product bug), found while writing the
  Integration test.** The test's first draft used long, descriptive
  period keys (e.g. `ai-assistant-test-accumulation-test`, 36
  characters) against the real `period_key varchar(20)` column — this
  environment's MySQL isn't running in strict SQL mode, so the INSERT
  silently truncated the key to 20 characters instead of erroring, and
  every subsequent read by the full, untruncated key then matched
  nothing. Fixed by using short keys matching the column's real intended
  width (production keys are always the 10-character `Y-m-d` form)
  rather than widening the column.

## Verification — full test suite

**1544 tests / 3709 assertions / 0 failures** (up from 1496/3608 at the
end of Task 34), plus **6 new Integration tests / 14 assertions against
the real database** (`DbCostUsageTrackerDatabaseTest`):
- cost accumulates correctly across multiple real calls
- an untouched period reads as zero/non-existent rather than erroring
- two period keys accumulate independently
- a threshold claim succeeds once, then fails for a repeat claim in the
  same period
- a claim correctly escalates from warning to cap
- a claim never allows a downgrade back to a lower threshold

A whole-module `php -l` sweep (638 files) is clean. `setup:upgrade`
created the new table (confirmed via `DESCRIBE
aavirbhava_ai_cost_cap_usage`); the pre-existing, unrelated
`Magento_CatalogSampleData` patch failure recurred identically, as
expected, and does not block anything this task needed.

`setup:di:compile`: a first run produced 53 errors in unrelated,
pre-existing test files (`SearchStoreContentToolTest`/
`ProductContentSearcherTest`), all `Class or interface
"Magento\Cms\Model\ResourceModel\Page\CollectionFactory" does not
exist` — confirmed via `class_exists()` and a source search that
`Model/Tool/CmsPageContentSearcher.php` genuinely depends on this
factory and always has, so this was compile flakiness from that one run
(a generated factory that should exist simply didn't), not anything
this task's own DI wiring caused. A clean re-run of `setup:di:compile`
alone resolved it, and the full suite passed cleanly afterward with no
further compile issues.

## Verification — live, real container, across genuinely separate requests

Two separate real chat requests through the actual, un-mocked
`ChatEntryPipeline` (real retrieval/ranking/revalidation, a real local
LLM) against a temporarily-configured non-zero local-provider price
accumulated real cost exactly matching hand-computed expected values
from the real token counts returned:

- Call 1: 9,697 input / 1,316 output tokens → `(9697/1000×0.01) +
  (1316/1000×0.02) = 0.12329` — matched the real database row exactly.
- Call 2 (accumulated): 16,192 input / 2,042 output tokens →
  `(16192/1000×0.01) + (2042/1000×0.02) = 0.20276` — matched exactly.

With a real cap configured below the accumulated cost:
`CostCapCheckerInterface::isBlocking()`, resolved via the real DI
container in a separate PHP process, returned `true` with override=No
and `false` against the same real data once override was flipped to
Yes — live-proving the full override matrix, not just its unit-tested
mock version.

A third real request (with the cap now active, warning threshold 50%)
correctly jumped `notified_threshold_rank` from 0 straight to 2 (cap)
in the real database in one step, and — per the bug above — 2 real
emails (warning, then cap) were captured by this environment's real
Mailcatcher instance (`http://mailcatcher:1080` — this container's
`sendmail_path` is configured to `msmtp`, relaying there), confirming
genuine end-to-end delivery through Magento's real mail transport, not
just that `sendMessage()` was called without throwing. After the fix, a
follow-up real request confirmed both emails' subject and every body
field rendered correctly.

All temporary config changes (cap amount, override, provider pricing,
notification email, warning threshold) and all test data (the real
`aavirbhava_ai_cost_cap_usage` rows created during this verification)
were reverted/cleared afterward — confirmed via `core_config_data` and
a row count of 0 in the usage table.

## Requirement 7 coverage (tests)

- Cost accumulation across multiple calls: `DbCostUsageTrackerDatabaseTest::
  testCostAccumulatesAcrossMultipleRealCalls` (real database) + live
  verification above
- Period rollover: `PeriodCalculatorTest` (daily/weekly/monthly boundary
  crossings) + `DbCostUsageTrackerDatabaseTest::
  testDifferentPeriodKeysAccumulateIndependently`
- Cap-reached + override=No suppresses widget rendering:
  `ChatWidgetTest::testToHtmlIsEmptyWhenTheCostCapCheckerReportsBlocking`,
  `CostCapEnforcerTest::testBlocksWhenCapIsReachedAndOverrideIsNotAllowed`
- Cap-reached + override=Yes keeps rendering AND still sends the email:
  `CostCapEnforcerTest::testDoesNotBlockWhenCapIsReachedButOverrideIsAllowed`,
  `CostUsageRecorderTest::testStillNotifiesOnCapReachedEvenWhenOverrideIsAllowed`
  + live verification above
- Warning threshold email fires once: `CostUsageRecorderTest::
  testSendsTheWarningNotificationOnlyOnceAcrossMultipleCallsInTheSamePeriod`,
  `DbCostUsageTrackerDatabaseTest::
  testClaimingAThresholdSucceedsOnceThenFailsForTheSamePeriod`
- Tracking-failure fail-open: `CostCapEnforcerTest::
  testFailsOpenWhenConfigurationReadingThrows`/
  `testFailsOpenWhenUsageTrackerReadingThrows`/
  `testFailsOpenWhenStoreResolutionThrows`,
  `CostUsageRecorderTest::testTrackingFailureIsSwallowedNotPropagated`/
  `testNotificationFailureIsSwallowedNotPropagated`

## Skill files updated

- `references/progress-log.md` — header summary replaced, status rows 3
  (Admin config sections), 7 (Fallback chain), and 12 (Storefront chat
  widget) extended additively, a new Task 35 history entry added.
- `CLAUDE.md` — the "LLM cost cap (new feature)" section (present from
  this task's own spec injection) rewritten to "LLM cost cap" marked
  implemented, with real implementation-decision bullets (the decorator
  seam, the atomic-increment/CAS mechanics, the fail-open-vs-fail-closed
  distinction, the claim-before-send tradeoff, the Phrase-rendering bug)
  replacing the original spec bullets; a new "Environment realities"
  entry documents the real Mailcatcher instance for future tasks that
  need to live-verify email.

## Not done / blocked

Nothing blocked. Two disclosed, deliberate scope boundaries:

1. A transient email-transport failure after a successful threshold
   claim permanently forfeits that one notification for that period
   (claim-before-send, chosen specifically to prevent duplicate emails
   under concurrent requests — the opposite tradeoff would risk
   spamming on retries instead).
2. An unconfigured/future provider identifier's cost defaults to 0.0
   rather than being impossible to under-report — adding a new paid
   provider to the registry requires also adding its pricing fields to
   the `provider_cost` system.xml group, not automatic.
