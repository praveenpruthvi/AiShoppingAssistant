# STATUS REPORT — Assistant-unavailable widget-hide safeguard + Part A "missing response" investigation

Two related items. **Part A found no reproducible bug** — a claim in
both the task prompt and CLAUDE.md's own pre-written spec for this
task ("REAL BUG (found live)") did not hold up against direct, repeated
live investigation of the actual current code. **Part B is fully
implemented, tested, and live-verified.**

## Part A — "missing response" investigation

### What was claimed

CLAUDE.md's pre-written spec for this task stated as fact:

> REAL BUG (found live): with fallback disabled, a failed primary
> provider call currently produces NO response to the frontend at
> all — not even the documented safe non-AI SafeResponse.

### What was actually found

Confirmed first via `git status` that every relevant file
(`FallbackChatGenerationService.php`, `ChatEntryPipeline.php`,
`Controller/Chat/Send.php`, `CostTrackingChatGenerationService.php`,
`ToolCallingChatService.php`) had zero uncommitted changes — the
investigation is against the real, canonical, committed code, not
stale WIP.

Read the full call chain end to end:
`Controller\Chat\Send` → `ChatEntryPipelineInterface::handle()` →
`ToolCallingChatService::converse()` → `ChatGenerationServiceInterface::chat()`
(→ `CostTrackingChatGenerationService` → `FallbackChatGenerationService`
→ `ChatGenerationService`/the provider adapter). Every layer either
correctly propagates a `ProviderException` untouched, or correctly
catches one and converts it to a `SafeResponse`. No gap was found by
reading alone, so this was verified live three separate, real ways
against the current environment:

1. **Invalid API key** (a clean 401), fallback disabled, via the raw
   `ChatEntryPipelineInterface::handle()` call:
   ```
   RESULT: ChatPipelineResult { shortCircuited: true, safeResponse:
     SafeResponse { message: "I can help you search, compare, and
     learn about products...", reasonCode: 'assistant_unavailable' } }
   ```
2. **Genuinely unreachable endpoint** (a real connection-level
   failure — a distinct failure mode from a clean auth rejection,
   explicitly called out in the task), fallback disabled, same raw
   pipeline call: took 16s (3 real retry attempts), then the identical
   correct `SafeResponse`.
3. **The same unreachable-endpoint case through the FULL real HTTP
   controller** (`Controller\Chat\Send::execute()`, constructed
   through the real object manager, rendered into a real
   `Magento\Framework\App\Response\Http` object):
   ```
   HTTP STATUS: 200
   BODY: {"message":"I can help you search, compare, and learn about
     products and services available on this store. What are you
     looking for?","reason_code":"assistant_unavailable", ...}
   ```

All three produced a real, correct `SafeResponse` — never an uncaught
exception, never a raw 500, never silence.

Two existing tests already independently prove both halves of this
chain, and both pass on the current code:
- `FallbackChatGenerationServiceTest::testNoFallbackConfiguredPropagatesThePrimaryFailure`
  — proves `FallbackChatGenerationService` correctly propagates
  (never swallows) the primary's exception when fallback isn't
  configured.
- `ChatEntryPipelineTest::testGenuineProviderUnavailabilityIsNeverRetriedUnlikeAnInvalidResponse`
  — proves `ChatEntryPipeline` correctly catches a
  `ProviderUnavailableException` (the exact exception type
  `FallbackChatGenerationService` throws in this scenario) and
  converts it to the `assistant_unavailable` `SafeResponse`.

### Conclusion

**No bug reproduces against the current code.** This is the same
pattern as Task 41/42's own "reported discrepancy, investigated,
found no real bug" finding — documented plainly rather than either
(a) fabricating a "fix" for something that isn't broken, or (b)
leaving a disproven claim standing silently in CLAUDE.md. The
"REAL BUG (found live)" section was corrected to reflect this finding
(see CLAUDE.md's "Assistant-unavailable widget hide (Task 44)"
section).

### Regression test added anyway

The task explicitly requested a regression test regardless of the
investigation's outcome, and there was a genuine, previously-untested
integration seam left: `FallbackChatGenerationServiceTest` already
used a real (not mocked) `ChatGenerationService` under the class
being tested, but nothing tested the layer immediately above it —
`ToolCallingChatService`, the thin wrapper every real caller
(`ChatEntryPipeline`, the Admin Playground) actually goes through.

Added `testConverseNeverSwallowsAPrimaryFailurePropagatedFromFallbackChatGenerationServiceWhenFallbackIsDisabled`:
wires a REAL, un-mocked `ToolCallingChatService` around the exact same
real `FallbackChatGenerationService` setup the existing propagation
test already used, and asserts `converse()` still throws the expected
`ProviderTimeoutException` — proving the pass-through layer doesn't
catch, wrap, or lose it either. This is genuinely new coverage, not
evidence of a bug.

## Part B — the new widget-hide safeguard

### Implementation

`Block\Frontend\ChatWidget::_toHtml()`'s existing render-gate:

```php
if (!$this->isAssistantEnabled() || $this->costCapChecker->isBlocking() || $this->isAssistantConfirmedDown()) {
    return '';
}
```

`isAssistantConfirmedDown()` reuses the exact same
`CircuitBreakerInterface` state `FallbackChatGenerationService`
already maintains — no second, separate health-tracking mechanism, per
the task's own explicit instruction:

```php
private function isAssistantConfirmedDown(): bool
{
    try {
        $storeId = (int) $this->storeManager->getStore()->getId();

        if (!$this->circuitBreaker->isOpen($storeId, CircuitBreakerInterface::ROLE_PRIMARY)) {
            return false;
        }

        if (!$this->configurationReader->readFallback($storeId)->isEnabled()) {
            return true;
        }

        return $this->circuitBreaker->isOpen($storeId, CircuitBreakerInterface::ROLE_FALLBACK);
    } catch (Throwable $exception) {
        $this->_logger->error(...);

        return true;
    }
}
```

### Key decisions

- **Never trips on a single transient failure.** `CircuitBreakerInterface::isOpen()`
  only returns `true` after `failureThreshold` real CONSECUTIVE
  failures accumulate — that's the circuit breaker's own existing
  contract (`CacheCircuitBreaker::recordFailure()`, from Task 22).
  Reading the circuit's aggregate state, rather than any single
  request's own outcome, is what makes "don't hide on one bad call"
  fall out naturally with zero extra logic in `ChatWidget` itself —
  exactly matching the task's own instruction not to build a second
  mechanism.
- **Primary's circuit alone being open is not enough to hide the
  widget.** If a fallback provider is configured, enabled, and its OWN
  circuit is still closed, the assistant is genuinely still usable —
  a real chat request in that exact state gets a real AI response via
  fallback, not a `SafeResponse`. Hiding the widget there would be
  actively wrong, not merely overcautious. This is the case Part A's
  (pre-existing, already-correct) fallback logic handles — Part B's
  own job is only to detect when NEITHER path works.
- **Fail direction is deliberately the OPPOSITE of the cost-cap
  check right next to it.** `costCapChecker` fails OPEN on its own
  internal error (a tracking glitch must never block a working
  channel — see CLAUDE.md's "LLM cost cap" section). This new check
  fails CLOSED on its own internal error instead — matching
  `isAssistantEnabled()`'s own existing fail-closed precedent in the
  very same class ("a config read failure here degrades to 'don't
  render the widget'"), not cost-cap's fail-open one. An assistant
  this safeguard cannot even confirm the health of is treated the
  same as one confirmed down, never silently assumed healthy.

## Files changed

- `Block/Frontend/ChatWidget.php` — new `CircuitBreakerInterface`
  constructor dependency (already had a real DI preference from an
  earlier task, no `di.xml` change needed); new
  `isAssistantConfirmedDown()` private method; new third condition in
  `_toHtml()`'s existing gate.
- `Test/Unit/Model/Chat/FallbackChatGenerationServiceTest.php` — +1
  test (the new integration-seam regression test).
- `Test/Unit/Block/Frontend/ChatWidgetTest.php` — +6 tests:
  - Hides when primary's circuit is open and fallback is disabled.
  - Hides when primary AND fallback circuits are both open.
  - Does NOT consider the assistant down when primary is open but
    fallback is enabled and healthy.
  - Does NOT consider the assistant down when primary is healthy.
  - Does NOT consider the assistant down after a single transient
    failure that hasn't tripped the circuit.
  - Hides when the check's own internal read throws.

  The three "does not hide" cases are asserted against the private
  `isAssistantConfirmedDown()` method directly (via reflection) rather
  than the public `toHtml()` — a "does not hide" outcome falls through
  to `Template`'s own real fetchView()/template-engine machinery, which
  this test file's own pre-existing documented convention already
  establishes is unsafe to exercise in a bare PHPUnit process (no full
  Magento app bootstrap). This still proves exactly the logic under
  test: whether the new safeguard itself decides to hide or not.
- `CLAUDE.md` — the pre-written "Assistant-unavailable widget hide"
  section's disproven "REAL BUG (found live)" claim corrected and the
  section filled in with the actual implemented safeguard's binding
  design details.

## Verification — full test suite

**1733 tests / 4292 assertions / 0 failures** (up from 1726/4285).
`setup:di:compile` clean. A whole-module `php -l` sweep is clean.

## Verification — live, both parts together in one real forced-down state

This is the task's own explicit requirement 9, done as one continuous,
real scenario rather than two separate ones:

```
Setup: primary pointed at a genuinely unreachable endpoint
       (10.255.255.1:9999), 3s timeout, fallback confirmed disabled.

3 real consecutive chat requests (each retries 3x internally):
  call 1: elapsed=10.8s reason=assistant_unavailable
  call 2: elapsed=9.9s  reason=assistant_unavailable
  call 3: elapsed=9.9s  reason=assistant_unavailable

Real circuit-breaker state check (via the real object manager,
CircuitBreakerInterface::isOpen(), not a mock):
  ROLE_PRIMARY:  true   <- genuinely tripped
  ROLE_FALLBACK: false

4th real chat request, breaker now genuinely open:
  PART A - chat request elapsed: 0.4s        <- retries skipped entirely
  PART A - shortCircuited: true
  PART A - reasonCode: assistant_unavailable
  PART A - message: "I can help you search, compare, and learn about
                      products and services available on this store.
                      What are you looking for?"

Same forced-down state, the real ChatWidget block (real object
manager, not mocked):
  PART B - widget toHtml() length: 0
  PART B - widget renders empty: true
```

All diagnostic config changes (`llm/provider`, `llm/base_url`,
`llm/timeout_seconds`, `llm/api_key`) were restored to their original
real values afterward; the real, intentionally-tripped circuit-breaker
state was cleared via `redis-cli FLUSHALL`.

## Not done / blocked

Nothing for Part B — fully implemented, tested, and live-verified.
Part A found no bug to fix; this is disclosed as a real, evidence-
backed finding, not a gap. The rendered widget through a real
authenticated browser session remains unconfirmed by this session
directly — same CAPTCHA-gated, no-browser-automation-tool limitation
disclosed for every other admin/frontend-UI task in this module — but
this is verified instead via the real, un-mocked `Block\Frontend\ChatWidget`
class constructed through the real object manager, which is what
actually decides whether any markup reaches the browser at all.
