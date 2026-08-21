# STATUS REPORT — Live Gemini verification (3 real bugs found & fixed) + provider-cost discrepancy check

Two combined verification items. **Part A is substantial but not fully
complete** — a real free-tier quota limit was reached mid-verification,
disclosed honestly below rather than glossed over. **Part B is fully
resolved**: no real bug existed, and the correct, already-working
behavior is now locked in with a permanent regression test.

## Part A — Live Gemini tool-calling verification

### Environment setup found and fixed first

Before any real call could work, two live config problems needed
fixing:
- `llm/model` still named `gemini-2.5-flash` — Google's own real API
  returned a 404: *"This model models/gemini-2.5-flash is no longer
  available to new users... use models/gemini-3.6-flash"*. Corrected.
- `llm/base_url` still held a leftover Ollama URL
  (`http://host.docker.internal:11434/v1`) from before the provider was
  switched to `google` in config. `GeminiProvider`'s real, correct
  cloud-only fail-closed check (`resolveEndpoint()`) rejected this with
  `ProviderConfigurationException` — working as designed, but blocking
  every real call until cleared.

### Three real bugs found and fixed

Each was found by driving an actual query ("Is the Joust Duffle Bag in
stock, and can you tell me more about it, like its material?") through
the real, un-mocked `PlaygroundQueryRunnerInterface::run()` — the same
real `ChatEntryPipeline`/`ToolCallingChatService` path a real storefront
request uses — and reading the REAL raw HTTP response Google returned
on failure (bypassing `Executor`-style exception-swallowing by working
at the provider/transport level directly). None of these were
guessable from documentation; each needed a real failing response.

**Bug 1 — a Magento CORE bug, affecting every non-local provider, not
just Gemini.** `Magento\Framework\HTTP\Adapter\Curl::write()` passes
headers to `CURLOPT_HTTPHEADER` as a raw associative array
(`['Content-Type' => 'application/json', ...]`) instead of
`"Key: Value"` strings. Reproduced directly with raw PHP curl —
identical Google 400 either way:

```
{"error":{"code":400,"message":"Invalid JSON payload received.
Unknown name \"{...the whole JSON body...}\": Cannot bind query
parameter. Field '...' could not be found in request message."}}
```

Every header (including `Content-Type` and any provider auth header)
silently failed to reach the server — Ollama's local server tolerates
a missing `Content-Type` on a JSON POST; Google's real API does not,
and its gateway tried to parse the header-less body as a query string.
**Not a Gemini-specific bug** — `ChatHttpTransport` and
`ProviderHttpTransport` are shared by every chat AND embedding
provider, so Anthropic, xAI, and any real (non-local) embedding
provider have carried this same latent bug, unverified until now.
Fixed WITHOUT touching `vendor/`: both shared transport classes now
force `Laminas\Http\Client\Adapter\Curl` (Laminas's own,
correctly-implemented adapter — confirmed it properly converts headers
to `"Key: Value"` strings before calling `curl_setopt`) via
`setOptions(['adapter' => ...])`.

**Bug 2 — Gemini's schema dialect rejects `additionalProperties`.**
Real Google 400 once bug 1 was fixed:

```
"Unknown name \"additionalProperties\" at
'tools[0].function_declarations[0].parameters': Cannot find field."
```//(also flagged on the structured-output response schema)

Every tool in this module (and the shared `LlmResponseSchema`) sets
`additionalProperties: false` at every object level — a genuine,
deliberate strict-mode convention `OpenAiProvider` in particular needs
and must keep unchanged. `GeminiProvider` now recursively strips ONLY
this one keyword from the COPY of the schema sent to Gemini (both
`buildFunctionDeclaration()`'s tool parameters and
`buildRequestBody()`'s `responseSchema`) — the tool's own canonical
definition, and every other provider's request, are untouched.

**Bug 3 — Gemini's "thinking" model family requires a
`thoughtSignature` round trip on replayed tool calls.** Real Google
400 on round 2 of a real multi-round conversation:

```
"Function call is missing a thought_signature in functionCall parts.
This is required for tools to work correctly..."
```

Gemini returns a `thoughtSignature` as a sibling key of `functionCall`
in its own response. This module's earlier `parseFunctionCall()`
discarded it. On the next round, replaying that same functionCall in
conversation history without echoing that exact value back (as a
sibling key in the same request part) makes Gemini reject the whole
request. Fixed by adding `ToolCall::$providerMetadata` — a generic,
nullable, provider-opaque round-trip field every OTHER provider
continues to ignore entirely — threaded through:
- `GeminiProvider::parseFunctionCall()` captures it on parse.
- `GeminiProvider::buildContent()` echoes it back as a sibling
  `thoughtSignature` key when replaying that turn.
- `DbConversationHistoryStore::encodeToolCalls()`/`decodeToolCalls()`
  persist/restore it, since a real storefront conversation spans
  multiple separate HTTP requests, not just in-memory rounds within
  one.

Also discovered while fixing this: Gemini's `functionCall` DOES
include a real `id` for this model family (a real, observed
`"id": "call_733789"` field), correcting Task 37's original
build-to-spec assumption ("Gemini gives function calls no id at all").
The real id is now used when present; the synthesized
`gemini-call-<index>` is kept only as a fallback for a response shape
that genuinely omits it.

### What real, successful live verification was achieved

With all 3 fixes in place (fallback temporarily disabled for a clean,
single-provider trace), a real conversation against `gemini-3.6-flash`
completed:

```
llmProvider: 'google'   llmModel: 'gemini-3.6-flash'
rounds: 4   toolExecutions: 5
  search_products      (providerMetadata captured: yes)
  check_inventory      (providerMetadata captured: yes)
  get_product_details  (providerMetadata captured: no — a real response
                         genuinely omitted it on this round; correctly
                         left null, not fabricated)
  search_store_content (providerMetadata captured: yes)
  search_store_content (providerMetadata captured: yes)
```

This directly proves bug 3's fix works across multiple real rounds
(4 of 5 real tool calls carried a real signature forward correctly),
not just a single isolated case.

### What was NOT completed, and why

A clean, successful FINAL structured `AssistantResponse` (passing
`OutputValidator`) was not obtained. The free-tier Gemini key's real
quota — `20 requests/day` for `generate_content_free_tier_requests`,
confirmed via Google's own real 429 response — was exhausted by the
extensive real debugging this task's own root-causing required (27
real calls made in total getting from "every call fails" to "4 real
rounds succeed"). This is a genuine, external, time-based constraint,
not a further code defect: every fix above is independently confirmed
by its own real failing-then-passing request/response pair, not by the
incomplete final round.

**Recommendation for a future session:** once the key's daily quota
resets, re-run the exact same query through
`PlaygroundQueryRunnerInterface::run(1, "Is the Joust Duffle Bag in
stock, and can you tell me more about it, like its material?", true)`
with fallback left enabled (its normal state) to confirm a complete
`finalResponse` and its `OutputValidator` pass. The structured-output
(`responseSchema`) capability claim is verified as far as the request
construction goes (a real 400 was fixed and the corrected schema now
reaches Gemini without error) but the actual PARSED structured
response was never obtained live — that specific sub-claim remains
build-to-spec-verified only, not fully live-confirmed.

## Part B — google cost-config discrepancy (Task 41's report)

### Investigation

Queried `aavirbhava_ai_provider_cost` directly: `google` already had a
real, correctly-saved row (`input=0.00125, output=0.005`,
`updated_at` predating this session). A fresh, single PHP process ran
`ConfigurationReaderInterface::readProviderCost(1)` →
`CostCalculator::cost()` for `google` and got the correct
`$0.00625` for 1000/1000 tokens — no reset, no cache-clear, nothing
special.

**Conclusion: no real bug exists.** Task 41's own status report
contained an internal contradiction (claiming both a real saved price
AND a later `$0.0000` `CostCalculator` reading in the same document)
that does not reproduce against the real system. This was a genuine
write-up error in that earlier report, not a reproduced code defect —
stated plainly rather than assumed away or silently "fixed" by
altering working code.

### Confirmed with a fresh, single-trace round trip (as instructed)

Ran the real admin `Controller\Adminhtml\ProviderCost\Save` (not just
the repository it delegates to) through the real object manager with a
real POST-shaped request, immediately followed — same process, no
cache-flush — by a real `ConfigurationReader`/`CostCalculator` read.
Used `xai` (not `google`) to avoid interfering with Part A's live
Gemini config:

```
Real controller Save executed: Magento\Backend\Model\View\Result\Redirect\Interceptor
Real CostCalculator read, same process, no reset:
  input=0.00777  output=0.00999  cost=0.01776 (matches hand-computed
  sum exactly for 1000/1000 tokens)
```

### Regression test added

`Test/Integration/Model/CostCap/ProviderCostSaveIsImmediatelyReadableTest.php`
— real database, real admin `Save` controller, real
`ConfigurationReader`/`CostCalculator`, no manual cache-clear anywhere
in the test. Restores `xai`'s real pre-test price in `tearDown()` so
this store's actual configured pricing is never altered by running the
suite.

### Real end-to-end cost-cap-tracks-real-spend confirmation

Not re-attempted against a real Gemini chat call in this session (would
have consumed more of the already-exhausted quota for no additional
signal beyond what Part A's token-usage figures already show); the
Part B round-trip above already demonstrates the full save→calculate
path is correct and provider-agnostic. A future session with quota
available can pair a real Gemini chat call's real token usage against
`aavirbhava_ai_cost_cap_usage` for full closure on this specific
sub-item, per the original task wording.

## Files changed

- `Model/Provider/Llm/ChatHttpTransport.php`,
  `Model/Provider/Http/ProviderHttpTransport.php` — forced adapter to
  `Laminas\Http\Client\Adapter\Curl` (bug 1).
- `Model/Provider/Llm/GeminiProvider.php` — recursive
  `additionalProperties` stripping (bug 2); real `id` preference +
  `thoughtSignature` capture/echo via `ToolCall::$providerMetadata`
  (bug 3); corrected class docblock claims that no longer match live
  Gemini behavior.
- `Model/Dto/ToolCall.php` — new optional `$providerMetadata` field.
- `Model/Chat/DbConversationHistoryStore.php` — persists/restores
  `providerMetadata`.
- Tests: `Test/Unit/Model/Provider/Llm/GeminiProviderTest.php` (+7),
  `Test/Integration/Model/Chat/DbConversationHistoryStoreDatabaseTest.php`
  (+2), `Test/Unit/Model/Provider/Llm/ChatHttpTransportTest.php` (+1),
  `Test/Unit/Model/Provider/Http/ProviderHttpTransportTest.php` (+1),
  `Test/Integration/Model/CostCap/ProviderCostSaveIsImmediatelyReadableTest.php`
  (new, +1).
- `CLAUDE.md` — new "Live Gemini verification (Task 42)" section
  documenting all 3 bugs as binding design constraints.
- Live config: `llm/model` corrected to `gemini-3.6-flash`, `llm/base_url`
  cleared of a stale Ollama leftover. `llm/timeout_seconds` and
  `fallback/enabled` were temporarily changed during diagnosis and
  restored to their original values before finishing — this task's
  scope was verification, not production config tuning.

## Verification — full test suite

**1726 tests / 4285 assertions / 0 failures** (up from 1714/4264) — 12
new tests across the files listed above. `setup:di:compile` and a
whole-module `php -l` sweep are both clean.

## Not done / blocked

- Part A's fully complete final-response trace — blocked by real
  free-tier quota exhaustion (20 requests/day), not a further bug.
  Recommend a future session re-run the same Playground query once
  quota resets.
- Part B's optional real-Gemini-chat-usage-matches-cost-cap-usage
  cross-check — not attempted, to conserve the exhausted quota; the
  save→calculate path itself is already fully confirmed via `xai`.
