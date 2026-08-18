# STATUS REPORT — Ollama / OpenAI-compatible LLM provider

Task 13 of the Aavirbhava_AiShoppingAssistant build sequence: a local
Ollama LLM adapter — generically an "OpenAiCompatibleProvider" per
architecture.md's original design, scoped to any server speaking OpenAI's
`/v1/chat/completions` wire format (Ollama, vLLM, llama.cpp, LM Studio),
selectable as either the primary or fallback LLM provider.

## Files created/changed

**New:**
- `Model/Provider/Llm/ChatEndpointPolicy.php` — endpoint resolution policy (cloud vs. local), mirrors the embedding side's `ProviderEndpointPolicy`.
- `Model/Provider/Llm/AbstractChatProvider.php` — shared chat-adapter pipeline (validation, HTTP call, status mapping, response/tool-call/usage parsing, `testConnection()`), mirrors `AbstractEmbeddingProvider`.
- `Model/Provider/Llm/OpenAiCompatibleProvider.php` — the new adapter itself.
- `Test/Unit/Model/Provider/Llm/{ChatEndpointPolicyTest,OpenAiCompatibleProviderTest}.php`.

**Modified:**
- `Model/Provider/Llm/OpenAiProvider.php` — refactored to extend `AbstractChatProvider`; observable behavior unchanged (all 23 pre-existing tests pass without modification to their assertions).
- `etc/di.xml` — `openai_compatible` registered in `LlmProviderRegistry`'s array (its admin label already existed, added ahead of time in Task 1).
- `Test/Unit/Model/Provider/Llm/OpenAiProviderTest.php` — its two direct-construction call sites updated for the new `ChatEndpointPolicy` constructor argument.

**Tests:** 16 net new tests (full suite 1207 → 1223).

## Conventions followed

Mirrors the embedding side's already-proven `ProviderEndpointPolicy`/`AbstractEmbeddingProvider` split exactly. Exception taxonomy unchanged — no new hierarchy, every failure mode maps into the existing `Provider*Exception` classes Task 1 established. `SecretValue`/config-reading/DI patterns all match existing precedent. `ProviderCapabilities`'s existing `apiKeyOptional`/`configurableBaseUrl` fields (present but unused by any provider until now) are exactly what this task needed.

## Deviations from existing conventions

One, explained in full below: extracting `ChatEndpointPolicy`/`AbstractChatProvider` from `OpenAiProvider`. This isn't a deviation so much as fulfilling a deferral Task 1's own report stated explicitly ("only one adapter exists — premature") now that its stated trigger condition (a second adapter) is met.

## Ollama API integration design

**Endpoint chosen:** the OpenAI-compatible `/v1/chat/completions` endpoint, not Ollama's native `/api/chat` + a custom translation layer. Verified against Ollama's current documentation before writing any code (not assumed from memory): the compatible endpoint documents support for `tools` and `response_format` in the standard OpenAI shape, and this was independently confirmed by a real live test (see Container verification) — a real tool call was correctly parsed from a real Ollama response, and a real `response_format` JSON-schema request produced valid schema-matching JSON. Given the shape genuinely matches, `AbstractChatProvider`'s shared request/response logic (inherited from `OpenAiProvider` via the new extraction) applies unmodified.

**One confirmed real wire difference:** the max-output-tokens field name. OpenAI's current API uses `max_completion_tokens` (what `OpenAiProvider` already sends); Ollama's compatibility layer documents and, per a live test, actually honors the older `max_tokens` field — an open, unresolved upstream GitHub issue (ollama/ollama#7125) tracks `max_completion_tokens` support. `OpenAiCompatibleProvider::maxOutputTokensField()` returns `'max_tokens'` for exactly this reason; everything else in the request body is shared/unmodified.

**Tool-calling support:** confirmed genuinely working, not just documented, via a real live call — but model-dependent on Ollama's side. A tool-capable model (`qwen3.5`) correctly returned a real, parseable tool call; a model without tool support (`tinyllama`) was rejected by Ollama itself (a real HTTP 400 with the message "does not support tools"), which this provider's existing generic status-code handling already maps to `ProviderInvalidResponseException` — the identical fallback Task 1 established for any unlisted 4xx status, not a new gap.

## Config wiring

Confirmed selectable as both primary and fallback with **zero new config fields**. `llm/{provider,api_key,model,base_url,timeout_seconds}` and `fallback/{provider,api_key,model,base_url,timeout_seconds}` were already fully generic (never hardcoded to OpenAI), and both `provider` dropdown fields already share one source model, `Model\Config\Source\Provider`, which derives its option list directly from `LlmProviderRegistryInterface::all()`. Registering `OpenAiCompatibleProvider` in `etc/di.xml` was the entire config-wiring task — confirmed live: a real, DI-resolved call to `Provider::toOptionArray()` inside the container returned `openai => OpenAI` and `openai_compatible => OpenAI-Compatible`, the exact list both admin dropdowns render from.

## Container verification

**A real local Ollama instance genuinely exists and was genuinely tested — with an honest caveat about where from.** This host runs a real `ollama serve` process with 3 real pulled models (`qwen3.5`, `tinyllama`, `nomic-embed-text`). It is bound to `127.0.0.1:11434` only (confirmed via `ss -tlnp` on the host) — genuinely unreachable from inside any docker-magento container: `host.docker.internal` doesn't resolve on this Linux Docker setup, and the network gateway IP gets a real connection-refused (the bind restriction itself, not a routing problem).

Rather than settling for a fully mocked/scripted boundary, the real, unmodified provider classes were loaded directly via Magento's own composer autoloader and run from the **host's** PHP CLI directly against the **real** running Ollama instance — genuinely live, just executed from outside a container rather than inside one:

- `testConnection()` and a plain `chat()` call both succeeded against `tinyllama` with real response text, real token usage, and real latency.
- A `chat()` call with a real tool definition against `qwen3.5` returned a real, correctly-parsed tool call (`check_price` with `{"sku":"ABC-123"}`) — tool-calling genuinely works through this endpoint.
- A `chat()` call with a `response_format` JSON schema against `tinyllama` returned valid JSON exactly matching the schema — structured output (Task 4's whole response-contract design) works too.
- Two real, non-bug findings surfaced (detailed under Known gaps): a reasoning model's reasoning tokens can consume a small output budget before any visible content appears (confirmed not a bug — the same model succeeded once given a larger budget); a model without tool support is cleanly rejected by Ollama itself and mapped to the existing generic exception, not a crash.

Separately, **from inside** the docker-magento container, a real DI-resolved `openai_compatible` provider's `testConnection()` against the container-reachable-but-Ollama-unreachable address correctly reported a clean `PROVIDER_TRANSPORT_ERROR` failure rather than crashing — proving the full admin-config-to-provider wiring works end-to-end even for the one hop this environment's host configuration cannot actually complete. This mirrors Task 9's own "deliberately unreachable, to prove failure reporting works" methodology.

`php -l`, `setup:upgrade`, `setup:di:compile`, `cache:flush` all clean (one incidental fix needed: `magento-db-1` had again lost its Docker network attachment between sessions, the same class of issue Tasks 6-7 documented — fixed identically and non-destructively via `docker network connect`).

## Test results

1207 → 1223 tests (+16), 2943 → 2978 assertions (+35), 0 failures. New: `ChatEndpointPolicyTest` (6), `OpenAiCompatibleProviderTest` (10). `OpenAiProviderTest`'s 23 pre-existing tests pass with zero assertion changes, confirming the refactor preserved `OpenAiProvider`'s exact observable behavior.

## Known gaps / TODOs left for later tasks

- `testConnection()`'s fixed 16-token budget (shared, unchanged) can look like a false-negative empty response against a reasoning-style local model before it emits visible content — a genuine, live-discovered characteristic of reasoning models in general, not fixed here (a bigger fixed number isn't a principled fix, since a reasoning model can always need more).
- Ollama's own `reasoning` response field (distinct from `content`) is not read or exposed anywhere — out of this task's scope, but worth knowing about for a future task that might want to surface model "thinking" output.
- Tool-calling support is genuinely model-dependent on the Ollama side; this module has no way to warn an admin ahead of time that their configured local model doesn't support tools before a real customer conversation hits that gap and gets a safe fallback response.

## Skill files updated

- `references/progress-log.md` — status row 2 updated; full Task 13 history entry added; "Next up" left unchanged per this task's own instruction (residual gaps / Phase 2 decision still pending).

## Not done / blocked

Nothing blocked. Anthropic/xAI adapters remain unbuilt, unchanged from every prior task's gap list — out of this task's scope.
