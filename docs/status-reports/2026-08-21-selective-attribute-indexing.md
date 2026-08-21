# STATUS REPORT — Admin-controlled, selective product-attribute indexing

Replaced today's implicit, free-text-config-driven attribute coverage
with a real, explicit, merchant-managed allowlist stored in a new DB
table, exposed through two synchronized admin entry points that share
one repository. The pipeline change turned out to be a single-seam
replacement, not a rewrite — an audit done first, before any code
changed, found the whole downstream pipeline already fed from one
choke point, so only that one source needed swapping.

## Requirement 1 — audit (done first, before any code changed)

Traced exactly which attribute codes were indexed today and how, before
touching anything:

- **What was indexed**: a live query of `core_config_data` confirmed
  this store's real, effective custom-attribute list was exactly 11
  codes — `manufacturer, color, size, material, climate, pattern,
  style_general, style_bottom, activity, collar, sleeve` — sourced from
  the free-text `ai_shopping_assistant/indexing/searchable_attribute_codes`
  config field (no admin override beyond the config.xml-seeded
  default).
- **`is_user_defined` verified safe as the bulk-select screen's
  filter**: a live query of `eav_attribute`/`catalog_eav_attribute`
  confirmed all 11 real codes have `is_user_defined = 1`, and every
  `is_user_defined = 0` attribute on `catalog_product` is a genuine
  Magento-core/system field (name, sku, price, status, images, meta
  fields, dates, `country_of_manufacture`, ...) — never a real
  merchant-facing product-fact attribute this screen would need to
  offer. Confirmed before relying on it, not assumed.
- **How it reached both paths already**: `ProductSnapshotProvider` →
  `SearchableAttributeValueResolver::resolve()` (gated by
  `IndexingConfigInterface::searchableAttributeCodes()`) →
  `ProductDocumentNormalizer::normalize()`, which already fed the SAME
  resolved attribute list into BOTH the embedding `searchableText`
  payload AND the structured `attributes` array field of the real
  OpenSearch document — the same field `AttributeMatchSignal` and
  `ProductContextFormatter` both read via `SearchCandidate::attributes`.
  **This meant requirement 7 ("feed both paths") was already
  structurally satisfied by the existing pipeline** — only the SOURCE
  of the code list needed replacing, not any downstream wiring.

## Files

**New:**
- `Api/Catalog/AttributeIndexingSelectionRepositoryInterface.php`,
  `Model/Catalog/AttributeIndexingSelectionRepository.php` — the one
  shared repository both admin entry points and the indexing pipeline
  read/write through
- `Setup/Patch/Data/SeedAttributeIndexingSelection.php` — this module's
  first-ever Setup data patch
- `Model/Catalog/AttributeGrid/{Grid,IndexedForAiColumnRenderer}.php` —
  Entry Point A
- `Controller/Adminhtml/Attribute/{AbstractMassSetIndexedForAi,
  MassEnableForAi,MassDisableForAi}.php`
- `Block/Adminhtml/AttributeSelection/Index.php`,
  `Controller/Adminhtml/AttributeSelection/{Index,Save}.php` — Entry
  Point B
- `view/adminhtml/layout/aavirbhava_aishoppingassistant_attributeselection_index.xml`,
  `view/adminhtml/templates/attributeselection/index.phtml`
- `etc/adminhtml/di.xml` (new file — the grid `<preference>`)
- Tests: `Test/Unit/Model/Config/ConfigurationReaderTest.php` (extended),
  `Test/Unit/Block/Adminhtml/AttributeSelection/IndexTest.php`,
  `Test/Integration/Model/Catalog/
  {AttributeIndexingSelectionRepositoryDatabaseTest,
  AttributeSelectionAffectsIndexingPipelineTest}.php`

**Modified:**
- `Model/Config/ConfigurationReader.php` / `Api/Config/
  IndexingConfigInterface.php` — `searchableAttributeCodes()` now
  sourced from the new repository, not a free-text field
- `Model/Config/Path.php` — removed the now-dead constant
- `etc/adminhtml/system.xml` / `etc/config.xml` — removed the old
  `searchable_attribute_codes` field and default **entirely**
- `Api/Indexing/ProductIndexMappingInterface.php` — `MAPPING_VERSION`
  3 → 4
- `etc/db_schema.xml`, `etc/di.xml`, `etc/acl.xml`,
  `etc/adminhtml/menu.xml`

**Not touched at all** (confirmed by their own pre-existing test
suites passing completely unmodified): `SearchableAttributeValueResolver`,
`ProductSnapshotProvider`, `ProductDocumentNormalizer`,
`AttributeMatchSignal`, `ProductContextFormatter`, `ProductAttributePolicy`.

## Key decisions

- **Replace the old field entirely, not leave it dead alongside the
  new mechanism.** The task's own wording ("replacing today's
  inconsistent/implicit attribute coverage") is an explicit instruction
  to replace, and this module's own standing convention is not to leave
  backwards-compatibility shims for something certainly unused — a
  dead, now-inert admin field would be actively misleading to a
  merchant. `ProductAttributePolicy` (the security denylist for codes
  like `cost`/`api_key`) is completely unchanged and still
  independently re-applied inside `SearchableAttributeValueResolver` —
  this task's new allowlist is layered ON TOP of that existing security
  boundary, never a substitute for it.

- **Entry Point A wired via a `<preference>` on a concrete class, not a
  layout `<referenceBlock>` override.** Confirmed by reading the real
  core classes first, not assumed: Stores > Attributes > Product is a
  legacy `Backend\Block\Widget\Grid\Extended` grid (not a Ui
  Component), created directly in PHP by
  `Grid\Container::_prepareLayout()` with no stable, addressable
  layout block name a `<referenceBlock name="...">` could target — the
  container itself has no explicit registered name either (created via
  `addContent()`). A `<preference>` on the CONCRETE
  `Magento\Catalog\Block\Adminhtml\Product\Attribute\Grid` class is
  valid, real Magento behavior — `Layout::createBlock()` resolves
  through the ObjectManager, which honors preferences for any
  requested type string, confirmed correctly compiled by directly
  reading `generated/metadata/adminhtml.php`'s real `preferences` map.

- **The new grid column via a custom renderer, not a SQL join.**
  `IndexedForAiColumnRenderer` calls
  `AttributeIndexingSelectionRepositoryInterface::all()` once per grid
  render (confirmed cached/reused across every row by reading
  `Column::getRenderer()`'s real caching before relying on it) rather
  than joining the new table into the core attribute collection's own
  internal query-building, which this task deliberately never touches.

- **Entry Point B mirrors this module's own established hand-rolled-
  server-rendered-page convention** (the Playground/Boost precedent),
  not a Ui Component form: a plain checkbox list POSTing
  `selected_codes[]` (checked) plus a hidden `all_codes` field listing
  everything the page offered. An unchecked HTML checkbox never appears
  in a POST at all, so without `all_codes`, unchecking a previously-
  selected attribute would silently do nothing — `Save` computes both
  newly-selected AND newly-deselected codes from the diff between the
  two.

## A real, newly-confirmed environment finding

The pre-existing, already-documented `Magento_CatalogSampleData`
`InstallCatalogSampleData` patch failure doesn't just fail itself — it
**silently aborts the rest of that `setup:upgrade` run's data-patch
queue too**, including every module ordered after it. Confirmed twice:
two full `setup:upgrade` runs both stopped at the identical point, and
this task's own brand-new `SeedAttributeIndexingSelection` patch never
appeared in `patch_list` after either run. This is a more serious
consequence than CLAUDE.md's existing note disclosed ("does not block
`setup:di:compile`, schema upgrades, or reindexing" — true, but
incomplete: it does block other modules' *data* patches specifically).

Worked around for this task's own verification by constructing
`SeedAttributeIndexingSelection` via the real object manager and
calling `apply()` directly in a separate process — confirmed it
correctly read the real live config value and seeded exactly the 11
real attribute codes the audit found:

```
activity  climate  collar  color  manufacturer  material
pattern   size     sleeve  style_bottom  style_general
```

CLAUDE.md's "Known open issues" section has been updated with this more
complete finding so a future task doesn't have to rediscover it.

## Verification — full test suite

**1599 tests / 3868 assertions / 0 failures** (up from 1596/3863 at the
end of Task 37), plus **10 new Integration tests / 20 assertions**
against the real database:

- `AttributeIndexingSelectionRepositoryDatabaseTest` (7 tests) — the
  repository's own atomic upsert semantics: set/read round trip, no
  row defaults to not-indexed, deselecting after selecting, one code's
  change doesn't affect another, multiple codes in one call,
  repeated/idempotent calls.
- `AttributeSelectionAffectsIndexingPipelineTest` (3 tests) — proves
  the real, load-bearing requirement end to end against real data: a
  real product (SKU `MP01-32-Black`) and a real attribute (`color`),
  toggling its selection genuinely changes what `ProductSnapshotProvider`
  — the pipeline's real entry point — includes, both directly and via
  `ConfigurationReaderInterface::readIndexing()`. The store's real
  pre-existing `color` selection is restored in `tearDown()`.

A whole-module `php -l` sweep (659 files) and `setup:di:compile` are
both clean.

## Verification — real, DI-resolved wiring beyond the test suite

- **The grid `<preference>`**: confirmed correctly compiled by reading
  the real `generated/metadata/adminhtml.php`'s `preferences` map
  directly — it maps
  `Magento\Catalog\Block\Adminhtml\Product\Attribute\Grid` to this
  module's own subclass. (A naive ad-hoc script calling
  `State::setAreaCode('adminhtml')` *after* the object manager was
  already bootstrapped for the default area failed to reflect this —
  disclosed as a real limitation of manually flipping area code
  post-bootstrap in a script, not of the actual preference, which a
  real admin HTTP request resolves correctly since Magento initializes
  the object manager already scoped to the real request's area.)
- **All new controllers executed for real**, via the real object
  manager with real populated request params, including resolving a
  REAL attribute_id (`climate`'s) to its real code via the real
  `Magento\Eav\Api\AttributeRepositoryInterface`:
  - `MassDisableForAi` → `climate` correctly became not-indexed
  - `MassEnableForAi` → `climate` correctly became indexed again
  - Bulk-select `Save` (selecting `pattern`, leaving `climate`
    unchecked) → `climate` correctly became not-indexed, `pattern`
    correctly became indexed
  - **Confirmed both entry points agree with each other's resulting
    state afterward** (requirements 6 and 9)
  - The store's real seeded selection was restored afterward

## Verification — real reindex + real OpenSearch document (requirement 11)

All against this store's genuinely live data, not a fixture:

1. `bin/magento aavirbhava:ai-shopping-assistant:index-coverage` showed
   **181/181 full coverage** before the reindex.
2. Ran a real `bin/magento indexer:reindex ai_product_rag`
   (`MAPPING_VERSION` 4, forcing a real full rebuild).
3. Coverage remained **181/181 fully covered** afterward.
4. Directly queried the real, currently-active OpenSearch index's
   mapping `_meta` and confirmed `"mapping_version": 4`.
5. Directly queried the real indexed document for SKU `MP01` (the same
   real product used in Task 34's own live chat verification) and
   confirmed its `attributes` field contains exactly the codes this
   task's real, currently-selected attributes that this specific
   product actually has non-empty values for:

```json
"attributes": [
  {"code": "climate", "values": ["Cool", "Spring"], "label": "Climate"},
  {"code": "material", "values": ["CoolTech™", "Fleece", "Hemp", "Wool"], "label": "Material"},
  {"code": "pattern", "values": ["Solid"], "label": "Pattern"},
  {"code": "style_bottom", "values": ["Workout Pants", "Sweatpants", "Track Pants"], "label": "Style"}
]
```

This is genuine, real, end-to-end proof the admin-controlled selection
reaches a real OpenSearch document — not merely that the code compiles.

## Skill files updated

- `references/progress-log.md` — header summary replaced, this Task 38
  history entry added.
- `CLAUDE.md` — "Attribute indexing selection" section marked done with
  6 new implementation-decision bullets; "Known open issues" gained the
  more complete CatalogSampleData-blocks-other-modules'-data-patches
  finding.

## Not done / blocked

Nothing blocked. Two disclosed, deliberate scope boundaries:

1. No dedicated PHPUnit unit test exists for `Model\Catalog\
   AttributeGrid\Grid` itself — its `_prepareColumns()`/
   `_prepareMassaction()` overrides are thin, static-config calls, the
   same class of logic this module's own pre-existing `Boost\Save`
   controller also has with no dedicated test. Verified instead via the
   compiled-preference-metadata check and the real controller execution
   above, consistent with this module's own established "no admin
   controller/legacy-grid unit tests exist anywhere in this module"
   precedent.
2. The actual rendered grid column/mass-action/bulk-select screen
   through a real authenticated browser session remains unconfirmed —
   this environment's admin-login CAPTCHA gate (already documented)
   blocks it, and no browser-automation tool is available in this
   session.

Every other layer — schema, DI wiring, real controller execution, real
repository/pipeline integration against real data, real reindex, real
OpenSearch document — is genuinely, separately verified and disclosed
as such, not silently assumed.
