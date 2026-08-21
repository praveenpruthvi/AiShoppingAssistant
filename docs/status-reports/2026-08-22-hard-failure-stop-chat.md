# STATUS REPORT — Hard vs. transient provider failures: distinct message + stop-the-chat safeguard

## The problem (user-reported, with a screenshot)

The Admin Playground showed `Provider error: PROVIDER_RATE_LIMIT`
while the storefront widget kept showing "Chat with us" and — the real
complaint — kept answering every customer message with the exact same
generic text: *"I can help you search, compare, and learn about
products and services available on this store. What are you looking
for?"* That text is `GuardrailConfigInterface::outOfScopeMessage()`,
meant for a genuine "that's out of scope for me" answer. Reusing it
for a provider failure made a broken assistant look identical to a
working one that just didn't understand the question — confusing and,
per the user, frustrating.

First checked whether Task 44's widget-hide safeguard should already
have caught this. It hadn't, correctly: the real circuit breaker
(checked via the real object manager) was NOT open yet. A single
rate-limited request is exactly the "don't hide on one transient
failure" case Task 44 was explicitly built and tested to preserve —
not a bug, working as designed. But the user's actual ask went further
than what Task 44 covered: rate-limited/invalid-key/unauthorized
failures should be treated as confirmed-unrecoverable *immediately*
(not after several consecutive failures like a merely transient
outage), get a message that plainly says the service is down, and stop
the chat for the rest of the visit — while a genuinely one-off failure
(a slow response, one malformed reply) should still just let the
customer try again.

## What was built

### `HardFailureClassifierInterface` / `HardFailureClassifier`

A new, narrow classifier: `ProviderRateLimitException`/
`ProviderAuthenticationException` — plus their embedding-provider
siblings, `EmbeddingRateLimitException`/`EmbeddingAuthenticationException`,
used during retrieval's query-embedding step — are "hard": an
exhausted quota or an invalid/revoked key will fail identically on the
very next request, so retrying cannot help. Every other
`ProviderException` (timeout, transport, invalid response, generic
unavailability) stays "transient" — a fresh request has a genuine
chance of not hitting the same problem again.

The embedding-hierarchy check was not an afterthought: `EmbeddingRateLimitException`/
`EmbeddingAuthenticationException` are *sibling* classes of the
chat-provider hierarchy (both extend the common `ProviderException`
base directly), not subclasses of `ProviderRateLimitException`/
`ProviderAuthenticationException` — an `instanceof` check against only
the chat-side classes would have silently missed every embedding-side
hard failure. Caught this by reading the actual class hierarchy before
writing the classifier, not by a test failure.

### `FallbackChatGenerationService`

Two changes for a hard failure:

1. `attemptPrimaryWithRetry()` skips its local 3-attempt backoff-retry
   loop — retrying a 429/401 three times in ~1.4s cannot change the
   outcome, it only burns quota and latency.
2. Both `recordFailure()` call sites (primary role and fallback role)
   force the affected circuit open on this **single** occurrence
   (`recordFailure($storeId, $role, 1, $cooldownSeconds)`) instead of
   waiting for the configured multi-failure `failure_threshold`, so
   `ChatWidget`'s Task 44 hide safeguard reacts immediately rather than
   after several more customers each hit the identical guaranteed
   failure.

**A real, subtler bug was caught and fixed during implementation**,
not by the user: `ProviderAuthenticationException` is deliberately
never fallback-*eligible* (a bad primary key must never itself trigger
a fallback attempt — a pre-existing, correct safety boundary, left
unchanged). The original code only called `recordFailure()` for
fallback-eligible exceptions. That meant an authentication failure
would **never** have touched the circuit breaker at all — permanently
invisible to Task 44's widget-hide check no matter how many times it
happened, silently defeating this entire feature for its single most
important case (an invalid key). Fixed by separating two questions
that used to share one gate: "may this trigger a fallback attempt"
(unchanged — still no for authentication) vs. "should the circuit
breaker learn about this" (now yes for any hard failure, regardless of
fallback eligibility).

### `ChatEntryPipeline`

Picks the customer-facing reason code/message from whether the
**terminal** exception — the one left after every retry and fallback
attempt is exhausted — is hard or transient. Captured across the
tool-calling loop as `$terminalProviderException`, reset to `null` on
any attempt that succeeds `converse()` so it only ever reflects the
real last failure, never a stale earlier one from an already-recovered
attempt.

- Transient keeps `REASON_ASSISTANT_UNAVAILABLE`, now paired with a
  new, genuinely different, admin-configurable "Assistant Temporarily
  Unavailable" message — the customer can reasonably try again.
- Hard gets a new `REASON_ASSISTANT_DOWN` reason code, paired with a
  new "Assistant Down" message — applied identically at **both**
  short-circuit sites (the LLM tool-calling loop, and the
  retrieval/embedding-failure catch), since the customer-facing
  meaning ("stop, don't retry") is the same regardless of which
  backend produced the hard failure. The debug log/trace still
  distinguishes which backend actually failed.

Neither new message reuses `outOfScopeMessage()` any more — that reuse
was the actual root cause of the user-reported confusing behavior.
`outOfScopeMessage()` itself is untouched, still used only for genuine
scope decisions (assistant disabled, out-of-scope classification,
output-validator rejection).

Both new messages are real, admin-configurable guardrails fields
(`guardrails/assistant_unavailable_message`,
`guardrails/assistant_down_message`), matching the existing
`out_of_scope_message` field's own convention — a merchant can edit
either from Stores > Configuration, same as before.

### Frontend

A response with `reason_code: "assistant_down"` — the string is
exposed as a shared `REASON_ASSISTANT_DOWN` constant from
`chat-widget-core.js` so both presentation layers reference the
identical value rather than each hardcoding it — permanently disables
the input box and send button for the rest of that visit, in both the
Luma (`chat-widget-luma.js`) and Hyva (`chat-widget-hyva.js` +
`widget-hyva.phtml`'s new `:disabled="loading || stopped"` binding)
layers. The widget itself stays open/closeable; only the ability to
send another message is removed, with the input's placeholder text
changed to say the conversation has ended.

This is deliberately **not** persisted client-side (no sessionStorage
entry): a page reload re-evaluates `ChatWidget`'s own server-side
render gate (Task 44), which by then hides the widget entirely once
the same now-force-opened circuit-breaker state is visible there too —
a stronger form of "stopped" than a grayed-out input. The in-page
`stopped` flag only covers the gap between the hard failure happening
and the customer's next reload.

## A second real bug, found live while verifying this feature

While live-verifying the fix against the real environment (an invalid
Gemini key), the pipeline kept returning `assistant_unavailable`
(soft) instead of the expected `assistant_down` (hard). Investigated
by curling the real Gemini `generateContent` endpoint directly with a
deliberately bad key:

```
$ curl -s -o resp.json -w "HTTP_STATUS:%{http_code}\n" \
  "https://generativelanguage.googleapis.com/v1beta/models/gemini-3.6-flash:generateContent?key=definitely-invalid-test-key-12345" \
  -H "Content-Type: application/json" -d '{"contents":[{"role":"user","parts":[{"text":"hi"}]}]}'
HTTP_STATUS:400
{
  "error": {
    "code": 400,
    "message": "API key not valid. Please pass a valid API key.",
    "status": "INVALID_ARGUMENT",
    "details": [
      {"reason": "API_KEY_INVALID", ...}
    ]
  }
}
```

Gemini's real API returns a genuine **HTTP 400** for an invalid key —
not 401/403, which is all `HttpStatusMapper` recognized as an
authentication failure. Left alone, this meant every bad Gemini key
was silently misclassified as `ProviderInvalidResponseException` (a
retryable compliance problem), never even reaching
`HardFailureClassifier` as the authentication failure it actually
was — this entire feature would have silently never worked for
Gemini specifically, which is this module's only currently-configured
live provider in this environment.

Fixed narrowly in a new `GeminiProvider::assertNotApiKeyFailure()`,
called before the generic `HttpStatusMapper::assertSuccess()`: only a
400 whose body contains the literal, documented `API_KEY_INVALID`
string is reclassified to `ProviderAuthenticationException`; every
other 400 (a genuine malformed request/schema issue — e.g. an unknown
field name) is left for `HttpStatusMapper`'s normal, unchanged
handling. Added a regression test proving both directions
(`testRealGeminiApiKeyInvalidBodyOnA400MapsToAuthenticationException`,
`testUnrelatedBadRequestStatusStillMapsToInvalidResponseException`).

This is the second time in this module a provider's real behavior has
diverged from its own published documentation (see Task 42's
`additionalProperties`/`thoughtSignature` findings) — another
reminder that a provider's actual wire behavior, not its docs, is the
only reliable source when an error-mapping question comes up.

## Files changed

- `Api/Provider/HardFailureClassifierInterface.php` (new)
- `Model/Provider/HardFailureClassifier.php` (new)
- `etc/di.xml` — new preference for the interface above
- `Model/Chat/FallbackChatGenerationService.php` — skip local retry +
  force-open circuit on hard failure (both roles); auth failures now
  record even though still never fallback-eligible
- `Model/Chat/ChatEntryPipeline.php` — terminal-exception tracking,
  new `REASON_ASSISTANT_DOWN`, hard/soft message selection at both
  short-circuit sites
- `Model/Config/Path.php`, `Model/Config/ConfigurationReader.php`,
  `Api/Config/GuardrailConfigInterface.php`,
  `Model/Config/GuardrailConfig.php`, `etc/config.xml`,
  `etc/adminhtml/system.xml` — two new guardrails messages
- `Model/Provider/Llm/GeminiProvider.php` — real API_KEY_INVALID
  reclassification (the second bug above)
- `view/frontend/web/js/chat-widget-core.js` — shared
  `REASON_ASSISTANT_DOWN` constant
- `view/frontend/web/js/chat-widget-luma.js`,
  `view/frontend/web/js/chat-widget-hyva.js`,
  `view/frontend/templates/chat/widget-hyva.phtml` — stop-the-chat UI
- `CLAUDE.md` — new "Hard vs. transient provider failures (Task 45)"
  section; `references/progress-log.md` — new Task 45 entry + header

New/updated tests (7 net):
- `Test/Unit/Model/Chat/FallbackChatGenerationServiceTest.php` — rate
  limit skips local retry but stays fallback-eligible; authentication
  still forces the circuit open despite never consulting fallback;
  fallback-side hard failure also force-opens the fallback circuit
- `Test/Unit/Model/Chat/ChatEntryPipelineTest.php` — rate limit and
  authentication both short-circuit to `assistant_down` + the new
  message; an embedding-side rate limit during retrieval does too
  (proving the sibling-hierarchy classification); two pre-existing
  tests' message assertions updated from the old shared out-of-scope
  text to the new soft message
- `Test/Unit/Model/Provider/Llm/GeminiProviderTest.php` — the real
  `API_KEY_INVALID`-on-400 reclassification, and a companion test
  proving an unrelated 400 is untouched

## Verification — full test suite

**1740 tests / 4317 assertions / 0 failures** (1664/3994 unit +
76/323 integration; up from 1733/4292). `setup:di:compile` clean.
Whole-module `php -l` sweep clean.

## Verification — live, end-to-end

With the real Gemini API key temporarily replaced by a deliberately
invalid one (the real encrypted value backed up first via a direct
`core_config_data` read, restored byte-for-byte afterward):

```
Before: CircuitBreakerInterface::isOpen(PRIMARY) = false

One real ChatEntryPipelineInterface::handle() call:
  elapsed = 1.53s
  shortCircuited = true
  reasonCode = assistant_down
  message = "Our shopping assistant is temporarily unavailable due to
             a technical issue. Please try again later, or contact us
             directly for help."

After that SINGLE call: CircuitBreakerInterface::isOpen(PRIMARY) = true

Real Block\Frontend\ChatWidget::toHtml() in that same state:
  html length = 0
  renders empty = true
```

Every step above ran against the real object manager, the real
Gemini HTTP endpoint (via the deliberately-bad key), and the real
Redis-backed circuit breaker — nothing mocked. All diagnostic config
changes were restored to their original real values afterward, and the
circuit-breaker state cleared via `redis-cli FLUSHALL`.

## Environment note

Partway through this task, the entire docker-magento stack (all 8
containers) was found stopped — unrelated to any command run in this
session, apparently an environment-level restart between turns.
Recovered via `bin/docker-compose up -d --remove-orphans` (not the
`bin/start` wrapper, whose trailing `bin/cache-clean --watch` step
blocks indefinitely and isn't suitable for a scripted restart inside
this workflow). All containers came back healthy; the real diagnostic
DB state (the temporarily-invalid API key, mid-verification) survived
intact since the database lives in a Docker volume, not
container-ephemeral storage.

## Not done / blocked

Nothing for the backend or the JS logic — both were implemented,
tested, and live-verified together. The rendered widget/disabled-input
state through a real authenticated browser session remains unconfirmed
directly (same CAPTCHA-gated, no-browser-automation-tool limitation
disclosed for every other frontend-UI task in this module) — verified
instead via the real, un-mocked `ChatEntryPipeline`/`ChatWidget`/
`CircuitBreakerInterface` objects constructed through the real object
manager, plus direct reading of the new JS logic against the exact
normalized response shape the real backend actually sends.
