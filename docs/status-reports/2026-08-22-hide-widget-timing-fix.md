# STATUS REPORT — Widget-hide timing bug + the fallback-not-yet-tripped "doesn't survive a refresh" gap

Two real UX bugs found via live testing immediately after Task 46
shipped. Both diagnosed with real evidence before any code changed,
both fixed, both tested, both live-reconfirmed.

## Part A — the widget hides before the customer can read why

### Diagnosis

Read the real, current `chat-widget-core.js`/`chat-widget-luma.js`/
`chat-widget-hyva.js` source directly. Confirmed the exact bug: in
`chat-widget-luma.js`'s `submitMessage()`, `trackFailureAndMaybeHide()`
(Task 46's combined decide-and-hide function) ran **before**
`appendAssistantResponse(normalized)` in source order — the failure
message was never even in the DOM yet when `hideWidgetEntirely()`
(`root.style.display = 'none'`) fired. The Hyva layer had the identical
ordering bug.

Even reordering alone would not have been a complete fix: `hideWidgetEntirely()`
ran **synchronously**. Nothing forces a browser to paint a just-appended
DOM node before a synchronous style change immediately after it — the
task's own hint about this ("even correct ordering can still paint too
fast to actually see if both happen in the same frame") is accurate;
a real yield to the render step, not just correct call order, is what
was actually missing.

### Fix

Split the concern into two functions, in both presentation layers:

- **`shouldHideWidget(reasonCode)`** — pure decision, no DOM access,
  returns a boolean. Replaces Task 46's combined
  `trackFailureAndMaybeHide()`.
- **`scheduleHideIfNeeded(shouldHide)`** — calls a real
  `window.setTimeout(hideWidgetEntirely, HIDE_DELAY_MS)`, called only
  after the response has actually been rendered into the log
  (Luma)/`messages` array (Hyva).

`HIDE_DELAY_MS` (5000ms) is a new shared constant in
`chat-widget-core.js`. Both the hard-failure (`assistant_down`) and
soft-failure-threshold (3 consecutive `assistant_unavailable`/
`retrieval_unavailable`) trigger paths now go through the identical
decide → render → schedule sequence.

### Regression test

`Test/Unit/js/chatWidgetHideTiming.test.js` — genuinely dependency-free
(Node's built-in `node:test` runner + its `mock.timers` fake-timer API,
zero npm install, matching this module's own "vanilla JS, no framework"
philosophy). Loads the **real** `chat-widget-core.js`/
`chat-widget-luma.js` source via `vm.runInContext()` against a minimal
hand-built DOM/window stub — not a reimplementation of the widget's own
logic — and drives it through its real public entry points (the
captured `DOMContentLoaded` handler, the captured form `submit`
listener). Three tests, all passing:

```
$ node --test Test/Unit/js/chatWidgetHideTiming.test.js
ok 1 - hard failure (assistant_down): message renders before the widget
        hides, and only after a real delay
ok 2 - soft failures: a single assistant_unavailable does NOT hide the
        widget, but 3 consecutive ones do (after the message renders
        and a real delay)
ok 3 - a success in between resets the soft-failure counter, so 2 soft
        failures + 1 success + 2 more soft failures never hides the
        widget
```

Each proves, against the real sequence of DOM operations recorded in
chronological order: the message is appended before any hide;
`root.style.display` is still not `'none'` immediately after the
response resolves; advancing the fake clock by `HIDE_DELAY_MS - 1`ms
still doesn't hide it; advancing 1ms further does.

Hit a real bug in the **test harness itself** along the way, not the
widget: the fake `window` object had no `setTimeout`, so
`scheduleHideIfNeeded()`'s real call threw a `TypeError` — and the
widget's own `.then().catch()` chain silently caught it, producing a
"Sorry, something went wrong" bubble as if the network request itself
had failed. A useful reminder that a `.catch()` chained after a
`.then()` catches errors from the `.then()` callback's own body too,
not only the original promise's rejection — worth remembering when a
`.catch()`-only error message shows up somewhere unexpected.

### Verification — served bytes

Per Task 46's own discipline (a source-file check is not proof a real
browser received it), cleared the stale `pub/static`/
`var/view_preprocessed` copies and re-confirmed via real HTTPS
requests through the actual site URL:

```
chat-widget-core.js  (luma theme):  HTTP 200, contains HIDE_DELAY_MS etc.
chat-widget-luma.js  (luma theme):  HTTP 200, contains scheduleHideIfNeeded/shouldHideWidget
chat-widget-hyva.js  (luma theme):  HTTP 200, contains scheduleHideIfNeeded/shouldHideWidget
chat-widget-luma.js  (blank theme): HTTP 200, contains scheduleHideIfNeeded/shouldHideWidget
```

## Part B — hide doesn't survive a refresh reliably

### Diagnosis

Reproduced with real evidence, not assumptions, in three separate
steps:

**Step 1 — is it the render-gate logic itself, or cooldown expiring
too fast?** Tripped a real hard failure (invalid API key), then called
the real `ChatWidget::toHtml()` three times over 6 seconds with
**no** `cache:flush` in between:

```
[refresh #1, immediately after trip] widget html length: 0 (empty=true)
[refresh #2, +3s, no cache:flush]    widget html length: 0 (empty=true)
[refresh #3, +6s, no cache:flush]    widget html length: 0 (empty=true)
```

Stayed correctly hidden the whole time. This ruled out both the
render-gate logic and a too-fast cooldown as the cause of a genuine
customer-facing "doesn't survive a refresh" symptom.

**Step 2 — is `cache:flush` itself resetting it?** Ran
`bin/magento cache:flush` once, then checked again:

```
PRIMARY open after bin/magento cache:flush: false
widget html length: 10715 (empty=false)
```

Confirmed: `cache:flush` genuinely resets the circuit breaker
immediately, well inside its 60-second cooldown. `CacheCircuitBreaker`
stores its state through the generic `Magento\Framework\App\CacheInterface`
— the same Redis-backed pool `cache:flush` clears in full (unlike
`cache:clean`, which respects Magento's per-type cache tags).

This is a **real, reproducible mechanism**, but **not a customer-facing
bug**: an ordinary browser refresh never runs `bin/magento cache:flush`
— that's a CLI/admin operation. It IS, however, a real trap for
*diagnosing* one: this session (and, evidently, whoever surfaced this
bug report) routinely runs `cache:flush` between manual test steps for
unrelated reasons, which silently resets circuit-breaker state
mid-investigation. Documented as a diagnostic-process gotcha in
CLAUDE.md rather than "fixed" in code — there is no code bug here to
fix.

**Step 3 — the real bug.** With fallback enabled (pointed at a
genuinely unreachable host per this module's own documented
`host.docker.internal` unreachability note) and PRIMARY already
confirmed hard-down, a real chat request correctly attempted fallback,
which genuinely failed too (a real connection error) — but
**fallback's own circuit stayed closed**, since a single ordinary
transient failure doesn't reach `fallback/failure_threshold` (3) on
its own. `ChatWidget::isAssistantConfirmedDown()` only reads
FALLBACK's own circuit state in that branch, so the widget stayed
visible even though neither path was currently working:

```
=== Simulating: primary already confirmed hard-down from an earlier real failure ===
PRIMARY open: true
FALLBACK open: false

=== Real chat request: primary skipped (circuit open), fallback genuinely attempted (unreachable host) ===
reasonCode: assistant_down

=== After that one combined request: circuit state ===
FALLBACK open: false   <- before the fix
```

### Fix — deliberately narrow

The task explicitly warned against casually overriding
`ChatWidget`'s existing, deliberate "don't hide if fallback might
still genuinely work" logic — and that warning is correct.
Blanket-hiding whenever primary alone is hard-down, regardless of
fallback's actual outcome, would regress a real, already-tested,
legitimate case (Task 44): primary hard-down while fallback is
configured, enabled, and genuinely healthy means the assistant is
still fully answering real questions via fallback. Hiding the widget
in that case would be actively wrong, not merely overcautious.

Instead, `FallbackChatGenerationService::attemptFallback()` now
upgrades a fallback failure to hard specifically when primary's own
failure is confirmed hard — a new `primaryFailureWasHard()` helper
checks either this call's own fresh primary exception, or (via the new
`wasOpenedByHardFailure()`, added in Task 46) an earlier call's
already-open primary circuit. When true, `recordHardFailure()` (not
`recordFailure()`) is used for the fallback role, and the exception
thrown becomes `ProviderConfirmedDownException` (already hard, per
`HardFailureClassifier`) instead of the fallback's own raw exception —
keeping the customer-facing reason code, the fallback circuit, and
`ChatWidget`'s next render all consistent.

**Critically, this only fires when fallback has just been actually
attempted and actually failed.** A genuinely healthy, succeeding
fallback never reaches this code at all — `recordSuccess()` runs
instead, completely untouched by this change.

### Regression tests

`Test/Unit/Model/Chat/FallbackChatGenerationServiceTest.php`, two new
tests:

- `testFallbackFailureWhilePrimaryCircuitAlreadyHardOpenUpgradesToConfirmedDown`
  — the skip-path variant (primary's circuit already open from an
  earlier hard failure); fallback attempted, fails with an ordinary
  soft exception; asserts `ProviderConfirmedDownException` is thrown
  and `recordHardFailure()` (not `recordFailure()`) is called for the
  fallback role.
- `testFallbackFailureAfterAFreshHardPrimaryFailureInTheSameCallAlsoUpgradesToConfirmedDown`
  — the non-skip-path variant (primary attempted this call, fails hard
  via a real `ProviderRateLimitException` — deliberately not
  authentication, which is never fallback-eligible and would never
  reach this code at all); same assertions.

## A live-verification mistake, caught and corrected

The first live-verification attempt for Part B's fix used an
**invalid API key** (authentication) to force the primary failure —
and showed no effect (fallback's circuit stayed closed). Before
concluding the fix didn't work, re-checked the code: authentication is
deliberately never fallback-eligible (a pre-existing, unrelated safety
boundary — a bad primary key must never itself trigger a fallback
attempt), so `attemptFallback()` is never even reached for that
exception type at all. This was a test-design error, not a fix
failure — corrected by simulating "primary already confirmed hard-down"
directly (via `recordHardFailure()`) and making a real request with
fallback pointed at an unreachable host, which correctly exercises the
actual skip-path scenario a real customer refresh would hit. See the
"Live-verified end-to-end" section below for the corrected run.

## Files changed

- `view/frontend/web/js/chat-widget-core.js` — new `HIDE_DELAY_MS`
  constant
- `view/frontend/web/js/chat-widget-luma.js`,
  `view/frontend/web/js/chat-widget-hyva.js` — `hideWidgetEntirely()`/
  `shouldHideWidget()`/`scheduleHideIfNeeded()` replacing Task 46's
  combined `trackFailureAndMaybeHide()`
- `Model/Chat/FallbackChatGenerationService.php` — new
  `primaryFailureWasHard()` helper; the exception-upgrade branch in
  `attemptFallback()`'s catch block
- `Test/Unit/js/chatWidgetHideTiming.test.js` (new — 3 tests)
- `Test/Unit/Model/Chat/FallbackChatGenerationServiceTest.php` (+2
  tests)
- `CLAUDE.md` — new "Widget-hide timing and the fallback-not-yet-tripped
  gap (Task 47)" section; two new environment-realities entries
  (`cache:flush` resetting the circuit breaker; Node's built-in fake
  timers being available with no npm install)
- Corrected a "Task 45" mislabeling (should have read "Task 46") left
  in 4 doc comments across `HardFailureClassifier.php`,
  `ChatEntryPipelineTest.php`, and `FallbackChatGenerationServiceTest.php`
  from the previous task's own work — found incidentally while editing
  nearby code, fixed while here
- Cleared and regenerated `pub/static`/`var/view_preprocessed` for this
  module (a deployment action, not a source change)

## Verification — full test suite

**1745 PHP tests / 4333 assertions / 0 failures** (1669/4010 unit +
76/323 integration; up from 1743/4325), plus **3/3 new JS tests
passing** (Node's own test runner, separate from PHPUnit).
`setup:di:compile` clean. Whole-module `php -l` sweep clean.

## Verification — live, end-to-end

Part A: served-bytes check above. Part B (corrected, skip-path
variant — the realistic "customer refreshes after an earlier failure"
case):

```
Simulated: primary circuit already hard-open (recordHardFailure)
Real chat request: fallback enabled, pointed at an unreachable host

reasonCode: assistant_down
FALLBACK open after the request:            true
wasOpenedByHardFailure(FALLBACK):            true
ChatWidget::toHtml() immediately afterward:  empty (length 0)
```

All diagnostic config changes (API key, fallback enabled/timeout,
primary timeout) were restored to their original real values
afterward, and the circuit-breaker state cleared via
`redis-cli FLUSHALL`.

## Not done / blocked

Nothing for the backend or JS fixes — both diagnosed with real
evidence, fixed, tested (PHPUnit + the new Node fake-timer suite), and
live-reconfirmed. The rendered widget's actual paint timing through a
real authenticated browser session remains unconfirmed directly — same
CAPTCHA-gated, no-browser-automation-tool limitation as every other
frontend-UI task in this module. This task narrows that gap further
than before: the fake-timer test proves the REAL source's sequencing
and delay logic genuinely work as designed (not merely "looks correct
on reading"), and the served-bytes check proves a real browser receives
that exact code — what remains unconfirmed is only whether the
browser's own render pipeline visibly paints the message within the
observed window, which no tool in this session can check without an
actual browser.
