# STATUS REPORT — Fix Task 45's "doesn't work live" report, a real alternating-message bug, and hide the widget entirely on confirmed/repeated failure

Two real bugs diagnosed and fixed (Part A), and a new client-side
safeguard built on top of the fix (Part B).

## Part A.1 — "Task 45's disable-input JS doesn't actually work live"

### What was checked

Re-traced the exact candidate causes the task named, from scratch,
against the current source:

- **The `stopped` flag reset on a subsequent render?** No — `stopped`
  was a plain closure variable in `chat-widget-luma.js`'s `init(root)`
  (Luma) / a reactive Alpine property (Hyva), set once per hard
  failure and never reset by anything else in the file.
- **The send handler not checking the flag?** No — `submitMessage()`/
  `send()` both guarded on `stopped` at the top, and `setLoading()`
  additionally forced `input.disabled`/`sendButton.disabled` to `true`
  whenever `stopped` was true.
- **Event listeners not respecting the disabled state?** No — a
  disabled `<input>`/`<button type="submit">` genuinely cannot dispatch
  `input`/`click`/form-`submit` events in a real browser; nothing in
  this module's JS second-guesses that.
- **A state/scope bug?** No — `stopped`, `loading`, and the DOM
  references were all captured in the same closure/Alpine instance;
  no double-initialization or stale-reference pattern was found.

Every one of Task 45's actual code paths was correct.

### The real root cause

`view/frontend/web/js/chat-widget-luma.js` (and `-hyva.js`,
`-core.js`) are static frontend assets. Magento materializes a
compiled copy the first time a static asset is requested and nginx's
`location /static/` block then serves that materialized file
**directly**, without ever re-invoking PHP — this happens regardless
of `developer` vs `production` deploy mode; developer mode's
"regenerate on request" only helps the very first time a file is
requested, never on an edit to an already-materialized one.

Confirmed directly:

```
$ grep -c "stopped\|stopChat" pub/static/frontend/Magento/luma/en_US/Aavirbhava_AiShoppingAssistant/js/chat-widget-luma.js
0
$ ls -la <source> <deployed>
-rw-rw-r-- ... Aug 22 00:36 .../view/frontend/web/js/chat-widget-luma.js   (Task 45's edit)
-rw-rw-r-- ... Aug 21 21:17 pub/static/.../chat-widget-luma.js             (pre-Task-45)
```

`var/view_preprocessed/pub/static/app/code/Aavirbhava/...` held an
equally stale intermediate copy. A real browser was therefore never
served Task 45's fix at all — the JS logic was correct and untested
live only because it was never actually delivered, not because it was
broken.

### Fix

Deleted both stale copies and let Magento regenerate them, then
confirmed via a **real HTTPS request through the actual site URL**
(not just a file check) that the served bytes now contain the current
code:

```
$ rm -rf pub/static/frontend/Magento/{luma,blank}/en_US/Aavirbhava_AiShoppingAssistant \
         var/view_preprocessed/pub/static/app/code/Aavirbhava
$ bin/magento cache:flush
$ curl -sk https://magento.test/static/frontend/Magento/luma/en_US/Aavirbhava_AiShoppingAssistant/js/chat-widget-luma.js \
  | grep -c "hideWidgetEntirely\|trackFailureAndMaybeHide"
5
```

Confirmed for `chat-widget-{core,luma,hyva}.js` in both the `luma` and
`blank` themes — all now serve current code.

This is now documented as a durable environment lesson in CLAUDE.md's
"Environment realities" section: **after editing any file under
`view/*/web/`, confirm the SERVED bytes changed via a real request
through the site URL — a correct source file and a passing code
review are not proof a real browser received it.**

## Part A.2 — the alternating hard/soft message pattern

### What was checked

Ran 5 real consecutive `ChatEntryPipelineInterface::handle()` calls
against a genuinely, persistently invalid Gemini API key, watching
both the reported `reason_code` and the real circuit-breaker state
after each call:

```
call 1: reasonCode=assistant_down        primaryOpen=true
call 2: reasonCode=assistant_unavailable primaryOpen=true
call 3: reasonCode=assistant_unavailable primaryOpen=true
call 4: reasonCode=assistant_unavailable primaryOpen=true
call 5: reasonCode=assistant_unavailable primaryOpen=true
```

### Root cause

Call 1 genuinely attempted the primary provider (circuit was closed),
got a real `ProviderAuthenticationException`, and correctly classified
as hard. Calls 2-5 all found the circuit **already open** and skipped
the primary entirely (`FallbackChatGenerationService::chat()`'s own
`if (!$this->circuitBreaker->isOpen(...))` guard) — `$primaryException`
stayed `null` for every one of them. `attemptFallback()`'s "nothing
left to try" branch then synthesized a **generic**
`ProviderUnavailableException` for each of those calls
(`$primaryException ?? new ProviderUnavailableException(...)`), and
`HardFailureClassifier` does not treat that as hard — silently
downgrading the customer-facing message from "the assistant is down"
back to "just try again" for every request made during the cooldown,
even though the underlying cause (the same invalid key) had not
changed at all. This is a genuine bug, not circuit-breaker cooldown
behavior working as designed.

### Fix

`CircuitBreakerInterface` gained two additive methods:

- `recordHardFailure(int $storeId, string $providerRole, int $cooldownSeconds): void`
  — always opens the breaker on this single occurrence (unlike
  `recordFailure()`'s multi-failure threshold), and marks the stored
  state as hard.
- `wasOpenedByHardFailure(int $storeId, string $providerRole): bool`
  — lets a later call, one that never sees a fresh exception because
  it skipped the provider entirely, recover *why* the breaker is open.

`FallbackChatGenerationService` now calls `recordHardFailure()`
instead of `recordFailure(..., 1, ...)` wherever a hard failure occurs
(both the primary and fallback failure-recording sites), and a new
`confirmedOrGenericallyUnavailableException()` helper checks
`wasOpenedByHardFailure(PRIMARY)` before synthesizing the
"nothing left to try" exception — throwing a new
`ProviderConfirmedDownException` (added to `HardFailureClassifier`)
instead of the generic `ProviderUnavailableException` when the breaker
is known to be open for a hard reason.

Live-reconfirmed after the fix, same 5-call scenario:

```
call 1: reasonCode=assistant_down primaryOpen=true
call 2: reasonCode=assistant_down primaryOpen=true
call 3: reasonCode=assistant_down primaryOpen=true
call 4: reasonCode=assistant_down primaryOpen=true
call 5: reasonCode=assistant_down primaryOpen=true
```

All 5 consecutive calls now consistently return `assistant_down` with
the identical message.

## Part B — hide the widget entirely, not just its input

### Design

On `reason_code: assistant_down`, both presentation layers now hide
the **entire** widget — the floating toggle button as well as the
panel, not merely the input box — for the rest of that visit:

- **Luma** (`chat-widget-luma.js`): `root.style.display = 'none'` on
  `#aavirbhava-chat-widget`, the single outer element that contains
  both the toggle button and the panel.
- **Hyva** (`chat-widget-hyva.js` + `widget-hyva.phtml`): a reactive
  `hidden` flag, bound via `x-show="!hidden"` on the outer `x-data`
  root (the same element wrapping both the toggle button and the
  panel).

This replaces Task 45's disable-input-only approach entirely — a
customer confirmed unable to get help from the assistant is now told
plainly by the widget's absence, rather than left staring at a
visibly "alive" widget that silently does nothing useful.

**Also added**: `SOFT_FAILURE_HIDE_THRESHOLD` (3), triggering the same
full hide after that many **consecutive** `assistant_unavailable`/
`retrieval_unavailable` responses with nothing else resetting the
count in between (a successful response, an out-of-scope answer, or a
validation rejection all reset it to 0). A single soft failure alone
still does **not** trigger a hide — per the task's own explicit
requirement, one transient miss (a slow response, one malformed reply)
is not, on its own, evidence the assistant is genuinely down. Both the
threshold and the reason-code constants (`REASON_ASSISTANT_DOWN`,
`REASON_ASSISTANT_UNAVAILABLE`, `REASON_RETRIEVAL_UNAVAILABLE`) live
in `chat-widget-core.js`, shared by both presentation layers via a new
`isSoftFailureReason()` helper, so Luma and Hyva cannot drift on
either the threshold value or the reason-code strings.

Deliberately **not** persisted client-side (no sessionStorage entry):
a page reload re-evaluates `ChatWidget`'s own server-side render gate
(Task 44), which is the stronger, authoritative "hidden" once the same
circuit-breaker state is visible there too — this client-side hide
only covers the gap between the failure happening and the customer's
next reload.

## Files changed

- `Api/Chat/CircuitBreakerInterface.php` — `recordHardFailure()`,
  `wasOpenedByHardFailure()` (additive)
- `Model/Chat/Fallback/CacheCircuitBreaker.php` — implements both, adds
  an `is_hard` flag to the stored cache state
- `Model/Provider/Exception/ProviderConfirmedDownException.php` (new)
- `Model/Provider/HardFailureClassifier.php` — recognizes the new
  exception
- `Model/Chat/FallbackChatGenerationService.php` — uses
  `recordHardFailure()`/`wasOpenedByHardFailure()`; new
  `confirmedOrGenericallyUnavailableException()` helper
- `view/frontend/web/js/chat-widget-core.js` — new shared constants +
  `isSoftFailureReason()`
- `view/frontend/web/js/chat-widget-luma.js`,
  `view/frontend/web/js/chat-widget-hyva.js` — replaced
  `stopped`/`stopChat()` with `hideWidgetEntirely()`/
  `trackFailureAndMaybeHide()`
- `view/frontend/templates/chat/widget-hyva.phtml` — `x-show="!hidden"`
  on the outer root; reverted the Task 45 `:disabled="loading ||
  stopped"`/placeholder bindings back to plain `:disabled="loading"`
- `CLAUDE.md` — new "Hide the widget entirely on confirmed/repeated
  failure (Task 46)" section; new environment-realities entry on the
  stale-static-asset gotcha
- Cleared and regenerated `pub/static`/`var/view_preprocessed` for this
  module (a deployment action, not a source change)

New/updated tests (5 net):
- `Test/Unit/Model/Chat/FallbackChatGenerationServiceTest.php` — the
  two existing hard-failure tests updated to assert
  `recordHardFailure()` instead of `recordFailure(..., 1, ...)`; two
  new tests proving the circuit-already-open skip-path throws
  `ProviderConfirmedDownException` when the breaker was opened by a
  hard failure and the original `ProviderUnavailableException` when it
  was opened by an ordinary soft one
- `Test/Unit/Model/Chat/ChatEntryPipelineTest.php` — one new test
  proving `ProviderConfirmedDownException` classifies as
  `assistant_down` end-to-end, same as a freshly-occurring
  `ProviderRateLimitException`/`ProviderAuthenticationException`

## Verification — full test suite

**1743 tests / 4325 assertions / 0 failures** (1667/4002 unit +
76/323 integration; up from 1740/4317). `setup:di:compile` clean.
Whole-module `php -l` sweep clean.

## Verification — live, end-to-end

With the real Gemini API key again temporarily replaced by a
deliberately invalid one (backed up first, restored byte-for-byte
afterward), and the circuit-breaker cache cleared between test runs:

```
Before the fix (5 consecutive real requests during one cooldown):
  call 1: assistant_down          call 4: assistant_unavailable
  call 2: assistant_unavailable   call 5: assistant_unavailable
  call 3: assistant_unavailable

After the fix (same scenario, fresh cooldown):
  call 1: assistant_down   call 4: assistant_down
  call 2: assistant_down   call 5: assistant_down
  call 3: assistant_down

Real HTTPS requests through the actual site URL, after clearing the
stale static assets:
  chat-widget-core.js  (luma theme):  HTTP 200, contains new logic
  chat-widget-luma.js  (luma theme):  HTTP 200, contains new logic
  chat-widget-hyva.js  (luma theme):  HTTP 200, contains new logic
  chat-widget-luma.js  (blank theme): HTTP 200, contains new logic
```

All diagnostic config changes were restored to their original real
values afterward, and the circuit-breaker state cleared via
`redis-cli FLUSHALL`.

## Not done / blocked

Nothing for the backend fixes (both diagnosed with real evidence,
fixed, tested, and live-reconfirmed) or the static-asset root cause
(fixed and confirmed via a real HTTP request). The rendered/hidden
widget through a real authenticated browser session remains
unconfirmed directly — same CAPTCHA-gated, no-browser-automation-tool
limitation disclosed for every other frontend-UI task in this module.
Verified instead via direct reading of the current JS/phtml logic
against the real, live-confirmed `assistant_down`/`assistant_unavailable`
response shape, plus confirming the served static bytes genuinely
contain that logic — the specific gap this task's own Part A
investigation found and fixed, so this verification method is now
known to actually reflect what a real browser receives.
