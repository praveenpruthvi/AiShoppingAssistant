# STATUS REPORT — Output Validator + response contract + live revalidation

Task 4 of the Aavirbhava_AiShoppingAssistant build sequence: fix the
`ProductIndexMapping` bug found during Task 3's live verification, then
build live revalidation, the structured response contract, and the Output
Validator that gates everything before it can reach a customer.

## Files created/changed

**Step 0 — index mapping fix:**
- `Model/Indexing/Mapping/ProductIndexMapping.php` — removed the redundant field-level `space_type` key on the `embedding` field (kept only `method.space_type`).
- `Test/Unit/Model/Indexing/Mapping/ProductIndexMappingTest.php` — updated the assertion to lock in the fix (`assertArrayNotHasKey('space_type', $embedding)`).

**New module dependencies (for live Magento product/stock/customer-group access):**
- `etc/module.xml`, `composer.json` — added `Magento_CatalogInventory`/`magento/module-catalog-inventory` and `Magento_Customer`/`magento/module-customer`.

**Live revalidation (new):**
- `Api/Revalidation/LiveRevalidationServiceInterface.php`, `Model/Revalidation/RevalidatedProduct.php`, `Model/Revalidation/LiveRevalidationService.php` — store-scoped, customer-group-aware live check against `ProductRepositoryInterface`/`StockRegistryInterface`.

**Response contract (new):**
- `Model/Chat/Response/{AssistantAction,ResponseMetadata,ProductResult,AssistantResponse}.php` — the structured contract DTOs.
- `Model/Chat/Response/LlmResponseSchema.php` — the JSON schema requested via `ChatRequest::responseSchema`.
- `Model/Chat/Response/{ParsedLlmOutput,LlmResponseParser}.php` — decodes the LLM's structured JSON text, returns null on any malformed shape.

**Output Validator (new):**
- `Model/Chat/Response/OutputValidationResult.php` — two-outcome result.
- `Api/Chat/OutputValidatorInterface.php` / `Model/Chat/OutputValidator.php` — validates a raw `ChatResponse` against the revalidated set, shapes the contract.

**Wiring:**
- `Model/Chat/ChatPipelineResult.php` — modified: now carries `AssistantResponse`, not a raw `ChatResponse`.
- `Api/Chat/ChatEntryPipelineInterface.php` / `Model/Chat/ChatEntryPipeline.php` — modified: `handle()` gained `?int $customerGroupId = null`; retrieval → ranking → live revalidation → structured-output chat call → Output Validator → contract or safe fallback.
- `etc/di.xml` — new preferences for `LiveRevalidationServiceInterface`/`OutputValidatorInterface`.

**Tests:** 72 net new tests (full suite 899 → 971) across 12 new test files and 4 modified ones (`ProductIndexMappingTest`, `ChatEntryPipelineTest`, plus the two Fake test doubles from earlier tasks were untouched this time).

## Conventions followed

- `LiveRevalidationService` mirrors the store-scoped, fail-closed shape of every other generation/retrieval service in this module (`requireActive()` first, explicit store/customer-group, never implicit "current" anything).
- Reused the existing `Model\Indexing\Clock\ClockInterface` for `verifiedAt` (imported cross-domain) rather than calling `gmdate()`/`new DateTimeImmutable()` directly or inventing a second clock abstraction.
- `OutputValidationResult`/`ChatPipelineResult` follow the established two-outcome value-object pattern (`ConnectionResult`, `ScopeClassification`).
- Output-validation failure reuses the *exact same* `SafeResponse`/`ChatPipelineResult::shortCircuit()` path as an out-of-scope message — one consistent safe-fallback shape in the whole pipeline, per the task's explicit instruction.
- New Magento module dependencies declared explicitly in both `module.xml` and `composer.json`, matching how `Magento_Elasticsearch` etc. were added in earlier milestones — never relying on an undeclared transitive dependency.
- Test style continues mirroring established precedent: real instances for pure/cheap collaborators (`OutputValidator`, `LlmResponseParser`, `ChatInputValidator` are all real objects inside `ChatEntryPipelineTest`, not mocks), mocked interfaces at genuine I/O boundaries.

## Deviations from existing conventions

1. **`ChatPipelineResult::chatResponse()` was renamed to `response()` and its return type changed from `ChatResponse` to `AssistantResponse`.** Verified zero production callers first (no Controller exists yet). This is the correct evolution, not a deviation from safety intent — the entire point of the Output Validator is that nothing downstream should ever see an unvalidated raw provider response again.
2. **`Magento\Catalog\Model\Product` (concrete class) is type-hinted instead of `Api\Data\ProductInterface`** in `LiveRevalidationService`, because `isSalable()`, `getProductUrl()`, `getFinalPrice()`, and the magic `setCustomerGroupId()` accessor aren't declared on the data interface — only on the concrete model, which is what the repository actually returns in practice. This is standard, widely-used Magento development practice, not specific to this module.
3. **Image URL was deliberately left out of `RevalidatedProduct`.** Step 3 only explicitly requires price/URL never be LLM-fabricated; the frontend-hydration principle already in `architecture.md` ("Frontend hydrates product cards from SKU/entity_id via live Magento data") means the frontend doesn't need this API response to embed images, and Magento's image-helper (`Magento\Catalog\Helper\Image`) has real rendering-context fragility for a field that isn't safety-critical. Revisit if a later frontend task needs it.
4. **Reused OpenAI's structured-output feature (`response_format`/`ChatRequest::responseSchema`) for the first time**, built in Task 1 but never exercised until now. This wasn't optional scope creep — there is no other reliable way to get a per-product `reason`, `follow_up_questions`, and `actions` out of a free-text response without fragile NLP.
5. **A response is invalidated in full on any single fabricated SKU**, not filtered down to the entries that did check out. This is a judgment call (the task's wording didn't mandate either way) made to match this codebase's dominant fail-closed philosophy — a response caught fabricating once has already shown it can't be trusted for the rest of its content.

## Index mapping fix

**What was wrong:** `ProductIndexMapping::createBody()`'s `embedding` field set `'space_type' => self::KNN_SPACE_TYPE` both at the field level *and* inside the `method` block. OpenSearch 2.12 (the version running in this environment, confirmed via `client->info()`) rejects this combination outright: `mapper_parsing_exception: unknown parameter [space_type] on mapper [embedding] of type [knn_vector]` — the field-level key becomes entirely unrecognized once a `method` block is present, not just redundant.

**What changed:** removed the field-level `'space_type'` key; `method.space_type` (used by the Lucene HNSW method block) is the only one that needs to exist.

**How verified against the live cluster:** two live checks. First, reproduced the *exact broken* production create-body directly against the running OpenSearch 2.12 container and confirmed the same failure (proving this wasn't specific to a throwaway test script — the real code was broken). Second, after the fix, called the actual production `ProductIndexMapping::createBody()` (via real DI-resolved `StoreScope`/`RebuildRunContext` objects, not a hand-copied body) and successfully created a real index against the live cluster, read back its `_meta`, and deleted it. `Test/Unit/Model/Indexing/Mapping/ProductIndexMappingTest.php` was updated with an explicit `assertArrayNotHasKey('space_type', $embedding)` to lock the fix in; the full `Test/Unit/Model/Indexing` suite (381 tests) still passes with zero regressions.

## Revalidation design

**Live Magento services called:** `Magento\Catalog\Api\ProductRepositoryInterface::get($sku, false, $storeId, true)` (force-reloaded, store-scoped), `Magento\CatalogInventory\Api\StockRegistryInterface::getStockItem()`, and the concrete `Magento\Catalog\Model\Product`'s `isSalable()`/`getFinalPrice()`/`getProductUrl()`/`setCustomerGroupId()`. No existing internal wrapper for any of this was found anywhere in the codebase — `ProductSnapshotProviderInterface` (used at index time) explicitly documents excluding price/stock/customer-group data "by design," confirming this is genuinely new ground, not a missed reuse opportunity.

**What "fails revalidation" means concretely** (any one condition drops the product silently — never returned with a failed flag):
- SKU doesn't resolve (`NoSuchEntityException`)
- `status !== STATUS_ENABLED`
- `visibility` not in `{IN_SEARCH, BOTH}`
- Product not assigned to the store's website
- Stock item not `is_in_stock`
- `isSalable()` returns false (this generically covers configurable/bundle/grouped child-availability logic too, since it dispatches to the product type instance)

Products that pass get customer-group-aware pricing: `setCustomerGroupId($resolvedGroupId)` is called explicitly before reading `getFinalPrice()`/`getPrice()` (never left to fall back on an ambient session), and `specialPrice` is populated only when the final price is genuinely lower than the regular price.

**Customer-group handling:** `ChatEntryPipeline::handle()` now accepts an optional `?int $customerGroupId` (verified zero production callers before extending the interface, since no Controller/session layer exists yet), threaded straight through to `LiveRevalidationService::revalidate()`. A `null` value resolves to `Magento\Customer\Model\Group::NOT_LOGGED_IN_ID`. **This is a genuine gap, not fully closed**: nothing populates this parameter with a real logged-in customer's group today, because there is still no Controller/session layer in this module (confirmed again this task) — the plumbing exists end-to-end and is unit-tested, but a real customer's group will never reach it until a later task builds that layer.

**Unexpected infrastructure failures** (anything other than `NoSuchEntityException` from the repository) are deliberately *not* caught per-SKU — they propagate and fail the whole `revalidate()` call, so a real system problem (DB down, etc.) is never silently misreported as "this product happens to be unavailable."

## Response contract shape

- `AssistantResponse { message: string, products: ProductResult[], followUpQuestions: string[], actions: AssistantAction[], metadata: ResponseMetadata }`
- `ProductResult { product: RevalidatedProduct, reason: string, recommendationType: string }` — `recommendationType` defaults to `"organic"`; `"recommended"`/`"promoted"` are accepted by validation but nothing produces them (Phase 2, explicitly out of scope).
- `RevalidatedProduct { entityId, sku, name, price, specialPrice, url, verifiedAt }` — the *only* source of any product fact besides `reason` anywhere in the contract.
- `AssistantAction { type: string, skus: string[] }`
- `ResponseMetadata { provider, model, fallbackUsed }` — `fallbackUsed` is always `false` this task (fallback execution isn't wired yet).

**Confirmed the LLM never supplies URLs/prices/stock directly**: `LlmResponseSchema` (the JSON schema sent as `ChatRequest::responseSchema`) has exactly four top-level fields — `message`, `product_skus` (sku + reason only), `follow_up_questions`, `actions` — no price, URL, or stock field exists anywhere in the schema for the model to fill in. `OutputValidator` builds every `ProductResult` from the pre-verified `RevalidatedProduct` keyed by the SKU the LLM mentioned; the LLM's `reason` text is the only thing it contributes to the final contract.

## Output Validator design

Three concrete checks, any one of which invalidates the *entire* response (not a per-entry filter):
1. **Structural well-formedness** — `LlmResponseParser` must successfully decode `ChatResponse::text` as JSON matching the schema (`malformed_response` if not — a real, expected possible outcome, not exceptional, since nothing guarantees a model actually honors `strict: true`).
2. **No fabricated SKUs** — every SKU mentioned in `product_skus` *and* every SKU mentioned inside `actions[].skus` must be present in the already-live-revalidated set (`fabricated_sku` otherwise).
3. **No URL in free text** — the `message` field is scanned for an `https?://` pattern (`fabricated_url` if found); since the schema never gives the model a URL field, any URL appearing here is either a leak or a direct violation of the system instructions.

**Not implemented, and explicitly not attempted:** free-text price-fabrication detection (parsing numbers out of `message` and comparing them against live prices). This would require fragile regex/NLP heuristics disproportionate to this task's scope; the schema's structural design (no price field to fill in) plus `ProductContextFormatter`'s existing system-message instruction not to state prices are judged sufficient defense for now.

## Container verification

- `bin/cli php -l` on all ~25 new/changed files — clean.
- `bin/magento setup:upgrade` — succeeded (new `Magento_CatalogInventory`/`Magento_Customer` module.xml sequence entries picked up cleanly; both were already-installed base Magento modules).
- `bin/magento setup:di:compile` — succeeded, validating the complete new graph.
- `bin/magento cache:flush`, `module:status` (enabled), structure validator, `git diff --check` — all clean.
- **Live check 1 (Step 0):** the fixed `ProductIndexMapping::createBody()` (actual production code, not a reproduction) successfully created a real index against the live OpenSearch 2.12 cluster; `_meta` read back correctly; index deleted.
- **Live check 2 (fabricated SKU caught):** built a real `ChatEntryPipeline` from real DI-resolved components — `StoreScopeProvider`, `ConfigurationReader`, `ChatInputValidator`, `CommerceScopeClassifier`, the real `RankingPipeline` (all four signals), the real `LiveRevalidationService`, the real `OutputValidator` — with only retrieval and the LLM call faked (no live embedding provider or LLM credentials in this environment, consistent with every prior task; retrieval fed one real sample-data SKU, `24-MB01`). Fed a fake LLM response mentioning both `24-MB01` and a fabricated SKU: **result short-circuited with `fabricated_sku`, PASS**. A second run mentioning only the real SKU produced a full structured contract with the *actual live* Magento price (`34.00`), a real product URL, and a real timestamp: **PASS**.
- **Live check 3 (unavailable product dropped):** created a throwaway out-of-stock product (`AI-REVALIDATION-SMOKETEST-OOS`) via `ProductRepositoryInterface::save()`, called `LiveRevalidationService::revalidate()` with its SKU alongside the real in-stock SKU: the out-of-stock product was dropped, the real one was returned — **PASS**. Deleted the throwaway product afterward (required temporarily setting the `isSecureArea` registry flag to bypass Magento's programmatic-delete guard) and confirmed it no longer exists.
- All environment mutations (temporary `general/enabled=1`, the throwaway product) were reverted/deleted and independently re-verified clean after each check.

## Test results

- New tests: 72 net new (full suite went from 899 to 971), across `LlmResponseParserTest` (13), `LlmResponseSchemaTest` (3), `AssistantActionTest`/`ResponseMetadataTest`/`ProductResultTest`/`AssistantResponseTest`/`OutputValidationResultTest` (19 combined), `RevalidatedProductTest` (8), `OutputValidatorTest` (8), `LiveRevalidationServiceTest` (23), plus `ChatEntryPipelineTest` growing from 6 to 12 tests and `ProductIndexMappingTest`'s updated assertion.
- Full module suite: **971 tests, 2393 assertions, 0 failures** — zero regressions.
- One real mocking limitation hit and resolved: `Magento\Catalog\Model\Product::setCustomerGroupId()` is a `DataObject` magic accessor (not a declared method), so plain `createMock()` couldn't stub it — fixed with PHPUnit 9.5's `getMockBuilder(...)->addMethods([...])`.

## Known gaps / TODOs left for later tasks

Explicitly confirmed **not** built:
- **Fallback-provider retry/circuit-breaker execution** — `ChatEntryPipeline` still lets any `ChatGenerationService`/retrieval exception propagate uncaught; `FallbackEligibilityPolicy` remains unwired to any actual retry logic.
- **Tool-calling / `CommerceToolInterface`** — unchanged from prior tasks, still not built.
- **Admin UI** — no controller/block/UI component exists to actually surface any of this to a merchant or customer yet.
- **Free-text price-fabrication detection** — only the URL-in-message check was built; deliberately not attempting NLP/regex price-vs-live-data comparison in free text (see Output Validator design above).
- **Image URLs** — deliberately out of scope for `RevalidatedProduct` (see Deviations #3).
- **Phase 2 recommendation types** — the DTO accepts `recommended`/`promoted`, nothing produces them.

**Customer-group handling outcome:** the plumbing is real and fully wired/tested end-to-end (`ChatEntryPipeline::handle($storeId, $message, $customerGroupId)` → `LiveRevalidationService::revalidate()` → `Product::setCustomerGroupId()`), but **no real customer context reaches it yet** — there's still no Controller/session layer in this module, so every live call today resolves to Magento's `NOT_LOGGED_IN` group regardless of who's actually asking. This is the same gap flagged in Task 3, now narrowed from "no path exists" to "the path exists but nothing feeds it a real value" — flagged again for whichever future task builds the storefront Controller/session integration.

## Skill files updated

- `references/progress-log.md` — updated in place: status table rows for areas 4 (bug fixed), 6 (full pipeline wired), 8 (response contract done), 9 (live revalidation done); added the full Task 4 history entry; "Next up" now points at Task 5 (fallback execution) per the dependency chain, with a note flagging that real customer-group threading still has no owner in the sequence.
- This file: `docs/status-reports/2026-08-16-output-validator-revalidation.md`.

## Not done / blocked

Nothing was left incomplete relative to this task's scope. Every Step 0–6 instruction was completed and verified live. The customer-group and free-text-price-fabrication gaps above are documented limitations of what this task was scoped to cover, not incomplete work within that scope.
