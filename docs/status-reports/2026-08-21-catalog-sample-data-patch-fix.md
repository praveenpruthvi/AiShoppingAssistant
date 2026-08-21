# STATUS REPORT — Fix the long-standing `Magento_CatalogSampleData` setup:upgrade failure

Fixed the real root cause of a build/`setup:upgrade` error the user hit
directly: `Unable to apply data patch Magento\CatalogSampleData\Setup\
Patch\Data\InstallCatalogSampleData for module Magento_CatalogSampleData.
Original exception message: Rolled back transaction has not been
completed correctly.`

This was previously documented in CLAUDE.md (since Task 22) as a
pre-existing, unrelated environment issue and only ever worked around
(never root-caused) — including in this session's own Task 38 and
Task 40, both of which had to fall back to constructing their data
patches via the real object manager and calling `apply()` directly
because a normal `setup:upgrade` couldn't get past this failure.

## Root cause

The CLI's own error message is misleading — it's a transaction-state
symptom one layer removed from the real cause.
`Magento\Framework\Setup\SampleData\Executor::exec()` (the class that
runs every `*SampleData` module's installer) catches **any**
`\Throwable` from the installer, logs it as `"Sample Data error: " .
$e->getMessage()` to `var/log/system.log`, and does **not** rethrow —
so the outer `PatchApplier::applyDataPatch()` never sees the real
error at all.

Bypassing that catch-all by calling
`Magento\CatalogSampleData\Setup\Installer::install()` directly through
the real object manager surfaced the actual exception:

```
Magento\Framework\DB\Adapter\DuplicateException: SQLSTATE[23000]:
Integrity constraint violation: 1062 Duplicate entry '1' for key
'PRIMARY', query was: INSERT INTO `catalog_product_entity`
(`entity_id`, `attribute_set_id`, `type_id`, `sku`, `has_options`,
`required_options`) VALUES (?, ?, ?, ?, ?, ?)
```

Direct queries confirmed the full Luma sample catalog was **already
installed and complete**: 2,040 products (`entity_id` starting at 1,
`created_at` months before this session), 40 categories, 3,416
gallery-media rows. But `patch_list` was missing its one completion
row for `InstallCatalogSampleData` specifically — every one of the
other 18 sample-data module patches (Bundle/CatalogRule/Cms/
Configurable/Customer/Downloadable/GroupedProduct/Msrp/
OfflineShipping/ProductLinks/Review/SalesRule/Sales/Swatches/Tax/
Theme/Widget/Wishlist SampleData) **was** correctly recorded. So every
`setup:upgrade` run tried to re-install the entire catalog from
scratch and immediately collided with its own already-inserted first
row.

### Why the CLI's error message doesn't say any of that

`Magento\Framework\DB\Adapter\Pdo\Mysql` tracks transaction nesting via
`_transactionLevel` plus an `_isRolledBack` flag. The installer's own
nested transaction hits the real duplicate-key error and calls
`rollBack()` at a nested level (`_transactionLevel > 1`), which only
sets `_isRolledBack = true` and decrements the level — it does **not**
issue a real SQL `ROLLBACK` yet, since a nested rollback defers to
whichever caller owns the outermost transaction. `Executor::exec()`
then swallows the exception, so `InstallCatalogSampleData::apply()`
returns normally. Back in `PatchApplier::applyDataPatch()`, the outer
`commit()` call sees `_isRolledBack === true` and throws
`AdapterInterface::ERROR_ROLLBACK_INCOMPLETE_MESSAGE` — literally
"Rolled back transaction has not been completed correctly" — which is
what actually reaches the CLI.

## Fix

A genuine bookkeeping correction, not a workaround — the patch's real
effect was already 100% present and correct, so the fix only records
that accurately:

```sql
INSERT INTO patch_list (patch_name)
VALUES ('Magento\\CatalogSampleData\\Setup\\Patch\\Data\\InstallCatalogSampleData');
```

Caution for anyone repeating this: MySQL string-literal backslash-
escaping needs exactly `\\` per path separator to produce a stored
value with a single backslash per separator, matching every other row.
A first attempt through nested shell → `docker exec` → `mysql -e`
escaping accidentally doubled it to `\\\\` (stored as literal double
backslashes) — caught and corrected by comparing `HEX(patch_name)`
byte-for-byte against a known-good existing row before trusting the
insert, then re-done by piping a `.sql` file through `bin/mysql`'s
stdin path instead of `-e` to avoid the extra escaping layer.

## Verification

- Two full, clean `bin/magento setup:upgrade` runs, back-to-back, both
  exit `0` with no error.
- Confirmed this module's own data patches (`MigrateProviderCostConfig`,
  `SeedAttributeIndexingSelection`) now both appear correctly in
  `patch_list` via a **completely normal** `setup:upgrade` run — the
  object-manager construct-and-call-`apply()`-directly workaround
  Task 38/40 needed while this was broken is no longer necessary for
  future patches in this module.
- Spot-checked the sample catalog data is genuinely intact, not just
  "patch_list says so": 2,040 products, 40 categories, 3,416 gallery
  images, all present before and unchanged after.

## Files changed

None in the module's own codebase — this was a database-state
correction (`patch_list` table) plus documentation:

- `CLAUDE.md` — the environment-realities bullet rewritten from "known,
  unfixed issue, don't treat as a regression" to "resolved, with the
  real root cause, exact fix SQL, and the `HEX()` byte-check caution
  documented for reference"; the "Known open issues" bullet about this
  failure blocking the rest of a `setup:upgrade` run's data-patch queue
  marked resolved.
- `references/progress-log.md` — header summary replaced, new task
  history entry added.

## Not done / blocked

Nothing — this was a full root-cause fix with real, repeated
verification, not a disclosed gap or a re-applied workaround.
