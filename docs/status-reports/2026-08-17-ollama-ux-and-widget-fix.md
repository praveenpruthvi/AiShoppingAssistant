# STATUS REPORT — Ollama admin UX + storefront widget visibility fix

Two fixes: (A) clearer Local/Ollama admin labeling plus a real "Fetch
Ollama Models" admin action populating the Model field from a configured
Ollama server's actually-pulled models; (B) diagnosing and fixing why the
storefront chat widget wasn't appearing despite `general.enabled = Yes`.

## Files created/changed

**Part A — new:**
- `Api/Provider/OllamaModelListServiceInterface.php`
- `Model/Provider/Llm/OllamaModelListResult.php` — success/failure value object, mirrors `ConnectionResult`.
- `Model/Provider/Llm/OllamaModelListService.php` — calls Ollama's native `GET /api/tags`.
- `Controller/Adminhtml/System/Config/FetchOllamaModels.php` — AJAX action, mirrors Task 9's `TestConnection.php` shape.
- `Block/Adminhtml/System/Config/OllamaModelField.php` — `frontend_model` appending the "Fetch Ollama Models" button + `<datalist>` + JS.
- 5 new test files (`OllamaModelListServiceTest`, `FetchOllamaModelsTest`, `OllamaModelFieldTest`, plus the interface/DTO covered by those).

**Part A — modified:**
- `etc/di.xml` — the `openai_compatible` label text changed; new `OllamaModelListServiceInterface` preference.
- `etc/adminhtml/system.xml` — `<frontend_model>` + clarifying comments on `llm/model` and `fallback/model`.

**Part B — no production files changed.** The root cause was a config data-state issue, not a code defect (see below); the fix was a corrected config value, not a code change.

**Tests:** 15 net new tests (full suite 1223 → 1238).

## Conventions followed

Part A mirrors established patterns throughout: `ConnectionResult`'s success/failure DTO shape, Task 9's `TestConnection` controller shape exactly, this module's universal `final class` + Api/interface split, and — critically — matched the module's *total absence* of ui_component/knockout admin-form customization by using the same plain-jQuery-AJAX-button pattern Task 9 already established, rather than introducing a heavier dependency for one field.

## Deviations from existing conventions

None.

## Part A design

**Label:** `di.xml`'s `ProviderLabelRegistry` array entry for `openai_compatible` changed from `"OpenAI-Compatible"` to `"Local / Ollama (OpenAI-Compatible)"` — display text only, confirmed to appear nowhere else (checked every file referencing the string first), no data migration needed since the underlying identifier is untouched.

**Model-fetch mechanism:** Confirmed by inspection that this module has zero existing dependent-admin-field/dynamic-select precedent — the only admin JS anywhere is Task 9's plain jQuery Test Connection button. Matched that exactly: a `frontend_model` block (`OllamaModelField`) appends a "Fetch Ollama Models" button and an HTML5 `<datalist>` bound to the existing Model text input via its `list` attribute, rather than replacing the field with a real `<select>` (which Magento config fields don't support natively without much heavier customization, and which would lose the ability to type a model name not yet in the fetched list). Clicking the button reads the *live, possibly-unsaved* sibling Base URL field's value via jQuery, POSTs it to the new controller, and populates the datalist with whatever came back — free-text entry into the Model field is never disabled, only augmented.

`OllamaModelListService` calls Ollama's own native `GET /api/tags` (not the OpenAI-compatible endpoint the chat providers use — `/api/tags` is Ollama-specific, not a capability every OpenAI-compatible server shares, so it stays a separate, Ollama-scoped class rather than folded into `OpenAiCompatibleProvider`). Since `llm/base_url`/`fallback/base_url` store the OpenAI-compatible chat prefix (e.g. `http://localhost:11434/v1`), the service strips a trailing `/v1` before appending `/api/tags`. Every failure mode (missing/invalid URL, unreachable server, non-2xx status, malformed body) reports through `OllamaModelListResult::failure()` with a clean message — never thrown, never leaking the configured URL or a raw exception message. Zero models pulled is reported as an honest *success* with an empty list, not an error, matching the task's own explicit instruction.

## Part B root cause

**A config-scope data-state issue, not a code bug.** `ChatWidget::isAssistantEnabled()` / `ConfigurationReader::readGeneral($storeId)` were confirmed — by inspection and by a real live test — to correctly read the store-view-scoped *effective* value of `general.enabled`, exactly as every other store-scoped config read in this module already does. The actual cause: `core_config_data` had a stale `general/enabled = 0` row at store-view scope (scope=`stores`, scope_id=1), left behind by this session's own repeated `bin/magento config:set ... --scope=stores --scope-code=default` test-and-revert cycles across Tasks 9, 11, and 12 — each of those tasks temporarily enabled the assistant for a live check, then reverted it, always at that same store-view scope. That store-view override silently took precedence over the `default`-scope value of `1` the merchant had set via the real admin UI, per Magento's completely standard store-view > website > default fallback — the classic "I set it but it doesn't apply" scope mismatch, not a defect anywhere in `ChatWidget`'s own logic.

Diagnosed methodically per the task's own checklist, in order, not stopped at the first plausible theory:
1. **Widget in generated layout at all?** Confirmed present once the effective config was corrected — the underlying `before.body.end` container wiring was never broken.
2. **`view/frontend/layout/default.xml` picked up, container exists in Luma?** Confirmed fine — the widget renders in exactly the right position (immediately before `</body>`), matching Magento's own `absolute_footer` block's use of the same container.
3. **Deploy mode / static-content:deploy needed?** `developer` mode — static assets already serve live with real 200s, not the cause.
4. **Cache staleness?** Flushed as routine hygiene; not the actual cause — the underlying config was genuinely disabled, not merely cached-stale.
5. **Scope mismatch?** **This was it** — confirmed conclusively (see Container verification).
6. **Silently swallowed exception?** Checked `exception.log`/`system.log` — no `ChatWidget`-related entries. Its own defensive try/catch never triggered; `isAssistantEnabled()` correctly (not exceptionally) evaluated to `false`.

## Part B fix

Corrected the store-view-scope `general/enabled` row to `1`, matching both the `default`-scope value and the merchant's own evident intent (rather than deleting the row to fall back to "use default," which the admin UI represents differently than an explicit store-view choice). **This fix was left in place, not reverted** — unlike every prior task's temporary test-and-revert config toggle, correcting this value *is* the fix; reverting it would re-introduce the exact bug.

## Container verification

`php -l`, `setup:upgrade`, `setup:di:compile`, `cache:flush` all clean.

**Part A**, verified two ways:
1. **Inside the container:** a real DI-resolved `Model\Config\Source\Provider` confirmed the dropdown now returns `openai_compatible => Local / Ollama (OpenAI-Compatible)`; a real DI-resolved `OllamaModelListServiceInterface` correctly reported a clean failure (`Unable to reach the Ollama server.`) against the container-unreachable address — the same "deliberately unreachable, to prove failure reporting works" methodology Tasks 9/13 used.
2. **From the host:** the real, unmodified `OllamaModelListService` was run directly (same technique Task 13 used for the same reason — Ollama is bound to `127.0.0.1` on the host, unreachable from inside any container here) against the actual running Ollama instance, and correctly returned the 3 real pulled models (`qwen3.5:latest`, `nomic-embed-text:latest`, `tinyllama:latest`), both with and without a trailing `/v1` on the base URL (proving the stripping logic), and correctly reported failure against a genuinely closed port.

**Part B**, verified directly: before the fix, a real `curl` against the live storefront homepage contained zero widget markup despite `general.enabled = Yes` at default scope — reproducing the user's exact report. After correcting the store-view override and flushing cache, the same real homepage request contains the widget's HTML in the correct position and both JS assets (`chat-widget-core.js`, `chat-widget-luma.js`) resolve with real HTTP 200s.

## Test results

1223 → 1238 tests (+15), 2978 → 3006 assertions (+28), 0 failures. New: `OllamaModelListServiceTest` (9), `FetchOllamaModelsTest` (3), `OllamaModelFieldTest` (3).

## Known gaps / TODOs left for later tasks

None newly introduced. Worth flagging for future live-check work in this module: a test-and-revert config toggle at store-view scope should restore the *exact* prior scope/value pair it found rather than a hardcoded assumption of "the original value" — this session's own methodology across Tasks 9/11/12 is what produced Part B's stale row, even though no single one of those tasks' own reports was inaccurate about what it reverted to at the time.

## Skill files updated

- `references/progress-log.md` — status rows 2 and 12 updated; full Task 14 history entry added covering both parts.

## Not done / blocked

Nothing blocked.
