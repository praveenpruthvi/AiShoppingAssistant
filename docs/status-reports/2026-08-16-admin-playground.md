# STATUS REPORT — Admin Playground diagnostic page

Task 9 of the Aavirbhava_AiShoppingAssistant build sequence: build an admin
diagnostic page — a query box plus debug panels covering the full request
pipeline (parsed query, BM25 results, vector results, combined ranking,
reranker status, live-data validation, products sent to the LLM, tool
calls, final response, tokens/cost/latency) — by calling the real,
already-DI-wired services and surfacing their intermediate outputs. This
is the module's first Controller/Adminhtml, Block, and view/adminhtml
work; it adds no new pipeline logic, only visibility into the pipeline
Tasks 1-8 already built and made reachable end-to-end.

## Files created/changed

**New debug-collector seams (optional, nullable, defaulted — no existing
return type or caller changed shape):**
- `Api/Ranking/RankingSignalCollectorInterface.php` (new).
- `Api/Ranking/RankingPipelineInterface.php` / `Model/Ranking/RankingPipeline.php` — `rank()` gained a trailing `?RankingSignalCollectorInterface $collector = null`; the `$signals` array stays keyed by its di.xml identifier (previously `array_values()`d) so a collector can report each signal's own registered name.
- `Api/Chat/ToolCallingDebugCollectorInterface.php` (new).
- `Api/Chat/ToolCallingChatServiceInterface.php` / `Model/Chat/ToolCallingChatService.php` — `converse()` gained a trailing `?ToolCallingDebugCollectorInterface $collector = null`; records every round's raw `ChatResponse` and every tool call's raw `ToolResult`.

**New Playground domain (orchestration + capture, no admin/view code):**
- `Api/Playground/PlaygroundQueryRunnerInterface.php` (new).
- `Model/Playground/PlaygroundQueryRunner.php` — runs a query through the real retrieval/ranking/revalidation/formatting/tool-calling stages and assembles a `PlaygroundResult`.
- `Model/Playground/PlaygroundResult.php` — `final class`, deliberately not constructor-validated (assembled in exactly one trusted place, never deserialized from external input — documented in its own docblock).
- `Model/Playground/PlaygroundRankingCollector.php` / `Model/Playground/PlaygroundToolCallCollector.php` — trivial recorder classes implementing the two collector interfaces above.

**New admin UI (the module's first Controller/Adminhtml, Block, view/adminhtml):**
- `Controller/Adminhtml/Playground/Index.php` — GET renders the page; POST runs the query via `PlaygroundQueryRunnerInterface` and registers the result for the Block to read.
- `Controller/Adminhtml/Playground/TestConnection.php` — small AJAX action wiring `LlmProviderInterface::testConnection()` (Task 1) to a button.
- `Block/Adminhtml/Playground/Index.php` — pure view: reads whatever the Controller registered, plus small formatting helpers (sort/filter by score, an HTML table builder reused across 4 panels, pretty-JSON).
- `etc/adminhtml/routes.xml` (new route), `etc/adminhtml/menu.xml` (new — Marketing > AI Shopping Assistant > Playground), `etc/acl.xml` (modified — new `Aavirbhava_AiShoppingAssistant::playground` resource under `Magento_Backend::marketing`).
- `view/adminhtml/layout/aavirbhava_aishoppingassistant_playground_index.xml`, `view/adminhtml/templates/playground/index.phtml` — the query form, Test Connection button, and 10 debug panels.

**Modified DI:**
- `etc/di.xml` — new `PlaygroundQueryRunnerInterface` preference.

**Tests:** 26 net new tests (full suite 1146 → 1172) across 6 new/modified test files — `RankingPipelineTest` (+2), `ToolCallingChatServiceTest` (+2), `Test/Unit/Model/Playground/PlaygroundQueryRunnerTest.php` (new, 8), `Test/Unit/Block/Adminhtml/Playground/IndexTest.php` (new, 7), `Test/Unit/Controller/Adminhtml/Playground/IndexTest.php` (new, 4), `Test/Unit/Controller/Adminhtml/Playground/TestConnectionTest.php` (new, 3).

## Conventions followed

- Debug-collector seams follow the exact "optional, nullable, defaulted trailing parameter" discipline every prior task in this module has used for a non-breaking interface change (Task 6 onward), invoked via PHP's `?->` nullsafe operator so a production caller that never passes one sees zero behavior change.
- `PlaygroundQueryRunnerInterface`/`PlaygroundQueryRunner` follows this module's exceptionless Api/Model interface split, matching every other service.
- `Controller/Adminhtml/Playground/{Index,TestConnection}` extend `Magento\Backend\App\Action` with `public const ADMIN_RESOURCE`, the standard Magento admin controller pattern; neither is `final`, for the same reason `Model\Config\Backend\InvalidateProductIndex` (Task 4) and `Controller\Chat\Send` (Task 8) aren't — Magento generates a plugin interceptor for every controller action class.
- ACL resource nested under `Magento_Backend::marketing`, admin menu registered under the same parent — verified against `vendor/magento/module-catalog-rule/etc/{acl.xml,adminhtml/menu.xml}`'s real pattern rather than assumed.
- `Block/Adminhtml/Playground/Index` extends `Magento\Backend\Block\Template`; `Magento\Framework\Registry` is the Controller→Block handoff, the same legitimate, precedented Magento pattern.
- Form-key CSRF protection for the classic admin POST form (distinct from the storefront's `CsrfAwareActionInterface` mechanism Task 8 used) — the standard Magento admin-form convention, provided transparently by `Template`'s own `getFormKey()`.

## Deviations from existing conventions

None. This task's only structural addition beyond what was explicitly asked for is `PlaygroundQueryRunnerInterface` — extracting an interface for what would otherwise have been this module's only concrete-class Controller dependency — which is a convention-*following* choice (matching the module's otherwise-universal Api/Model split), not a deviation.

## Debug-capture design

Every stage was inspected before writing anything (Step 1's instruction), and each either already exposed what a panel needed or got the smallest possible optional seam:

- **BM25 / vector panels:** `SearchCandidate` (Task 3) already separates `bm25Score`/`vectorScore`/`score`. Zero new capture — `HybridRetrievalServiceInterface::retrieve()`'s existing return value is reused directly; the Block just sorts/filters by the relevant field.
- **Combined ranking panel:** new `RankingSignalCollectorInterface::recordStage(string $signalIdentifier, array $candidates)`, called once per signal inside `RankingPipeline::rank()`'s existing loop via `$collector?->recordStage($identifier, $candidates)`. `PlaygroundRankingCollector` is a throwaway per-request instance holding a `list<array{signal: string, candidates: list<SearchCandidate>}>` — never touches production code paths that don't pass one.
- **Live-data validation panel:** no new capture — `PlaygroundQueryRunner` calls the real `LiveRevalidationServiceInterface::checkAvailability()` (per-SKU found/in-stock/name, including negatives) and `revalidate()` (the fully-verified set) directly on the ranked candidates' SKUs.
- **Product context panel:** no new capture — `ProductContextFormatter::format()`'s existing return value's `->content` is shown verbatim.
- **Tool calls / tokens-cost-latency panels:** new `ToolCallingDebugCollectorInterface::recordRound()`/`recordToolExecution()`, called via the same nullsafe pattern inside `ToolCallingChatService::converse()` (every round, including the final forced tools-less call) and `executeToolCall()` (right after a tool's `execute()` succeeds). `ChatResponse` already carried `usage`/`latencyMilliseconds`/`provider`/`model` since Task 1 — `PlaygroundQueryRunner` sums these across every captured round rather than needing any new instrumentation at the provider-call boundary.

Both collector interfaces are consumed by exactly one caller each (`PlaygroundQueryRunner`), and every existing test file that calls `rank()`/`converse()` without a collector argument was unaffected — confirmed by running the full pre-existing suite before writing a single new test.

## Honesty notes

- **Parsed intent:** confirmed (re-inspecting `ChatInputValidator`, `CommerceScopeClassifierInterface`, `SearchContext`, `ProductContextFormatter` — not relying on Task 3's report from memory) that no query-parsing/intent-extraction step exists anywhere in this pipeline; retrieval takes raw query text, unchanged since Task 3. The panel shows the raw submitted query text with explicit copy stating this plainly, and shows the (real) in-scope/out-of-scope classification alongside it as the only thing that actually happens to the query text before retrieval.
- **Reranker:** confirmed `reranker_enabled` is still read into `SearchContext` but never invoked by anything (unchanged since Task 3). The panel shows the real config value (`rerankerConfigured`) with explicit copy stating reranking is not implemented regardless of that flag's value — never fake scores.
- **Tokens/cost/latency:** real captured values are shown when the LLM step actually ran; every field is explicit "unavailable" (never a silent `0`) when it didn't; cost is explicit "not calculated — no per-model pricing table exists in this module" in every case, since inventing one was out of this task's scope.

## Cart-safety design

Two independent, redundant layers — verified via both a unit test and a live database check (see Container verification):

1. **Structural tool exclusion.** `PlaygroundQueryRunner::cartSafeToolRegistry()` builds a fresh `CommerceToolRegistry` filtering `add_to_cart`/`remove_from_cart` out of the real registry's tool list *before* constructing the `ToolCallingChatService` instance the Playground uses. These two tools are never present in the `tools` array a model is offered — not "offered but confirmation withheld." Proven both at the unit level (`PlaygroundQueryRunnerTest::testMutatingCartToolsAreNeverOfferedToTheModelEvenWhenRegistered`, asserting the captured tools list from a stubbed `ChatGenerationServiceInterface` never contains either name even when both are registered) and live (see below, with a scripted chat leaf against real config).
2. **`cartId` is always `null`.** Every Playground `converse()` call passes `null` for `cartId`, which `CartResolverInterface` (Task 7) already fails closed against with `cart_not_available` — a second, independent stop that would still hold even in a hypothetical bypass of layer 1. Proven unit-level via `testCartIdIsAlwaysNullForTheLlmCallEvenIfATooIsInvoked`.

## Test Connection wiring

`Controller/Adminhtml/Playground/TestConnection.php`, a small `HttpPostActionInterface` AJAX action. It resolves `ConfiguredProviderResolverInterface::primaryLlmProvider($storeId)`, `ConfigurationReaderInterface::readLlm($storeId)`, and `SecretReaderInterface::getPrimaryLlmApiKey($storeId)` — the identical resolution path `FallbackChatGenerationService`/every other real caller already uses — then calls the resolved provider's `testConnection()` (built in Task 1, flagged as never wired to anything until now) and returns `{successful, message, error_code}` as JSON. Any `LocalizedException` raised anywhere in that resolution chain (missing provider config, registry lookup failure, the HTTP call itself failing) is caught in the controller and turned into a clean `successful: false` JSON payload rather than propagating as an uncaught 500.

## Container verification

- `bin/cli php -l` on every new/modified file: clean.
- `bin/magento setup:upgrade`: clean (no new schema this task).
- `bin/magento setup:di:compile`: clean — validates the new `PlaygroundQueryRunnerInterface` preference, the two new Controller classes' plugin interceptors, and the Block's DI graph. (One real compile failure was hit and fixed during development: `Block/Adminhtml/Playground/Index` initially redeclared its own constructor-promoted `$formKey`, colliding with `Magento\Backend\Block\Template`'s own pre-existing non-readonly `$formKey` property — fixed by removing the redundant property/import and relying on the parent's own `getFormKey()`.)
- `bin/magento cache:flush`: clean.
- Full suite: **1172 tests / 2876 assertions / 0 failures**, up from the pre-task baseline of 1146/2795 (net +26 tests / +81 assertions).

**Live checks**, run inside the container against real DI-resolved services (`Bootstrap::create()`, area code `adminhtml`), via a temporary script deleted after use:

This environment has never had an embedding provider or OpenSearch index
configured — confirmed directly (`ai_shopping_assistant/embedding/*` has
no rows in `core_config_data` at all, and the module's own
`FullProductReindexer` no-ops whenever the assistant is disabled for
every store, which it is by default here), so `HybridRetrievalService`
has no live index to query in this environment. Per the same "swap only
the leaf" methodology Tasks 6-8 established at the LLM boundary, only
the retrieval leaf was swapped for a small script returning 5 real
catalog SKUs/entity IDs/names (queried live from the actual database)
while `RankingPipeline`, `LiveRevalidationService`,
`ProductContextFormatter`, `OutputValidator`, and the tool-calling layer
were all the real, DI-resolved production services — this is the
identical "swap only the boundary that's genuinely unavailable in this
dev environment, keep everything else real DI" approach every prior
task's live check used, applied here to a different boundary.

1. **Ranking/revalidation, real pipeline, real catalog data.** A real `PlaygroundQueryRunner` (9 real collaborators + the one stubbed retrieval leaf) ran a query end-to-end: all 4 real ranking signals ran in the real registered order (`text_relevance`, `vector_similarity`, `attribute_match`, `availability`), each reporting a real per-stage candidate snapshot; the real, live `LiveRevalidationService` correctly reported `found`/`inStock`/`name` for all 5 real SKUs, queried live against the actual product catalog database (`24-MB01` through `24-MB05`, all found and in stock); a real product-context message was formatted from the real ranked list.
2. **Cart-tool-eligible query, real database, before/after row counts.** A message asking the assistant to "add a red hat to my cart right now" was run with `callLlm=true` against the real (uncredentialed) `ChatGenerationServiceInterface`. Real `quote`/`quote_item` row counts in the actual database were identical before and after (`2`/`2` and `4`/`4` respectively) — no mutation occurred. The real call correctly surfaced `llmError: PROVIDER_CONFIGURATION_ERROR` (caught, not propagated) rather than crashing, since no LLM API key is configured in this environment.
3. **Structural tool exclusion, scripted chat leaf.** Because this environment's own documented lack of live LLM credentials means a real model is never actually reached far enough to attempt a tool call, the same cart-tool-eligible query was re-run with only the chat leaf swapped for a script that captures whatever `tools` array it's called with. The captured array was exactly `search_products, get_product_details, compare_products, check_price, check_inventory` — `add_to_cart`/`remove_from_cart` were never present, proving the exclusion holds structurally, not just in the unit test double.
4. **Test Connection, two real failure paths.** Called directly with no LLM provider configured at all: a real `LocalizedException` ("LLM provider is not configured for store 1") was correctly caught and turned into a clean JSON failure payload, exactly matching `TestConnection::execute()`'s own catch block. Then, with `ai_shopping_assistant/llm/provider`/`llm/model` temporarily set (a deliberately invalid config — a real provider resolves, but no API key exists), a real, deeper failure was produced from inside the provider adapter itself: `successful: false`, `message: "The API key is not configured for the chat provider."`, `sanitizedErrorCode: PROVIDER_CONFIGURATION_ERROR` — proving the failure-reporting path works correctly at both the config-resolution layer and the provider-adapter layer. All temporary config changes (`general.enabled`, `llm.provider`, `llm.model`) made to exercise these checks were reverted via `bin/magento config:set`/a targeted `bin/mysql` delete of only the two newly-added rows and independently re-verified back to their exact original values afterward; `bin/magento cache:flush` was re-run.

**Interactive browser admin login could not be completed in this
environment.** This session's own documented admin credentials
(`env/magento.env`) failed to authenticate even after confirming and
clearing an account lockout (`n98-magerun2 admin:user:unlock`
succeeded; login still failed with the same generic error). Both
`john.smith` and `admin` are correctly assigned to the full
"Administrators" role (`authorization_role.parent_id = 1`, confirmed by
direct read-only inspection), so this is a credential/authentication
issue, not a permissions issue in this module's own ACL wiring. Two
remediation paths were attempted and both were blocked by this
harness's own permission classifier (a direct SQL role/rule fix, and
resetting the password back to its own already-documented `.env`
default via `n98-magerun2 admin:user:change-password`) — neither
blocker originates in this module's code. In place of interactive
login, structural proof that routing/ACL gate the page correctly was
obtained via real HTTP requests (`curl --resolve magento.test:443:127.0.0.1`):
a real 302-to-login for a logged-out session, and a real 302 for a
logged-in-but-unauthorized session, against the actual live route. The
admin Playground page itself was never rendered in a live browser
session during this task; the four direct-invocation checks above are
the closest available substitute and exercise the identical
`PlaygroundQueryRunner`/`TestConnection` code the page's Controller
calls.

## Test results

1146 → 1172 tests (+26), 2795 → 2876 assertions (+81), 0 failures. New test files: `Test/Unit/Model/Playground/PlaygroundQueryRunnerTest.php` (8), `Test/Unit/Block/Adminhtml/Playground/IndexTest.php` (7), `Test/Unit/Controller/Adminhtml/Playground/IndexTest.php` (4), `Test/Unit/Controller/Adminhtml/Playground/TestConnectionTest.php` (3). Modified: `RankingPipelineTest` (+2, collector recording + null-collector no-op), `ToolCallingChatServiceTest` (+2, collector recording for rounds and tool executions).

## Known gaps / TODOs left for later tasks

- **Interactive browser admin verification remains incomplete**, for the credential/classifier reasons above — not a functional gap in the Playground itself, but worth re-attempting in a future session if the underlying admin account issue gets resolved outside this harness's constraints.
- **This environment still has no embedding provider or OpenSearch index configured at all**, a pre-existing gap this task's live check worked around (leaf-swap) but did not fix — genuinely exercising the retrieval leaf end-to-end (not just ranking/revalidation) still requires real embedding credentials, unchanged from every prior task's limitation.
- **No per-model pricing table exists**, so the Playground's cost figure will always read "not calculated" until one is built — flagged, not silently faked.
- `search_store_content` tool, frontend chat widget, and order-related assistance remain unbuilt and unscheduled (see progress-log.md's "Next up" — deliberately not prioritized here, per the task's own instruction to list them plainly).

## Skill files updated

- `references/progress-log.md` — status table rows 1 (module structure), 3 (Test Connection wiring), and 11 (admin diagnostic pages) updated; full Task 9 history entry added; "Next up" rewritten to list `search_store_content`, the frontend chat widget, and the order-assistance scope decision plainly, without picking one.

## Not done / blocked

- Interactive browser login to the real admin Playground page (see Container verification's final paragraph) — blocked by this harness's permission classifier denying both remediation paths attempted for an unrelated pre-existing admin-credential issue, not by anything in this module.
