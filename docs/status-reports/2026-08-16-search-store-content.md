# STATUS REPORT — search_store_content tool

Task 10 of the Aavirbhava_AiShoppingAssistant build sequence: a
`CommerceToolInterface` tool searching CMS pages, blog content (if a blog
module is present), and products by keyword, returning a unified result
set — distinct from search_products (Task 6), which does semantic/RAG
retrieval against the assistant's own product index for product
discovery. This tool is closer to keyword/full-text search: "do you have
a returns policy page," "any blog posts about waterproofing jackets," and
attribute/category-based product lookups search_products' semantic
ranking might not surface well.

## Files created/changed

**New store-content search domain:**
- `Api/Tool/BlogContentSearcherInterface.php` — the extensibility seam for a real blog integration.
- `Model/Tool/NullBlogContentSearcher.php` — the default (no blog module installed), always returns `[]`.
- `Model/Tool/CmsPageContentSearcher.php` — CMS page title/content keyword search.
- `Model/Tool/ProductContentSearcher.php` — product name/description/sku/category keyword search, candidate SKUs only.
- `Model/Tool/ContentSearchTextUtility.php` — shared LIKE-escaping and snippet-extraction helpers.
- `Model/Tool/StoreContentMatch.php` — the unified result row DTO (type/id/title/snippet, plus price/url for products).
- `Model/Tool/SearchStoreContentTool.php` — the `CommerceToolInterface` implementation tying the above together.

**Modified capability gating:**
- `Api/Config/CapabilitiesConfigInterface.php` / `Model/Config/{CapabilitiesConfig,ConfigurationReader,Path}.php` — new `policy_search_enabled` capability / `isPolicySearchEnabled()`.
- `etc/adminhtml/system.xml` / `etc/config.xml` — the corresponding "Store Content Search (search_store_content)" field, default enabled.

**Modified DI:**
- `etc/di.xml` — new `BlogContentSearcherInterface` → `NullBlogContentSearcher` preference; `search_store_content` added to `CommerceToolRegistry`'s tools array.

**Tests:** 25 net new tests (full suite 1172 → 1197) across 6 new test files (`ContentSearchTextUtilityTest`, `StoreContentMatchTest`, `NullBlogContentSearcherTest`, `CmsPageContentSearcherTest`, `ProductContentSearcherTest`, `SearchStoreContentToolTest`) plus updates to `CapabilitiesConfigTest`/`ConfigurationReaderTest` for the new toggle.

## Conventions followed

- `SearchStoreContentTool` matches every other tool's shape exactly: `name()`/`description()`/`inputSchema()`/`authorize()`/`execute()`, gated by a capability toggle read via `ConfigurationReaderInterface::readCapabilities()`, throwing `ToolAuthorizationException` when disabled.
- `policy_search_enabled` follows the exact system.xml/config.xml/Path.php pattern every prior "Assistant Capabilities" field uses, defaulting to enabled like the 5 read tools.
- `BlogContentSearcherInterface` + a swappable di.xml-registered default implementation mirrors `LlmProviderRegistry`/`EmbeddingProviderRegistry`'s provider-swap extensibility pattern already established in this module.
- Every new domain class is `final`, matching this module's universal convention for concrete implementation classes.
- Product results are only ever candidates until `LiveRevalidationServiceInterface::revalidate()` confirms them — the same "raw data is never ground truth" discipline as `search_products`/`check_price`/every other tool.

## Deviations from existing conventions

None structural. The one notable design choice — using Magento's own core `Product`/`Category`/`Cms\Page` collections instead of the assistant's own OpenSearch index — is explained under Search mechanism design below; it is a considered choice within the task's own explicit "decide and justify" instruction, not an unexplained departure.

## Blog module findings

**No blog module is installed in this Magento instance.** Checked `bin/magento module:status`, the project's `composer.json`, and `vendor/` directly for `Magefan_Blog` and known Amasty/Mageplaza blog packages — none present, none registered. `BlogContentSearcherInterface` was built specifically so this is not a dead end: a real integration is a single `etc/di.xml` preference swap to a new adapter implementing the interface against that module's own public repository/API (never its internal tables directly, per the task's own instruction). Until then, `NullBlogContentSearcher` is registered as the default and always returns an empty list — confirmed live to do so cleanly, with no error, exactly matching the task's explicit requirement.

## Search mechanism design

**CMS:** `Magento\Cms\Model\ResourceModel\Page\CollectionFactory`, using the collection's own `addStoreFilter()` (the same store-scoping mechanism the admin CMS grid itself uses) plus `is_active = 1` plus a title/content `LIKE` OR-match (wildcard-escaped). CMS pages are few, so a direct LIKE scan is appropriately simple for this volume — no separate index.

**Blog:** no module present; `NullBlogContentSearcher` returns `[]`.

**Products:** deliberately **not** the assistant's own OpenSearch/embedding retrieval path (`HybridRetrievalService`, what `search_products` uses) — that path always issues a vector query alongside the keyword one, which requires a live embedding-provider call this tool must never make, and it only ever sees products already present in the assistant's own index. Instead, `Magento\Catalog\Model\ResourceModel\Product\CollectionFactory` (name/description/short_description/sku, LIKE-OR) plus a second pass matching category names via `Category\CollectionFactory` and pulling in products from matching categories. Only candidate SKUs are returned by this searcher — every fact ultimately shown for a product still comes from `LiveRevalidationServiceInterface::revalidate()`, never from this raw collection scan. This design means `search_store_content`'s product search works in *any* install with this module active, independent of whether the assistant's own indexing pipeline has ever run — a genuine resilience property, not just a workaround for this dev environment's own unconfigured embedding provider.

**No LLM or embedding call anywhere in this tool** — confirmed by inspection (no `EmbeddingProviderInterface`/`ChatGenerationServiceInterface` dependency exists anywhere in `SearchStoreContentTool`'s or its collaborators' construction) and by every live check below succeeding with zero LLM credentials configured in this environment.

## Verified-SKU integration

`SearchStoreContentTool::execute()` returns discovered products' `RevalidatedProduct`s via `ToolResult::$verifiedProducts` — the identical field/shape every other tool (`search_products`, `check_price`, etc.) already uses. `ToolCallingChatService`/`ChatEntryPipeline`'s existing Task 6 merge logic (retrieval-verified ∪ every tool-verified set) already folds this in with **zero new integration code** required. This was confirmed live and directly, not merely by inspection: a real `OutputValidator::validate()` call, given a scripted `ChatResponse` referencing a real SKU this tool found (`24-MB01`) alongside that tool's own `verifiedProducts`, returned `isValid() === true`; the identical call referencing an unrelated, fabricated SKU returned `isValid() === false` with `reasonCode() === 'fabricated_sku'` — proving the check is genuinely selective, not a rubber stamp.

## Container verification

- `bin/cli php -l` on every new/modified file: clean.
- `bin/magento setup:upgrade`: clean (no schema changes this task).
- `bin/magento setup:di:compile`: clean — validates the new `BlogContentSearcherInterface` preference and `CommerceToolRegistry`'s updated tools array (run twice: once before, once after the join-type fix below).
- `bin/magento cache:flush`: clean.
- Full suite: **1197 tests / 2925 assertions / 0 failures**, up from the pre-task baseline of 1172/2876 (net +25 tests / +49 assertions).

**Live checks**, run inside the container against the real, DI-resolved `search_store_content` tool (via `CommerceToolRegistryInterface::get('search_store_content')`), via a temporary script deleted after use. No LLM credentials were needed for any of these — this is a keyword-only tool by design:

1. **CMS page search.** Querying `"returns"` found this store's real "Customer Service" CMS page (page id 6), with the correct title and a snippet correctly centered on the "returns" match within the page's real HTML content.
2. **Blog search, no module present.** Querying `"waterproofing jacket"` returned zero blog results with no error — confirming the graceful-skip path works live, not just in the unit test for `NullBlogContentSearcher`.
3. **Product search by keyword.** Querying `"duffle"` found 3 real, live-revalidated products (`24-MB01` "Joust Duffle Bag", `24-UB02` "Impulse Duffle", `24-WB07` "Overnight Duffle") with real prices and URLs.
4. **Product search by category name.** Querying `"Watches"` (a real category name, not a match on any product's own name/sku/description) correctly found 5 real products via the category-match path, capped at the per-content-type result limit — proving that code path works live, not only the text-match path.
5. **Output Validator integration**, described above under Verified-SKU integration.

**A real bug was found and fixed during check 3, before it passed** (see below).

## Test results

1172 → 1197 tests (+25), 2876 → 2925 assertions (+49), 0 failures. New test files: `ContentSearchTextUtilityTest` (6), `StoreContentMatchTest` (4), `NullBlogContentSearcherTest` (1), `CmsPageContentSearcherTest` (4), `ProductContentSearcherTest` (4), `SearchStoreContentToolTest` (6). Modified: `CapabilitiesConfigTest` (+1 assertion), `ConfigurationReaderTest` (+2 assertions, both existing capability tests extended for the new toggle).

## Known gaps / TODOs left for later tasks

- **A real bug, found and fixed by the live check, not by unit tests:** `ProductContentSearcher`'s combined sku/name/description/short_description search initially returned **zero results** for every query once description/short_description were included in the OR filter — even for products that plainly matched on name or sku. Root cause: `Magento\Eav\Model\Entity\Collection\AbstractCollection::addAttributeToFilter()`'s join type defaults to `'inner'`; description/short_description are optional attributes without a guaranteed default-scope EAV row for every product in this catalogue, so Magento generated an `INNER JOIN` requiring that row to exist for *every* product in the result — silently excluding any product missing one, which collapsed the entire OR clause to nothing even when a completely different attribute in the same OR obviously matched. Reproduced directly against this store's own real sample-data catalogue (`SELECT`s confirming `24-MB01` etc. are enabled, visible, and website-assigned, then isolating the exact query via `getSelect()`'s generated SQL) before fixing. Fixed by passing `'left'` as the explicit third argument to `addAttributeToFilter()`. This class of bug is a genuine argument for the gap noted directly below.
- **No `Test/Integration/`-style DB test exists yet for `CmsPageContentSearcher`/`ProductContentSearcher`.** This module has an established convention for exactly this class of risk (real Magento collection/SQL behavior that a fake-collection unit test structurally cannot catch — see `Test/Integration/Model/Chat/DbConversationHistoryStoreDatabaseTest.php` from Task 8) but none was written this task, for time reasons. The join-type bug above was caught only because this task's own manual live-check step happened to combine those specific attributes; a future regression in either searcher would currently only be caught the same manual way.
- Generic EAV attribute-value search (e.g., searching by a color or material attribute value directly, rather than name/category/description text) is out of scope for this simple LIKE-based approach — flagged, not silently unsupported.

## Skill files updated

- `references/progress-log.md` — status table rows 1, 3, and 6 (runtime request pipeline's tool list) updated; full Task 10 history entry added; "Next up" narrowed to just the frontend chat widget, with order-assistance's Phase-1 deferral (an explicit user decision, not a discovery of this task) noted plainly rather than re-litigated.

## Not done / blocked

Nothing blocked this task. The one gap worth flagging prominently is the missing DB-integration test coverage noted above — a reasonable next increment, not a functional deficiency in what shipped.
