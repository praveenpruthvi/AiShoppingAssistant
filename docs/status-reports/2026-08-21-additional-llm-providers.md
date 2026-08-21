# STATUS REPORT — Add Anthropic (Claude), xAI (Grok), and Google (Gemini) as selectable LLM providers

Added 3 new `LlmProviderInterface` adapters and registered them in the
existing DI provider registry, so both the Primary LLM and Fallback LLM
admin dropdowns now list 5 providers (OpenAI, Anthropic Claude, xAI
Grok, Google Gemini, Local/Ollama-compatible) with no `system.xml`
change needed — both dropdowns already derive their options from that
registry via the existing `Model\Config\Source\Provider`.

Scope was explicitly confirmed with the user before starting: all
three providers, built to spec against each one's real documented API,
with no live API key available in this session to exercise a real
call — disclosed here and in every new class's own docblock.

**Follow-up (same task, user-requested continued validation):** with
no real API key becoming available for any of the three providers, the
user explicitly chose to strengthen the existing mocked test suites
rather than leave them at their original, first-pass coverage — see
the expanded "Verification — full test suite" section below for what
was added and why each case is a real, named provider behavior, not an
arbitrary addition.

## Files

New: `Model/Provider/Llm/{XaiProvider,AnthropicProvider,GeminiProvider,
HttpStatusMapper}.php`, plus matching test files
(`Test/Unit/Model/Provider/Llm/{XaiProviderTest,AnthropicProviderTest,
GeminiProviderTest,HttpStatusMapperTest}.php`).

Modified: `Model/Provider/ProviderIdentifiers.php` (new `LLM_GOOGLE`
constant), `etc/di.xml` (3 new registry entries + a `google` label —
`anthropic`/`xai` labels already existed, pre-declared ahead of an
implementation), `Test/Unit/Model/Provider/ProviderIdentifiersTest.php`.

No new admin config field: one shared `llm/api_key`/`fallback/api_key`
field already covers whichever provider is selected in that role.

## Key decisions

- **xAI reuses `AbstractChatProvider` unchanged** — its API is
  genuinely OpenAI-SDK-compatible (same `/chat/completions` shape,
  `Authorization: Bearer` auth, the older `max_tokens` field name).
  `ChatEndpointPolicy`'s existing cloud-endpoint branch already covers
  it with zero code change.
- **Claude and Gemini implement `LlmProviderInterface` directly** —
  their wire formats differ from OpenAI's in load-bearing ways
  (different turn roles, tool calls/results represented as content
  blocks or by function name rather than OpenAI's `tool_calls`
  array + dedicated `tool` role, system prompt as a separate top-level
  field, model-in-URL-path for Gemini) that would have needed enough
  conditionals in the shared OpenAI-shaped builders to defeat the point
  of sharing them.
- **Gemini's tool-result-by-name resolution**: Gemini's
  `functionResponse` has no call-id concept — only a function name.
  `GeminiProvider` resolves the real name from the actual `ToolCall`
  objects already present earlier in the same request's own message
  history, never by parsing or guessing from the opaque id string. A
  synthesized id (`gemini-call-<index>`) covers this module's own
  internal round-tripping only — never sent back to Gemini.
- **New shared `HttpStatusMapper`** extracts the HTTP-status-to-
  exception mapping `AbstractChatProvider`'s own (untouched, private)
  logic already applies, so Claude/Gemini map transport failures onto
  the same exception hierarchy `FallbackEligibilityPolicy` recognizes
  — a new, additional call site, not a refactor of already-tested code.
- **Capabilities reported honestly**: Claude has no native
  `response_format`/JSON-schema field in its stable API, so
  `structuredOutput: false` — this module's existing prompt-based
  `ResponseContractFormatter` retry mechanism carries compliance for
  it, unchanged. Gemini genuinely supports `generationConfig.
  responseSchema`, so `structuredOutput: true` and a provided schema is
  actually forwarded, not just claimed.

## Verification — full test suite

**1615 tests / 3897 assertions / 0 failures** (up from 1549/3735 before
this task; 1596/3868 at this task's original first pass) — 67 new
tests total across the three new provider adapters, covering
endpoint/header/auth shape, request-body mapping for every role
(system extraction, tool-call/tool-result round-tripping, Gemini's role
renaming and by-name tool-result resolution), response parsing (text,
tool calls, provider-specific usage/caching field names), and the full
HTTP-status/transport-failure/fail-closed-config matrix already proven
for `OpenAiProvider`. A whole-module `php -l` sweep (646 files) and
`setup:di:compile` are both clean.

### Expanded coverage (follow-up round, 16 new tests)

With no live key available, the follow-up round targeted specific,
*named* real-provider behaviors from each API's own documentation that
the first pass hadn't exercised yet — not arbitrary additional cases:

- **`XaiProviderTest`** (4→8 tests): rate-limit status mapping,
  cloud-only base-URL rejection, a real tool-call round trip through
  xAI's own endpoint/headers (not just assumed via
  `AbstractChatProvider` inheritance), and `response_format`/
  JSON-schema forwarding matching the `structuredOutput: true`
  capability this adapter claims.
- **`AnthropicProviderTest`** (17→23 tests): **multiple `tool_use`
  blocks in one response** (a real, documented case — the model
  requesting several tools in one turn, e.g. `check_price` +
  `check_inventory` together) all becoming separate `ToolCall`s, not
  just the first; **multiple text blocks concatenated** (real when text
  is interleaved with `tool_use` blocks); **`stop_reason: "max_tokens"`**
  — a normal truncation outcome, not an error, still returns the
  truncated text (proves this class's own deliberate choice not to
  branch on `stop_reason` for success/failure is correct for this named
  case); **`cache_creation_input_tokens` vs `cache_read_input_tokens`**
  — Anthropic's real prompt-caching usage shape can carry both in the
  same response, and only a cache *read* is a genuine cost discount
  this module's `cachedInputTokens` should reflect — a cache *write*
  must never be conflated with one; no-system-message correctly omits
  the `system` field entirely; a `tool_use` block missing its `id` is
  rejected.
- **`GeminiProviderTest`** (20→26 tests): **`finishReason: "MAX_TOKENS"`**
  — the same real truncation-is-not-an-error case as Anthropic's
  `max_tokens`; **`finishReason: "RECITATION"`** — a real, documented,
  distinct-from-`SAFETY` finish reason, proving this class's own
  deliberate choice to map only the one well-documented `SAFETY` signal
  (not guess at others) behaves correctly — it falls through to the
  normal empty-content rejection rather than a fabricated refusal;
  multiple text parts concatenated; missing `usageMetadata` defaults to
  zero usage rather than failing; **only `candidates[0]` is used when
  Gemini returns several** (real, happens when `candidateCount` is
  raised above its default of 1 — this module never requests more than
  one); a `functionCall` missing its `name` is rejected.

## Verification — real DI-resolved wiring (not a live provider call)

Constructed all 5 registered LLM providers through the real, compiled
container and confirmed each resolves with the correct identifier and
capabilities. Separately confirmed the real `Model\Config\Source\
Provider` — the actual source model both admin dropdowns use — lists
all 5 with correct labels:

```
value=anthropic          label=Anthropic Claude
value=google              label=Google Gemini
value=openai              label=OpenAI
value=openai_compatible   label=Local / Ollama (OpenAI-Compatible)
value=xai                 label=xAI Grok
```

This proves the wiring/registration/admin-selectability chain is
genuinely correct end-to-end. **It does not substitute for an actual
authenticated call to any of the three new providers' real APIs**,
which this session had no key to make.

## Skill files updated

`references/progress-log.md` — header summary replaced, this task's
history entry added. `CLAUDE.md`'s existing "Everything is
provider-agnostic..." rule already covered this without a wording
change, since it was already written generically.

## Not done / blocked

Live verification against a real Anthropic, xAI, or Google API key —
explicitly out of scope by the user's own choice, not an oversight. A
future task with real credentials for one or more of these providers
should exercise a real `chat()` call (and ideally a real tool-calling
round trip, the most protocol-divergent part of each new adapter)
before any of them is treated as production-verified rather than
built-to-spec.
