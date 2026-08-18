# STATUS REPORT — Timeout diagnosis and console logging

Real chat messages had gone back to the generic fallback after Task
16. The suspected cause (a local-model-latency vs. configured-timeout
mismatch, flagged in Task 16's own report) turned out **not** to be
the real cause — the fast, sub-second failure time ruled that out
almost immediately. The real cause was a genuine bug: `get_cart`'s
tool schema `json_encode()`s its empty `properties` as a JSON array,
which a real Ollama instance rejects outright with HTTP 400 the
moment that tool is offered. Fixed, plus added always-on browser
console.debug logging of each chat request/response cycle.

## Files created/changed

- `Model/Tool/GetCartTool.php` — `inputSchema()`'s `properties` is now
  `new \stdClass()`, was `[]`.
- `Test/Unit/Model/Tool/GetCartToolTest.php` — 1 new regression test
  asserting the raw JSON encoding.
- `Model/Provider/Llm/AbstractChatProvider.php` — `buildTool()`'s own
  empty-parameters fallback default gets the identical fix.
- `Test/Unit/Model/Provider/Llm/OpenAiProviderTest.php` — 1 new
  regression test on the full encoded request body.
- `view/frontend/web/js/chat-widget-core.js` — `console.debug` logging
  added to the shared `sendMessage()` function.

**Tests:** 2 net new (full suite 1253 → 1255).

## Conventions followed

The diagnosis used this module's own established "verify, don't
assume" discipline throughout: reproduced the exact reported symptom
first, measured its actual timing before theorizing about cause,
ruled out the circuit breaker and container health directly against
real state rather than by inference, and used the same temporary-
debug-instrumentation-then-immediately-revert technique this module
has used since Task 9 to reveal a sanitized exception's real cause —
reverted immediately once the real HTTP request/response pair was
captured. The console-logging design keeps the diff minimal by
centralizing all three log points in the one shared core JS function
both presentation layers already funnel through, rather than
duplicating logging in each theme.

## Deviations from existing conventions

None.

## Diagnosis

**Step 1 — reproduce and measure, before theorizing.** Sent a real
message through the real `/aichat/chat/send` endpoint. Reproduced the
reported symptom exactly: `reason_code: assistant_unavailable`. But
the response came back in **0.2 seconds** — nowhere near the
20-second configured `llm/timeout_seconds`. This single measurement
immediately ruled out Task 16's own flagged timeout-mismatch concern
as the cause of *this specific* regression, before any config or code
change was made toward that theory.

**Step 2 — rule out infrastructure degradation.** Confirmed all
docker-magento containers were healthy (`docker ps`), the real
OpenSearch index still held its 811 documents, and Ollama was reachable
from inside the PHP-FPM container (`curl` to `/api/tags`, HTTP 200). A
direct, unrelated `ChatGenerationServiceInterface::chat()` call (real
DI, bypassing the full pipeline) succeeded in 18.4 real seconds,
proving the local model itself was genuinely working and available —
just not what the fast-failing production request was hitting. A
direct cache check of `CircuitBreakerInterface`'s real state for store
1 (both `primary` and `fallback` roles) came back clean/closed,
ruling out a stuck circuit breaker from earlier heavy testing.

**Step 3 — reveal the real exception.** With every "slow/unavailable
backend" theory ruled out by a request that failed in 0.2s, temporarily
instrumented `ChatEntryPipeline`'s `catch (ProviderException)` block
(reverted immediately after capturing what was needed) to reveal the
real, otherwise-sanitized exception: `ProviderInvalidResponseException`
from an HTTP **400** response — not a timeout exception at all.
Temporarily instrumented `ChatHttpTransport::post()` (also reverted
immediately) to capture the real outgoing request body and Ollama's
real response body:

```
"error":{"message":"Value looks like object, but can't find closing '}' symbol", ...}
```

Inspecting the captured request body's `tools` array directly (Python
`json.load`, which — unlike `json_decode(..., true)` — preserves the
distinction between a JSON object and array) showed exactly one tool,
`get_cart`, with `properties` decoded as a Python `list` (`[]`) while
every other tool's `properties` decoded as a `dict` (`{}`). Traced this
to `GetCartTool::inputSchema()`'s `'properties' => []` — a plain PHP
array `json_encode()`s as a JSON array, but JSON Schema's `properties`
keyword requires an object (a map), even when empty. OpenAI's real API
tolerates this common PHP-originated quirk; this environment's real
Ollama instance does not, and rejects the **entire** chat request (not
just the malformed tool definition) the moment `get_cart` is offered
in the tools array.

**Why now, and not in earlier tasks' live checks:** `get_cart` is only
offered when `guardrails.cart_mutations_enabled` is on (its
`authorize()` gates on the same flag `add_to_cart`/`remove_from_cart`
use). That flag defaults to disabled, and most earlier tasks' live
checks ran with it in that default state. Task 16's own Part C
verification (configurable-cart testing) left it enabled at store-view
scope — which is where this session's config already stood — so this
was the first real chat message to actually offer `get_cart` as a
tool since Ollama became the working chat provider, and the first to
hit this pre-existing (since Task 7), previously-latent bug.

**Confirmed the exact fix before writing any code:** replayed the
identical captured request with only `get_cart`'s `properties` changed
from `[]` to `{}`, directly against the real Ollama instance — HTTP
200, a genuine tool-call response.

## Fix

`GetCartTool::inputSchema()`'s `properties` value changed to `new
\stdClass()`, which always `json_encode()`s as `{}`, matching every
other tool's non-empty `properties` map, so this fix is a strict,
minimal correction, not a new abstraction. Also
applied the identical fix to `AbstractChatProvider::buildTool()`'s own
fallback default for a tool missing `parameters` entirely — currently
unreachable in practice (every real tool supplies a full
`inputSchema()`), fixed anyway for consistency and to close off the
same class of bug recurring in any future zero-argument tool.

Two new regression tests deliberately assert on the **raw JSON string**
of the request, not on `json_decode(..., true)`'s result — the latter
round-trips both `{}` and `[]` back to an identical PHP `[]`, so a
test written that way would never have caught this bug (and didn't,
in this module's existing test suite, until now).

## No timeout/config change was needed

Task 16's own flagged concern — a local model measured at ~50 seconds
for a trivial prompt under load, against a 20-second configured
timeout — is a real, distinct, still-accurate observation about this
host's performance characteristics. It was not, however, the cause of
this regression: the failure was sub-second, and today's real,
successful chat calls completed in 13-19 seconds, comfortably under
the existing, *unchanged* 20-second `llm/timeout_seconds`. No config
value was raised.

Reporting plainly, per the task's own explicit instruction rather than
coding around it: this host's real local-model latency for a genuine
multi-tool-definition chat completion measured 13-19 seconds today,
and Task 16 separately measured ~50 seconds for a much simpler prompt
under different load conditions. Sub-few-second response times are not
realistic for this specific local/CPU-inference Ollama setup — a
genuine hardware/environment characteristic of this development
environment, not a defect in this module's code, and not something
this task changed or attempted to change.

## Console debug logging design

Added directly inside `chat-widget-core.js`'s shared `sendMessage()`
function — the one place both `chat-widget-luma.js` and
`chat-widget-hyva.js` already funnel every request through, so neither
presentation-layer file needed any change:

1. **Request sent** — `console.debug` with the outgoing message text,
   logged immediately before the `fetch()` call.
2. **Response received** — `console.debug` with the HTTP status/`ok`
   flag, `reason_code`, `metadata`, `awaiting_confirmation`, and the
   full raw parsed response body, logged once the JSON is parsed.
3. **Request failed** — `console.debug` with the caught error, logged
   in a `.catch()` that then re-throws, so the existing `.catch()`
   handling in both presentation-layer files (which shows the
   customer a generic "something went wrong" bubble) is completely
   unchanged.

No `general.debug_logging` admin toggle exists anywhere in this
module — confirmed by a full-codebase search, not assumed — so per the
task's own explicit fallback instruction, this logging is always on
and ungated: browser console output carries no customer-facing harm
the way UI text would.

## Live verification

- **The original regression, reproduced then confirmed fixed**: the
  real `/aichat/chat/send` endpoint, given the exact original report's
  message ("show me some duffle bags"), now returns a genuine,
  product-specific answer (`reason_code: null`, 3 real duffle bags
  with real prices/URLs, real follow-up questions) instead of
  `assistant_unavailable`, completing in 13.6 real seconds. Repeated
  with a cart-tool-eligible message ("add a joust duffle bag to my
  cart") twice more — both succeeded cleanly with `reason_code: null`.
- **Browser console.debug output, in a real browser**: used Playwright
  to drive this machine's actual installed Google Chrome (not a
  fabricated or mocked browser) headlessly against the real storefront
  homepage — opened the real chat widget, typed and sent a real
  message, and captured the real console output verbatim:
  ```
  [debug] [Aavirbhava AI Assistant] sending message {message: show me some duffle bags}
  [debug] [Aavirbhava AI Assistant] response received {status: 200, ok: true, reasonCode: null, metadata: Object, awaitingConfirmation: false}
  ```
  confirming the logging works in an actual browser session, not just
  by reading the code.

## Container verification

`php -l`, `setup:upgrade`, `setup:di:compile`, `cache:flush` all
clean. All Docker containers confirmed healthy; OpenSearch index
intact (811 documents); Ollama reachable.

## Test results

1253 → 1255 tests (+2), 3060 → 3063 assertions (+3), 0 failures.

## Known gaps / TODOs left for later tasks

None newly introduced. Task 16's own local-model-latency observation
stands as a real, ongoing environment characteristic: future
live-check-heavy tasks against this host should keep expecting real
local-LLM calls to take low double-digit seconds, not sub-second, and
pace real calls accordingly — this task's own diagnosis independently
corroborated that observation, even though it was not the actual
cause of this task's own regression.

## Skill files updated

`references/progress-log.md` — status rows 6 and 12 updated; header
summary updated; a full Task 17 history entry added.

## Not done / blocked

Nothing blocked. Both the diagnosis and the fix were completed and
live-verified, including real browser console output.
