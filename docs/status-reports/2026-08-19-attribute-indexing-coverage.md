# STATUS REPORT — Fix inconsistent attribute coverage in the RAG index

Real PDP attribute data (Style/Material/Pattern/Climate) exists for
hoodies and pants alike, but only `material` was reaching the model —
`climate`, `pattern`, and the style attributes never made it into the
index at all. Root-caused to the admin config's attribute list, not the
normalizer and not inconsistent catalog data, confirmed via direct SQL
against the real catalog and a direct OpenSearch document fetch, both
before and after the fix.

## Files created/changed

**Modified (production):** `etc/config.xml` — `indexing/
searchable_attribute_codes`'s shipped default broadened from
`manufacturer,color,size,material` to `manufacturer,color,size,material,
climate,pattern,style_general,style_bottom,activity,collar,sleeve`.

**Not code:** this environment's own already-stored admin config
override was also updated via `bin/magento config:set` (a config-data
change, not a code change), and a real `indexer:reindex ai_product_rag`
was run.

**No PHP files changed** — the diagnosis confirmed both candidate code
paths (`ProductAttributePolicy`, `ProductDocumentNormalizer`) already
correctly handle whatever attribute list they're given; the gap was
purely in what list they were given.

**Tests:** unchanged — 1396 unit tests, 3376 assertions, 0 failures,
before and after (a config-value fix introduces no new branchable PHP
logic to unit-test).

## Step 1 — diagnosis, via direct SQL and a real OpenSearch query

Read the code first, not assumed:

- `SearchableAttributeValueResolver` draws its attribute code list
  *entirely* from `IndexingConfigInterface::searchableAttributeCodes()`
  (admin config) — not a hardcoded set — and correctly handles both
  scalar and multiselect (option-id) attribute storage.
- `ProductAttributePolicy` is a denylist (secrets/internal fields), not
  an allowlist restricted to a fixed set of codes.
- `ProductDocumentNormalizer` normalizes whatever attribute list it's
  handed, with no hardcoded subset of its own — this directly and
  completely **ruled out** the task's third candidate hypothesis (a
  normalizer-level fixed subset), by inspection.

Checked the real configured value: the shipped default was
`manufacturer,color,size,material` — only 4 codes.

Queried the catalog's real EAV structure directly via SQL. The dominant
"Top" (hoodies, 1462 rows) and "Bottom" (pants, 532 rows) attribute sets
both define real `climate`/`pattern`/`style_general` or `style_bottom`/
`activity`/`collar`/`sleeve` fields. **An initial check against the
wrong EAV value table (`catalog_product_entity_int`) wrongly suggested
these were all empty** — re-checking against the *correct* table for
`multiselect`/`backend_type=text` attributes
(`catalog_product_entity_text`) found them fully, comprehensively
populated: `climate` and `pattern` on all 147 configurable products
catalog-wide, `material` likewise, `style_general`/`style_bottom`
together covering effectively the whole catalog. This reversal — catching
and correcting an initial wrong-table conclusion — is reported here
deliberately, not smoothed over.

Directly fetched MH08's (Oslo Trek Hoodie) real, live OpenSearch document
before any fix:

```json
"attributes": [
  {"code": "material", "label": "Material", "values": ["Organic Cotton", "Polyester", "Nylon"]}
]
```

`climate`/`pattern` were completely absent from both `attributes` and
`searchable_text`, despite being just as real and just as populated as
`material`. **This is the direct, confirmed proof: the gap is the admin
config list, not the normalizer, and not inconsistent underlying Magento
data** — the opposite, in fact: comprehensively populated, simply never
configured to be captured.

Cross-referencing an "Oslo Trek Hoodie made with organic cotton,
polyester, and nylon" claim from earlier live testing against this real
data confirmed it was **not** a hallucination as briefly suspected
mid-investigation — that's MH08's genuine, real PDP value.

## Step 2 — the fix

Broadened `searchable_attribute_codes`'s default to include `climate`,
`pattern`, `style_general`, `style_bottom`, `activity`, `collar`,
`sleeve` alongside the existing four codes — chosen specifically because
each is a genuinely descriptive PDP attribute (matching the task's own
"Style/Material/Pattern/Climate" framing) with real, broad population,
not a marketing/merchandising boolean flag.

**Deliberately did not add:**
- `new`/`sale`/`eco_collection`/`erin_recommends`/`performance_fabric` —
  real but only ~20-30% populated toggle flags, a different *kind* of
  attribute than a descriptive PDP fact.
- `category_gear`/`features_bags`/`strap_bags`/`style_bags`/`gender`/
  `format`/`country_of_manufacture` — checked and found genuinely 0%
  populated catalog-wide, or niche accessory-only fields.

A deliberate, disclosed scope boundary, not an oversight.
`max_attribute_values_per_product`'s default (100) has ample headroom
for the added codes — a typical product now resolves roughly 8-10 total
attribute values, nowhere near the cap.

## Step 3 — reindex and verify

Discovered, via direct SQL against `core_config_data`, that this
environment already had an explicit, previously-saved admin override at
the *old* 4-code value — updating only the module's shipped
`etc/config.xml` default had zero effect on this live environment's
actual behavior until this was found and fixed too, via the standard
`bin/magento config:set` (the proper, sanctioned way to change a live
admin config value, not a direct SQL edit).

Confirmed the change took effect (`readIndexing(1)->
searchableAttributeCodes()` returning all 11 codes), confirmed it
correctly marked the indexer "Reindex required," then ran a real
`indexer:reindex ai_product_rag` (completed in 6 seconds).

Re-fetched MH08's real OpenSearch document post-reindex:

```json
"attributes": [
  {"code": "climate", "values": ["Windy", "Cool"]},
  {"code": "material", "values": ["Organic Cotton", "Polyester", "Nylon"]},
  {"code": "pattern", "values": ["Solid"]}
]
```

`style_general` correctly absent for this specific SKU, matching its own
real, individually-sparse (85/98) coverage rather than a bug. Re-ran the
Task 24 index-coverage command to confirm the full reindex didn't disturb
overall catalog/index parity: still 181/181, fully covered.

## Live verification through the real chat pipeline

**"what climate are the mens hoodies suited for"** (single-turn) returned
a rich, fully grounded answer directly using real Climate option values
(All-Weather, Cool, Spring, Windy, Mild, Indoor, Cold, Wintry) — a clean,
unambiguous success.

**A two-turn "hoodies" → "which ones are cotton"** re-run (after
recreating this task's own scratchpad directory, found missing mid-task
and silently breaking an earlier cookie-jar-based two-turn attempt —
caught and fixed, not glossed over) produced:

- Turn 1 text now rich with real material/pattern/climate facts: *"made
  of wool, polyester, and nylon with solid pattern... all-weather and
  wind-resistant."*
- Turn 2 honestly and correctly concluded none of those specific hoodies
  are cotton — grounded and accurate, not a hallucination and not a
  blanket data-unavailability decline.

**Spot-checked beyond hoodies:** a yoga pant SKU's document now shows
`climate,material,pattern,style_bottom` (full coverage), and two
Gear/Bag-category SKUs show `activity,material` — real data reaching the
index even outside the two dominant attribute sets, confirming the fix
generalizes, not just the one reported hoodie case.

## Verification

Full suite unchanged at 1396 unit tests, 3376 assertions, 0 failures,
run before and after (a config-value fix introduces no new branchable PHP
logic to test). `etc/config.xml` validated as well-formed XML;
`setup:di:compile` not needed (no DI wiring changed). A real
`cache:flush` and real `indexer:reindex ai_product_rag` were run as this
task's actual container verification.

## Known gaps / TODOs left for later tasks

- `style_general`'s own 15/98 real gap on the "Top" set (some hoodies
  genuinely have no Style value set in Magento) is real, ordinary
  catalog data sparsity, not a bug — correctly reflected as absent
  per-product, not indexed as a blank/placeholder.
- The excluded merchandising boolean flags and niche Gear/Bag-only
  fields remain out of the indexed set — a deliberate, disclosed scope
  boundary rather than an oversight. A later task with a clearer signal
  that shoppers actually ask "is this on sale"/"is this eco-friendly"
  could reconsider that boundary.
- The model's own choice not to always draw on available carry-over/
  context data (seen once mid-verification — a turn calling
  `search_products` fresh instead of using genuinely available
  prior-turn hoodie context) remains the same, already-documented
  local-model reliability class this module has reported repeatedly
  (Tasks 18, 23, 25, 27, 28, 29) — not something an indexing-coverage
  fix can address.

## Skill files updated

`references/progress-log.md` — status row 4 updated; header summary
updated; a full Task 30 history entry added.

## Not done / blocked

Nothing blocked.
