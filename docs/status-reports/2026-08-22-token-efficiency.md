# STATUS REPORT — Token efficiency: unconditional prompt caching + the Token Optimization toggle

Two separate mechanisms, as scoped: (A) provider-native prompt caching
— unconditional infrastructure, no quality tradeoff — and (B) a new
admin toggle gating one concrete, real context-trimming behavior that
DOES trade something away for lower cost.

## Part A — provider-native prompt caching

### What was confirmed first (not assumed)

Read `AnthropicProvider.php` before writing anything. `parseUsage()`
already parsed `cache_read_input_tokens` (Task 42), but nothing in
`buildRequestBody()` ever marked any content as cacheable —
`grep -rn "cache_control"` across the whole provider layer returned
zero matches. Caching had never actually been active for Anthropic;
the response-parsing code existed but had never been exercised.

### Anthropic: real cache_control breakpoints

Researched Anthropic's current, published API shape directly (live
web fetch of `platform.claude.com/docs/en/build-with-claude/prompt-caching`)
rather than relying on possibly-stale training knowledge, since this
task explicitly asked for "Anthropic's real documented cache_control
API shape." Confirmed: no beta header required (generally available),
`cache_control: {type: "ephemeral"}` is the object shape, `system`
must be an array of content blocks to carry it (a plain string
cannot), and a tool-definitions breakpoint goes on the LAST tool in
the array — Anthropic caches everything up to and including a marked
block, not the block alone.

Implemented exactly that:

- `system` is now `[{type: 'text', text: $systemText, cache_control:
  {type: 'ephemeral'}}]` instead of a plain string.
- The last entry in `tools` gets `cache_control: {type: 'ephemeral'}`
  appended.

Both are this module's two largest, most static per-request blocks
(the system prompt is a fixed `const` string; tool definitions come
from a DI-built, fixed-insertion-order registry — see the OpenAI
section below for the full audit) — exactly what the task asked to
cache.

### A real, previously-latent bug found and fixed

Anthropic's real usage-reporting formula (confirmed via the same live
research) is:

```
total_input_tokens = input_tokens + cache_read_input_tokens + cache_creation_input_tokens
```

Three ADDITIVE, non-overlapping fields — genuinely different from
OpenAI's model, where `prompt_tokens_details.cached_tokens` is already
a SUBSET of `prompt_tokens`. The existing `parseUsage()` treated
Anthropic's raw `input_tokens` alone as the total and clamped
`cache_read_input_tokens` down to fit inside it
(`min($cachedTokens, $inputTokens)`). Once caching actually started
working, this would have massively UNDER-reported real cache hits — a
typical cache hit has a LARGE `cache_read_input_tokens` (the whole
cached system+tools prefix) alongside a SMALL `input_tokens` (just
this turn's new content), so the old clamp would have reported almost
none of the real benefit. This bug was never caught before because
caching was never active, so `cache_read_input_tokens` had always been
`0` in every real response — the path was simply never exercised.

Fixed to compute the real additive total:

```php
$inputTokens = $newInputTokens + $cacheReadTokens + $cacheCreationTokens;
$cachedTokens = $cacheReadTokens;
```

`cache_creation_input_tokens` (a cache WRITE, billed at a premium —
1.25x for the default 5-minute TTL — not a discount) is folded into
the normal-priced portion of the total rather than tracked as its own
third tier, since this module's `TokenUsage`/`CostCalculator` only
distinguish two tiers (normal vs. cached/cheap). A disclosed, bounded
simplification: a cache-write turn's real cost is billed here at 1.0x
when it's actually 1.25x on Anthropic's side — not silently wrong, a
known, minor underestimate on write turns specifically.

### OpenAI/xAI/local-compatible: confirmed deterministic, no code change

Dispatched a dedicated research pass over the full request-body-
construction path (tool registry → tool filtering → tool schemas →
system prompt → final JSON encoding) specifically to answer: will two
otherwise-identical requests produce byte-identical serialized
prefixes, which is what OpenAI's automatic prefix caching requires?

Findings, with file:line citations verified directly:

- `CommerceToolRegistry` is built entirely via `di.xml`'s fixed,
  ordered `<item>` list — insertion order is stable across requests
  and process restarts.
- `ToolCallingChatService`'s tool-filtering is a pure linear `foreach`
  with capability-flag gating — no sorting, no shuffling, no DB query.
- Every checked tool's `inputSchema()` is a fixed PHP array literal —
  no EAV/attribute-collection iteration anywhere in the chat-request
  path.
- The system prompt (`ResponseContractFormatter::INSTRUCTIONS`) is a
  literal `const` heredoc, always assembled first in message order.
- `json_encode()` is called with no `ksort`/`asort`/`uksort` anywhere
  in the path — PHP preserves associative-array insertion order by
  default, and this codebase adds no sorting.

No non-determinism risk was found. No code change needed — confirmed,
not assumed.

### Gemini: researched, scope correctly deferred

Researched (live web search + fetch of `ai.google.dev/gemini-api/docs/caching`)
before deciding scope, per the task's explicit instruction not to
guess. Gemini has two genuinely different mechanisms:

- **Implicit caching** — automatic for Gemini 2.5+ models, zero code
  required ("there is nothing you need to do to enable this," per
  Google's own docs), and already benefits from the same deterministic-
  request-shape work confirmed above for OpenAI.
- **Explicit caching** (the `cachedContents` API) — a genuinely
  separate, heavier, STATEFUL mechanism: create a cache resource
  explicitly, manage its own TTL/expiration, reference it by id in
  later requests, real storage costs. Not a simple per-request
  breakpoint.

Implicit caching needs nothing further from this module. Explicit
caching is correctly OUT of scope for this pass and flagged as a real
follow-up — implementing it would need new resource-lifecycle
infrastructure (tracking whether a cached resource is still valid,
when to recreate it, where its id lives) that doesn't exist anywhere
in this module yet, and would be disproportionate to bundle into this
task alongside everything else.

## Part B — "Token Optimization" admin toggle

### New config

`general/token_optimization_enabled` (Yes/No, default No) —
`GeneralConfigInterface::isTokenOptimizationEnabled()`.

### Audit before trimming (same discipline as prior tasks)

Read `LlmResponseSchema::schema()` directly before deciding what to
trim: it requests `message`, `product_skus` (sku + reason),
`follow_up_questions`, `actions` — no `category` property anywhere.
Cross-referenced every field `ProductContextFormatter` currently
sends against what's genuinely load-bearing:

- **SKU** — stays in both modes. `OutputValidator`'s `fabricated_sku`
  check is the real security boundary; it only accepts a SKU present
  in the formatted context.
- **Name** — stays in both modes. The model needs to know what the
  product IS to reason about fit at all.
- **Attribute label/values** — stay in both modes. This module's real
  matching signal for attribute-driven queries ("waterproof," "size
  medium").
- **Category names** — the one field genuinely not referenced by the
  response schema or any downstream validation. Dropped when the
  toggle is enabled.

### Implementation

`ProductContextFormatter::format(int $storeId, array $candidates)` —
gained a `$storeId` parameter (needed to read the toggle) and now
reads `readGeneral($storeId)->isTokenOptimizationEnabled()` once per
call, passing the result down into `formatCandidate()`. When enabled,
the "Categories: ..." part of each candidate line is omitted
entirely; everything else is byte-identical. Both real callers
(`ChatEntryPipeline::handle()`, `PlaygroundQueryRunner::run()`)
updated to pass `$storeId` through.

Toggling this is a clean, reversible gate: `OutputValidator`, live
revalidation, and price/stock/discount grounding all still run
identically either way — only the size of the context payload feeding
them changes.

## Files changed

- `Model/Provider/Llm/AnthropicProvider.php` — cache_control
  breakpoints on `system` and the last tool; corrected additive
  token-counting formula in `parseUsage()`
- `Model/Config/Path.php`, `etc/config.xml`, `etc/adminhtml/system.xml`,
  `Api/Config/GeneralConfigInterface.php`, `Model/Config/GeneralConfig.php`,
  `Model/Config/ConfigurationReader.php` — new
  `general/token_optimization_enabled` toggle
- `Model/Chat/ProductContextFormatter.php` — new `$storeId` param,
  config-gated category-name trimming
- `Model/Chat/ChatEntryPipeline.php`, `Model/Playground/PlaygroundQueryRunner.php`
  — updated `format()` call sites
- `CLAUDE.md` — extended the existing "Token efficiency" section with
  full implementation details (already had accurate high-level
  framing pre-written for this task)

New/updated tests:
- `Test/Unit/Model/Provider/Llm/AnthropicProviderTest.php` — 2 new
  tests (`testSystemPromptCarriesACacheControlBreakpoint`,
  `testLastToolDefinitionCarriesTheCacheControlBreakpointNotEveryTool`);
  3 pre-existing tests corrected to the real additive token formula
  (they had encoded the bug's expected — i.e. wrong — behavior)
- `Test/Unit/Model/Chat/ProductContextFormatterTest.php` — 2 new
  tests (toggle=No byte-identical to pre-task behavior; toggle=Yes
  measurably smaller with categories gone but SKU/Name/attributes
  intact); updated for the new constructor/method signature
- `Test/Unit/Model/Chat/ChatEntryPipelineTest.php`,
  `Test/Unit/Model/Playground/PlaygroundQueryRunnerTest.php` —
  updated for `ProductContextFormatter`'s new signature

## Verification — full test suite

**1750 tests / 4350 assertions / 0 failures** (1674/4027 unit +
76/323 integration; up from 1746/4335). `setup:di:compile` clean.
Whole-module `php -l` sweep clean.

## Verification — live, Part B

Ran the same real query ("waterproof jackets") against the real
indexed catalog (8 real candidates), in two SEPARATE PHP processes
(see the methodology note below):

```
toggle=No:  message length = 2468 bytes, "Categories:" present
toggle=Yes: message length = 2164 bytes, "Categories:" absent

Bytes saved: 304 (~12%)
```

**A real verification-methodology gotcha, caught and corrected**: an
initial single-process script that read the context, flipped the DB
config row, flushed the persistent cache, and read again in the SAME
process showed no difference at all. Magento's config reader caches
resolved values in-memory for the life of one PHP process/request;
flushing the persistent cache backend mid-process doesn't invalidate
values already resolved earlier in that same process. Corrected by
running the "before" and "after" reads as two genuinely separate
`bin/cli php ...` invocations (matching how two separate real HTTP
requests would actually behave) — this is now documented in CLAUDE.md
so a future session doesn't waste time on the same false negative.

All diagnostic config changes were restored to their original values
(`token_optimization_enabled` back to `0`) afterward.

## Verification — live, Part A (disclosed gap)

**Could not be performed.** Confirmed via a direct database check
before attempting: `ai_shopping_assistant/llm/api_key` is `NULL` and
`ai_shopping_assistant/llm/provider` is `openai_compatible`
(Local/Ollama) with no reachable base URL for a real call in this
environment. The one real, working key this session ever had (Google
Gemini, obtained in Task 42) is gone — apparently overwritten when the
store's provider selection was later switched to Local/Ollama.
Anthropic itself has never had a live key available in any session in
this module's history (Task 39's own original, still-accurate
disclosed limitation).

Without a working key for ANY provider, an actual cache-write-then-
cache-read round trip against a real server could not be demonstrated
this session. Part A is instead verified via:

1. Comprehensive, passing unit tests proving the exact request shape
   (`cache_control` present on `system` and only the last tool) and
   the corrected response-parsing formula.
2. Live, current research against Anthropic's own published API docs
   (not assumed from training data) confirming that shape is correct.

If a working provider key becomes available in a future session,
re-verify live per the exact steps CLAUDE.md's "Token efficiency"
section now documents: a first request should show
`cache_creation_input_tokens > 0` in the raw response; an immediately
repeated, identical request should show `cache_read_input_tokens > 0`
at a lower effective cost.

## Not done / blocked

Gemini's explicit `cachedContents` caching API — correctly deferred as
a follow-up (see the research summary above); implicit caching already
works automatically with no code needed. Part A's live round-trip
verification — blocked on no LLM provider currently having a working
API key in this environment, disclosed above with the exact evidence
and the exact re-verification steps for whenever a key is available.
