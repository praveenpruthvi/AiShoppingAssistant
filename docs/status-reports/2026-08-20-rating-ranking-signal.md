# STATUS REPORT — RatingSignal: Bayesian-weighted product rating in the ranking pipeline

Added a 5th ranking signal, `RatingSignal`, additive to the existing 4
(text relevance, vector similarity, attribute match, availability).
Rating data (average, review count) is now indexed from Magento's
native review system into the same OpenSearch product index, on the
existing batch/cron indexer only. Score is a Bayesian/IMDB-style
weighted average, not a raw average, so a single 5-star review can
never outrank a well-established 500-review 4.7-star product, and a
0-review product falls back to the catalogue-wide mean with no
special-case branch. Signal weight is admin-configurable, default kept
conservative. Live-verified against this store's real 181-product
catalog end to end: real data indexed and correctly converted, a
consistent denormalized catalogue average across every document, and
a live, un-mocked run of the real ranking pipeline confirming the
signal nudges without dominating.

## Files created/changed

**New:**
- `Api/Catalog/ProductRatingResolverInterface.php`,
  `Model/Catalog/ProductRatingResolver.php` — reads real rating facts
  from Magento's native review system (`Magento_Review`) via
  `Magento\Review\Model\ResourceModel\Review\Summary`'s own
  `appendSummaryFieldsToCollection()` join mechanism (the same one
  Magento's own catalog/search listings use for star ratings), plus a
  `catalogAverage()` aggregate query and the one `percentToStars()`
  (0-100 → 0-5) conversion point.
- `Model/Ranking/Signal/RatingSignal.php` — the new
  `RankingSignalInterface` implementation.
- `Test/Unit/Model/Catalog/ProductRatingResolverTest.php`,
  `Test/Unit/Model/Ranking/Signal/RatingSignalTest.php`.

**Modified (production):**
- `Api/Catalog/ProductSnapshotInterface.php` /
  `Model/Catalog/ProductSnapshot.php` — 3 new trailing fields
  (`ratingAverage`, `reviewCount`, `catalogRatingAverage`), validated
  0-5 / non-negative / 0-5.
- `Api/Catalog/ProductDocumentInterface.php` /
  `Model/Catalog/ProductDocument.php` — same 3 fields, same
  trailing-optional-parameter pattern used throughout this session.
- `Model/Catalog/ProductDocumentNormalizer.php` — passes the 3 fields
  into `$completePayload` only, never `$embeddingPayload` or
  `searchableText`, so a rating change never triggers re-embedding.
- `Model/Catalog/ProductSnapshotProvider.php` — new
  `ProductRatingResolverInterface` constructor dependency; calls
  `appendToCollection()` before the product collection loads and
  `catalogAverage()` once per batch.
- `Api/Indexing/ProductIndexMappingInterface.php` (3 new `FIELD_*`
  constants, `MAPPING_VERSION` 2→3), `Model/Indexing/Mapping/
  ProductIndexMapping.php` (field types), `Model/Indexing/Document/
  IndexedDocumentPayloadBuilder.php` (3 new payload keys).
- `Model/Retrieval/SearchQueryBuilder.php` (3 new `SOURCE_FIELDS`),
  `Model/Retrieval/SearchHitParser.php` (lenient `?? default` parsing,
  matching name/shortDescription's existing leniency — not the
  fail-closed identity-field pattern), `Model/Retrieval/
  SearchCandidate.php` (3 new trailing public readonly fields;
  `withScore()` now threads them through its reconstruction).
- `etc/config.xml`, `etc/adminhtml/system.xml`, `Model/Config/Path.php`,
  `Model/Config/ConfigurationReader.php` (new
  `retrieval/rating_signal_weight`, default 0.1, bounds [0,1], a new
  `readFloat()` helper mirroring `readInt()`'s exact shape),
  `Api/Config/RetrievalConfigInterface.php` /
  `Model/Config/RetrievalConfig.php` (new `ratingSignalWeight()`).
- `etc/di.xml` — new `ProductRatingResolverInterface` preference;
  `rating` signal registered between `attribute_match` and
  `availability`.
- `composer.json` / `etc/module.xml` — new `magento/module-review`
  dependency, `Magento_Review` added to the module sequence.

**Test fixtures updated:** `CatalogSnapshotFactory`,
`FakeProductDocumentFactory`, `ProductSnapshotProviderTest`,
`ProductSnapshotTest`, `ProductDocumentTest`,
`ProductDocumentNormalizerTest`, `SearchCandidateTest`,
`SearchHitParserTest`, `IndexedDocumentPayloadBuilderTest`,
`ProductIndexMappingTest`, `ConfigurationReaderTest`,
`RankingPipelineTest`.

**Not code:** `references/progress-log.md` (header summary, status
rows 4 and 10, new Task 31 history entry) and `CLAUDE.md` (dropped the
now-stale attribute-coverage "known open issue," fixed in Task 30 but
never removed from that file until now).

## Key design decisions

### The Bayesian formula, not a raw average

```
WR = (v / (v + m)) * R + (m / (v + m)) * C
```

`R`/`v` are the candidate's own average rating and review count, `C`
is the catalogue-wide mean rating, `m` is a fixed internal smoothing
constant (10 — not admin-configurable; only the signal's overall
weight is, the same way `AttributeMatchSignal`'s own boost curve is
fixed while its place in the pipeline is configurable).

Verified on paper before writing any code: R=5.0,v=1 vs R=4.7,v=500,
C=3.5,m=10 → WR≈3.636 vs WR≈4.677 — correctly ranks the 500-review
product higher. A 0-review product has v=0, so `WR` reduces to exactly
`C` with no special-case branch — this satisfies the task's explicit
"no separate branch" requirement by construction, not a guard clause.

### `C` denormalized at index time, not live-queried at rank time

`ProductRatingResolver::catalogAverage()` runs one SQL aggregate
(`AVG(rating_summary)` over reviewed products only — 0-review products
are deliberately excluded from the aggregate itself, since including
them would drag the prior toward zero and make the Bayesian blend
meaningless) once per indexing batch, and the result is stamped onto
every product document. `RatingSignal::apply()` reads it straight off
`SearchCandidate` at ranking time, staying a pure, network-free
function exactly like the 4 existing signals — never an OpenSearch
aggregation per ranking pass, which would be the only signal in the
pipeline carrying a live network cost per request.

### Signal ordering

Registered between `attribute_match` and `availability` in
`etc/di.xml`, preserving the existing, explicitly-documented invariant
that `AvailabilitySignal` runs last and is the authoritative gate
regardless of what upstream signals scored. A dedicated
`RankingPipelineTest` case wires all 5 real signal classes together
(not fakes) and confirms a disabled-but-highly-rated candidate is
still demoted to the bottom.

### `SearchCandidate::withScore()` reconstruction hazard — caught before shipping

`withScore()` rebuilds a brand-new immutable instance from scratch
rather than mutating one field. Had the 3 new rating fields not been
threaded through that reconstruction, any signal running after
`RatingSignal` — including the real `AvailabilitySignal` it sits in
front of — would have silently reset them to zero on its own
`withScore()` call, breaking the signal for most of the pipeline.
Fixed and covered by
`SearchCandidateTest::testWithScorePreservesRatingFieldsAcrossReconstruction`.

### OutputValidator decision (explicit, per CLAUDE.md's instruction)

CLAUDE.md instructs that "new product-fact-bearing features (ratings,
promotions, etc.) must add their own OutputValidator check." **No new
check was added, deliberately.** Rating data never reaches the LLM's
context (not added to `ProductContextFormatter`) and never reaches the
customer-facing response schema — it is a purely internal ranking
input read directly off `SearchCandidate` inside `RankingPipeline`,
never serialized, never sent to a provider, never shown to a customer.
There is no path by which the LLM could fabricate a rating claim
through this feature, unlike price/URL/SKU, which the model's own
free-text response can mention and which `OutputValidator` therefore
must check. Stated here explicitly, per that instruction's own "must"
phrasing, rather than silently assumed — a future task that *does*
expose rating text to the LLM or customer (e.g. "4.5-star product")
would need to revisit this and add a check then.

### Mapping version bump

`MAPPING_VERSION` 2→3, this module's own documented alias-activation
compatibility-proof mechanism, forcing a real full reindex rather than
an incremental write into an old-shaped physical index. Confirmed
necessary and sufficient by the live reindex below completing cleanly.

## Verification — full test suite

- **Before this task (baseline, re-run to confirm real):** 1396 tests,
  3357 assertions, 0 failures.
- **After:** **1418 tests, 3432 assertions, 0 failures, 0 errors**
  (net +22 tests, +75 assertions).
- `php -l` run across every new/changed file individually, plus a full
  `find Api Model Test -name '*.php' | xargs php -l` sweep of the
  whole module — clean.
- `di.xml`, `config.xml`, `system.xml`, `module.xml` confirmed
  well-formed XML via `DOMDocument`.

## Verification — live, real container

- `bin/magento setup:upgrade` (new `Magento_Review` dependency),
  `bin/magento setup:di:compile` (clean, no errors), `bin/magento
  cache:flush`, then a real `bin/magento indexer:reindex
  ai_product_rag` — rebuilt in 5 seconds against this store's actual
  181-product catalog.
- Re-ran the Task 24 `index-coverage` command post-reindex: still
  181/181, fully covered — the mapping-version bump and new indexed
  fields did not disturb catalog/index parity.
- Queried the live OpenSearch alias directly:
  - Real documents carry `rating_average`/`review_count` converted
    correctly from Magento's native 0-100 `rating_summary` (e.g. 90.0%
    → 4.5 stars, matched against `24-MB04`'s real data).
  - A 0-review product (`24-WG084` and others) carries
    `rating_average: 0`, `review_count: 0`, with `catalog_rating_average`
    still populated.
  - Every one of the 181 documents carries the identical denormalized
    `catalog_rating_average` (3.5632) — confirmed via an OpenSearch
    terms aggregation returning exactly one bucket across all 181 docs.
- Live-ran the actual `HybridRetrievalService` → `RankingPipeline`
  (bypassing the LLM entirely, avoiding Ollama latency and any risk of
  the model's own compliance noise) for a real `"shirt"` query at the
  shipped default weight (0.1):
  - Every candidate's rating-stage delta stayed small (~0.06-0.075)
    against text/vector-relevance scores of ~0.8-1.7 — the signal
    nudges, it does not dominate.
  - The final top-8 ranking was still led by the strongest text/vector
    matches (`WS12`, `MS03`, `MS04` — the 3 highest pre-rating scores)
    ahead of 2 zero-review, still-relevant candidates, and well ahead
    of lower-relevance candidates regardless of their rating — a
    well-matching product outranks a well-rated-but-less-relevant one,
    exactly as the conservative-default requirement intends.
  - All 8 zero-review candidates in that real query received the
    identical rating-stage delta (0.0713 = 3.5632/5.0 × 0.1),
    live-confirming the no-special-case fallback end to end, not just
    in unit tests.

## Pre-existing, unrelated environment issue (not caused by this task)

`bin/magento setup:upgrade` reports:

```
Unable to apply data patch Magento\CatalogSampleData\Setup\Patch\Data\InstallCatalogSampleData
for module Magento_CatalogSampleData. Original exception message:
Rolled back transaction has not been completed correctly.
```

on every run, including a clean re-run. Confirmed via the `patch_list`
table that this patch has **never** successfully applied in this
environment (no row for it at all) — a broken `Magento_CatalogSampleData`
data patch entirely unrelated to this module or `Magento_Review`. It
did not block `setup:di:compile`, the real reindex, or any live
verification above. Flagged here rather than silently worked around,
since fixing an unrelated core sample-data patch is out of this task's
scope.

## Known gaps / TODOs left for later tasks

- `m` (the Bayesian smoothing constant) is a fixed internal constant,
  not admin-configurable, per this task's own explicit scope ("only
  the overall signal weight is configurable"). A future task could
  reconsider this if a merchant genuinely needs a different
  minimum-votes threshold.
- `FullProductReindexer` leaving prior run-indices behind in
  OpenSearch (flagged Task 16, still unaddressed — 17 physical indices
  observed for this one store during this task's own live
  verification) is unrelated to this task and remains open.
- The unrelated `Magento_CatalogSampleData` patch failure above is
  noted, not fixed — out of this task's scope.

## Skill files updated

`references/progress-log.md` — status rows 4 and 10 updated, header
summary updated, a full Task 31 history entry added. `CLAUDE.md` —
dropped the now-stale attribute-coverage "known open issue" (fixed in
Task 30) and added a note that the "Ranking signal: product rating"
section now documents an implemented feature, not a pending spec.

## Not done / blocked

Nothing blocked.
