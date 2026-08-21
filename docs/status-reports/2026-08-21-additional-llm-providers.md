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

**1596 tests / 3868 assertions / 0 failures** (up from 1549/3735) — 51
new tests covering endpoint/header/auth shape, request-body mapping for
every role (system extraction, tool-call/tool-result round-tripping,
Gemini's role renaming and by-name tool-result resolution), response
parsing (text, tool calls, provider-specific usage/caching field
names), and the full HTTP-status/transport-failure/fail-closed-config
matrix already proven for `OpenAiProvider`. A whole-module `php -l`
sweep (646 files) and `setup:di:compile` are both clean.

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
