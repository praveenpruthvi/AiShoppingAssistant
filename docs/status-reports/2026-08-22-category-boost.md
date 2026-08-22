# STATUS REPORT — Category-level merchandising boost

Adds category-level merchandising boost, combining additively with the
existing per-product boost (Task 32), completing this module's Phase 2
backlog item CLAUDE.md had already specified ("Category-level boosting
v2 (final Phase 2 backlog item)").

## Schema and repository

New `aavirbhava_ai_category_boost` table (declarative schema), same
shape/conventions as `MerchandisingBoost`'s table: `category_id`,
`boost_weight` (decimal, capped via
`CategoryBoostRow::MAX_BOOST_WEIGHT = MerchandisingBoostRow::MAX_BOOST_WEIGHT`
— the same constant value, not independently redefined, enforced by a
dedicated test), `start_date`/`end_date` (nullable), `is_active`, no
`store_id` (catalog-wide, matching product boost's own precedent). FK
to `catalog_category_entity` with `onDelete="CASCADE"`. Confirmed
applied via a real `setup:upgrade` and a real `DESCRIBE
aavirbhava_ai_category_boost;`.

`CategoryBoostRepositoryInterface`/`CategoryBoostRepository` is the
single shared read/write point both entry points and the ranking
signal go through, mirroring `MerchandisingBoostRepository` exactly
(`save()`/`getById()`/`deleteById()`), plus one addition:
`findByCategoryId(int $categoryId): ?CategoryBoostInterface`. This is
new relative to the product-boost repository because Entry Point A (a
field directly on the category's own edit form) needs upsert
semantics against the category's own id — every re-save of the same
category must update the SAME boost row, never create a duplicate —
unlike product boost's always-create-new mass-action flow.

`ActiveCategoryBoostReaderInterface`/`ActiveCategoryBoostReader`
mirrors the existing `ActiveBoostReader` exactly: raw SQL directly
against `ResourceConnection` (deliberately bypassing the ORM on this
hot ranking-time path, matching this module's established pattern),
`MAX(boost_weight)` grouped by `category_id`, filtered by
`is_active=1 AND (start_date IS NULL OR start_date <= now) AND
(end_date IS NULL OR end_date >= now)` using `ClockInterface::now()`
(real current time, no cron), per-PHP-instance memoization with no
invalidation logic (relies on PHP-FPM's one-instance-per-request
lifecycle, same as every other reader in this module).

## Entry Point A — category edit form field

Categories use a genuinely different admin UI architecture than
products, confirmed by reading the real vendor source before writing
any code: `Magento\Catalog\Model\Category\DataProvider::getData()`
hard-overrides the parent `AbstractDataProvider`/modifier-pool
mechanism entirely, so the product form's standard "register a data
Modifier" extension technique would silently do nothing here. The
correct, core-precedented mechanism — confirmed via
`Magento_CatalogUrlRewrite`'s own real, currently-installed usage of
this exact pattern for its `url_key` field — is a plugin directly on
the concrete `DataProvider` class (`afterGetData`), registered in
`etc/adminhtml/di.xml`.

A new `aavirbhava_category_boost` fieldset (`sortOrder="15"`) is
merged into core's real `category_form.xml` by name-attribute
matching: a weight field and optional start/end date fields (the date
fields mirror core's own `custom_design_from`/`custom_design_to`
example). No `is_active` toggle on this entry point by design —
weight > 0 implies active here; an explicit deactivate/delete is only
available from Entry Point B's grid.

Saving is wired to the real `catalog_category_save_after` event (new
`etc/adminhtml/events.xml` + `CategoryBoostSaveObserver`), not
`catalog_category_prepare_save` — confirmed by reading
`Controller\Adminhtml\Category\Save.php` and
`AbstractModel::afterSave()` directly that `_prepare_save` fires
*before* `$category->save()`, so a brand-new category's entity id is
not yet populated at that point, while `_save_after` guarantees a real
id for both new and existing categories, which this FK-dependent save
requires. A submitted weight of 0 (or the field not submitted at all)
deactivates an existing boost rather than deleting it, preserving its
configured history; outright deletion is only available from Entry
Point B. A boost-save failure is caught, logged via the injected
`LoggerInterface`, and surfaced as a non-blocking admin warning — the
category's own save must never be broken by a boost validation
failure.

## Entry Point B — standalone review grid

New controllers (`Index`/`Edit`/`Save`/`Delete`), block, layout XML,
and UI-component listing, mirroring `MerchandisingBoost`'s own Task 32
review grid exactly. `Edit` only ever handles an existing `boost_id` —
it never creates new, since categories have no bulk-select grid the
way products do; new boosts are always created via Entry Point A.
Category display names are EAV-stored (unlike product's fixed `sku`
column), so unlike the product-boost grid's cheap SQL join, names are
resolved separately in the grid's own `DataProvider` via a real,
page-scoped `Category\CollectionFactory` + `addNameToResult()` call.
New ACL resource and admin menu entry added (`Marketing > Category
Boosts`, alongside the existing `Merchandising Boosts` entry).

## Ranking signal — combination logic

Extended `MerchandisingBoostSignal` in place rather than adding a
second, parallel signal. Decision and rationale, documented directly
in the class's own docblock: `min(1.0, productBoost +
max(categoryBoosts))` genuinely cannot be expressed correctly by two
independent, order-applied `RankingSignalInterface` stages without
either breaking pipeline independence or letting the combined total
exceed the cap — if each signal capped only its own contribution
separately, e.g. a 0.8 product boost and a 0.9 category boost could
add up to 1.7 instead of the required `min(1.0, 0.8+0.9) = 1.0`.
Capping-after-summing genuinely requires both boost sources to be
known together in one place.

New `ProductCategoryMembershipReaderInterface`/
`ProductCategoryMembershipReader` resolves each candidate's real
`catalog_category_product` membership live, scoped to only the
current candidate set's product ids — the same "sparse, merchant-
intent, time-scoped, must be immediate, not indexed into OpenSearch"
reasoning as product boost itself. `SearchCandidate::$categoryNames`
(display names only, no ids) cannot be reused for this — a new,
dedicated reader was required.

The signal now: reads product boosts (existing reader, unchanged),
reads each candidate's category memberships, collects the full set of
distinct category ids across the whole candidate batch, reads active
category boosts for exactly that set in one query, then for each
candidate computes `max()` across its own categories' boosts (never
`sum()` — using MAX prevents a product from gaming the system by being
tagged into many boosted categories) and combines additively with its
product boost, capped at `MerchandisingBoostRow::MAX_BOOST_WEIGHT`.
The existing guardrail is unchanged: a boost — product or category —
can only reorder candidates that already passed retrieval/
availability; it can never inject an irrelevant product into results.

## `SearchCandidate`/`withScore()` audit (explicit task requirement)

Given Task 31's original bug class in this module — a field silently
dropped on `SearchCandidate`'s manual, field-by-field `withScore()`
reconstruction — this change was explicitly audited rather than
assumed safe. Conclusion: **no new field is needed, and `withScore()`
itself required zero changes.** Category membership is resolved via a
live reader keyed purely by the candidate's already-present
`entityId`; nothing about category boost needs to be carried on the
`SearchCandidate` object itself.

## A real, previously-latent bug found and fixed (in Task 32's code, not just new code)

A new test (`CategoryBoostSaveObserverTest::testAStartAndEndDateAreNormalizedToFullMysqlDatetimesAndPassedThrough`)
initially failed, showing a saved start date like
`'2026-03-01 07:22:53'` instead of the expected
`'2026-03-01 00:00:00'`. Root-caused via a direct `php -r`
reproduction: `\DateTimeImmutable::createFromFormat('Y-m-d', $raw)`
leaves the time-of-day at the CURRENT wall-clock time at the moment
the code runs, not midnight, because the format string doesn't
specify every date/time component — a real, non-obvious PHP behavior.

This exact code pattern had been copied verbatim from the pre-existing
`Controller\Adminhtml\Boost\Save.php::nullableDate()`, written in Task
32 — meaning this bug had been silently live in the already-shipped
product-boost feature the entire time, simply never triggered/observed
because no prior test asserted an exact saved value.

Fixed by changing the format string to `'!Y-m-d'` (a leading `!` resets
every field the format doesn't mention to its Unix-epoch default, i.e.
midnight), confirmed correct via a direct `php -r` check. Applied to
**three** files: the original `Controller/Adminhtml/Boost/Save.php`
(Task 32, retroactively fixed), plus the two new files
`Controller/Adminhtml/CategoryBoost/Save.php` and
`Observer/CategoryBoostSaveObserver.php` — each with an explanatory
comment. All 7 observer tests passed after the fix.

## Files changed/added

Schema: `etc/db_schema.xml`.

Repository/reader layer: `Api/Merchandising/CategoryBoostInterface.php`,
`Model/Merchandising/CategoryBoostRow.php`, `Model/CategoryBoost.php`,
`Model/ResourceModel/CategoryBoost.php`,
`Model/ResourceModel/CategoryBoost/Collection.php`,
`Api/Merchandising/CategoryBoostRepositoryInterface.php`,
`Model/Merchandising/CategoryBoostRepository.php`,
`Api/Merchandising/ActiveCategoryBoostReaderInterface.php`,
`Model/Merchandising/ActiveCategoryBoostReader.php`,
`Api/Merchandising/ProductCategoryMembershipReaderInterface.php`,
`Model/Merchandising/ProductCategoryMembershipReader.php`.

Ranking signal: `Model/Ranking/Signal/MerchandisingBoostSignal.php`
(rewritten in place).

DI: `etc/di.xml` (3 new preferences), `etc/acl.xml`,
`etc/adminhtml/menu.xml`, `etc/adminhtml/di.xml` (DataProvider
plugin), `etc/adminhtml/events.xml` (new file).

Entry Point A: `view/adminhtml/ui_component/category_form.xml`,
`Plugin/Catalog/DataProvider/CategoryBoostDataProviderPlugin.php`,
`Observer/CategoryBoostSaveObserver.php`.

Entry Point B: `Controller/Adminhtml/CategoryBoost/{Index,Edit,Save,Delete}.php`,
`Block/Adminhtml/CategoryBoost/Edit.php`,
`view/adminhtml/layout/aavirbhava_aishoppingassistant_categoryboost_{index,edit}.xml`,
`view/adminhtml/templates/categoryboost/edit.phtml`,
`view/adminhtml/ui_component/aavirbhava_categoryboost_listing.xml`,
`Model/Merchandising/CategoryBoostGrid/{DataProvider,CategoryBoostActions}.php`.

Bug fix: `Controller/Adminhtml/Boost/Save.php` (Task 32, retroactive
date fix).

Documentation: `CLAUDE.md` (extended the pre-written "Category-level
boosting v2" section with the real implementation), `references/progress-log.md`.

New/updated tests:
- `Test/Unit/Model/Merchandising/CategoryBoostRowTest.php` (new, ~12
  tests, including sharing the same cap constant as
  `MerchandisingBoostRow`)
- `Test/Unit/Model/Merchandising/ActiveCategoryBoostReaderTest.php` (new)
- `Test/Unit/Model/Merchandising/ProductCategoryMembershipReaderTest.php` (new)
- `Test/Unit/Model/Ranking/Signal/MerchandisingBoostSignalTest.php`
  (rewritten, 13 tests: additive combination, capping-together-not-
  separately, MAX-not-sum across a product's own multiple categories,
  query scoping to the candidate set, empty-candidate-list, defensive
  clamp, and both guardrail variants — product-boosted and category-
  boosted — of "boosted-but-irrelevant cannot outrank relevant-
  unboosted")
- `Test/Unit/Model/Ranking/RankingPipelineTest.php` (updated for the
  signal's grown constructor)
- `Test/Integration/Model/Merchandising/CategoryBoostDatabaseTest.php`
  (new, 16 real-DB tests, including 2 exercising
  `ProductCategoryMembershipReader` against the real
  `catalog_category_product` table; ids resolved dynamically, no
  hardcoding)
- `Test/Unit/Observer/CategoryBoostSaveObserverTest.php` (new, 7
  tests — caught the `createFromFormat` bug)
- `Test/Unit/Plugin/Catalog/DataProvider/CategoryBoostDataProviderPluginTest.php`
  (new, 5 tests)

## Verification — full test suite

**1804 tests / 4447 assertions / 0 failures** (1714/4094 unit +
90/353 integration; up from 1750/4350). `setup:di:compile` clean.
Whole-module `php -l` sweep clean.

## Verification — live

No browser-automation tool exists in this session and admin login is
CAPTCHA-gated (the same standing limitation as every other admin-UI
task in this module); live verification below uses real object-manager
construction against real DI/DB, disclosed explicitly as the
substitute for a literal browser click-through.

**1. Ranking-signal combination — full additive/capped formula, three
real product scenarios against real catalog data:**

Confirmed via direct SQL first: product 1 (SKU `24-MB01`) and product
6 (SKU `24-MB02`) are real members of category 3; product 47 (SKU
`MH01-XS-Black`) is confirmed NOT a member of category 3.

- Saved a real category boost: category 3, weight 0.6.
- Saved a real product boost: product 1, weight 0.7.
- Ran the real `MerchandisingBoostSignal` (real DI-wired readers, real
  DB) against three candidates, each with base score 0.2:

```
product 1  (both-boosted):     final_score = 1.2   (0.2 + min(1.0, 0.7+0.6) = 0.2 + 1.0)
product 6  (category-only):    final_score = 0.8   (0.2 + min(1.0, 0.0+0.6) = 0.2 + 0.6)
product 47 (control, neither): final_score = 0.2   (unchanged)
```

All three matched expected math exactly, proving the additive
combination, the cap, and that an unrelated product is untouched.
Both boosts deleted afterward.

**2. Real `Category\DataProvider::getData()` plugin:**

Saved a real category boost (category 3, weight 0.55). Constructed the
real `Magento\Catalog\Model\Category\DataProvider` via the real object
manager with the real request param (`id=3`) the real class reads, and
called its real `getData()`:

```
aavirbhava_category_boost_weight: 0.55
aavirbhava_category_boost_start_date: MISSING (null, open-ended boost)
real category 'name' field still present: true
```

Confirmed the field appears correctly in the real result array
alongside the category's own untouched real data — proving the real
`etc/adminhtml/di.xml` plugin registration actually fires, not just
the isolated unit test. Boost deleted afterward.

**3. Real `catalog_category_save_after` event → real observer → real
DB write:**

Deliberately dispatched the real event directly via
`EventManagerInterface::dispatch()` rather than calling
`$category->save()` on a real, existing sample-data category (category
4), specifically to avoid real reindex/`updated_at` side effects on
live catalog state during verification. Set the real request params
the real observer reads, then dispatched:

```
Before: findByCategoryId(4) = null

=== Real event dispatched for category 4 ===
boostWeight: 0.45
startDate:   '2026-04-01 00:00:00'   (correctly midnight — confirms the date-bug fix)
isActive:    true
```

Confirmed the real `etc/adminhtml/events.xml` observer wiring actually
persists the boost through the full real save path. Boost deleted
afterward; category 4 itself was never re-saved, so no reindex or
`updated_at` side effect occurred.

## Not done / blocked

Same standing limitation as every other admin-UI task in this module:
the actual rendered-HTML/click-through admin experience — both the new
category-form boost field and the standalone review grid — is
unconfirmed through a real authenticated browser session, since this
environment enforces a CAPTCHA on admin login and no browser-
automation tool exists in this session. Every other layer (schema, DI
compile, real ORM/integration tests, live ranking-signal effect, live
`DataProvider` plugin, live save-observer event dispatch) is
separately, genuinely verified above.
