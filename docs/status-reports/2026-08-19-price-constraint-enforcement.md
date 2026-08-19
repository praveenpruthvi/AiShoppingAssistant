# STATUS REPORT — Price constraint enforcement (fixing silent product loss)

The Task 24 debug log proved retrieval was never the problem: "find me
jackets below $60" returned `availability_filter: 8 → 8` — every real
candidate survived retrieval and live revalidation — yet only 4 of the 5
real qualifying products ended up in `products[]`. The LLM's own final
selection was silently dropping a genuine match. Fixed by detecting an
explicit price threshold in the customer's own query (simple regex) and
deterministically reconciling the validated response's `products[]`
against it in code — never trusting the model to apply a numeric
threshold correctly across a candidate list, and never asking it to try
again. Live-confirmed against this store's real catalog for three
different thresholds.

## Files created/changed

**New:**
- `Model/Chat/PriceConstraint.php` — a max/min price bound, each
  independently exclusive or inclusive.
- `Model/Chat/PriceConstraintDetector.php` — regex-based detection of
  a threshold from query text.
- `Model/Chat/PriceConstraintReconciler.php` — deterministic
  `products[]` correction against real, live-revalidated prices.
- `Model/Chat/PriceConstraintReconciliationResult.php` — the
  corrected response plus exactly which SKUs were added/removed.
- `Test/Unit/Model/Chat/{PriceConstraintTest,PriceConstraintDetectorTest,
  PriceConstraintReconcilerTest}.php` (9 + 11 + 8 tests).

**Modified (production):**
- `Model/Chat/ChatEntryPipeline.php` — two new constructor
  dependencies; the constraint is detected once right after scope
  classification; reconciliation runs once after the retry loop
  settles on a valid response, before persistence and before the
  response is returned.
- `Model/Chat/Debug/ChatDebugTrace.php` / `ChatDebugLogger.php` —
  three new fields (`priceConstraint`, `priceConstraintAddedSkus`,
  `priceConstraintRemovedSkus`) so the correction itself is directly
  visible in the same debug log that surfaced the bug.

**Modified (tests):** `Test/Unit/Model/Chat/ChatEntryPipelineTest.php`
(factory updated, net +3 end-to-end tests), `Test/Unit/Model/Chat/
Debug/ChatDebugLoggerTest.php` (updated for the new fields).

**Tests:** 31 net new (1341 → 1372 unit, 3245 → 3318 assertions), 0
failures.

## The bug, confirmed via the real debug log

```
"availability_filter":{"before_count":8,"after_count":8,"dropped_skus":[]}
"final_product_skus":["WJ09","MJ01","WJ08","WJ07"]
```

Cross-checked against each candidate's real, live price: Jade Yoga
Jacket $32, Beaumont Summit Kit $42, Proteus Fitness Jackshirt $45,
Adrienne Trek Jacket $57, Inez Full Zip Jacket $59 all genuinely
qualify for "below $60"; Riona Full Zip Jacket at exactly $60, Taurus
Elements Shell $65, and Orion Two-Tone Fitted Jacket $72 correctly
don't. **5 real products should have qualified — the model's own
selection silently dropped Proteus Fitness Jackshirt**, with nothing
anywhere telling the customer a real match was missing. Not a
retrieval bug, not a revalidation bug, not an `OutputValidator`
rejection — purely the model's own final selection under-counting
against a candidate list it had complete, correct access to.

## Which approach was chosen, and why

The task offered two options: (a) pre-filter the candidates handed to
the model so it can't select outside the correct set, or (b)
reconcile the response against a code-computed correct set afterward.
**Chose (b).**

**(a) was rejected for two concrete reasons**, found by tracing how
the existing pipeline actually uses its candidate/verified-product
sets:

1. It would remove the model's ability to mention real, priced
   alternatives in its own text ("all other jackets are priced above
   $60: ...") — a documented, desirable pattern since Task 22/23 —
   without also breaking `OutputValidator::containsFabricatedPrice()`'s
   "exempt a real, matching mention" check for exactly those
   alternatives, since that check validates against the same
   verified-product set retrieval would now be silently shrinking.
2. More fundamentally, pre-filtering candidates doesn't actually
   *guarantee* correctness at all. `OutputValidator` validates
   `product_skus` against the full verified set, not against "what the
   model was shown" — a smaller candidate list only reduces noise; it
   does nothing to structurally prevent the exact under-selection bug
   this task exists to fix.

**(b) closes the bug with certainty rather than probability.** It
reuses this pipeline's existing philosophy — verify strictly, never
trust the model for a fact code can compute (`OutputValidator`'s own
price/URL/SKU checks; `RevalidatedProduct` as the only source of truth
for price) — and, unlike Task 23's `ProductMentionCompletenessChecker`
retry (which asks the model to self-correct), needs no second model
round-trip at all, since the correct answer is already fully
computable from data already in hand. Given Task 23's own documented
finding that extra round-trips measurably raise real request latency
for no guaranteed benefit, a same-turn, zero-extra-call fix was the
clearly better fit here.

## Detection

`PriceConstraintDetector::detect()` — simple, regex-based per this
task's own instruction, mirroring `OutputValidator::
extractMentionedPrices()`'s existing currency-number pattern rather
than sharing it (the two solve different problems: one reads the
customer's query, the other checks the model's reply). Distinguishes
an exclusive bound ("under", "below", "less than", "cheaper than",
"over", "above", "more than" — strictly less/greater) from an
inclusive one ("up to", "no more than", "at least", "$60 or less" —
less/greater-or-equal), plus a direct "between $X and $Y" pattern for
a two-sided range.

## Reconciliation

`PriceConstraintReconciler::reconcile()` runs once, in
`ChatEntryPipeline::handle()`, right after the retry loop settles on a
valid response and before conversation persistence — using the
identical merged verified-product set `OutputValidator` already
validated the response against, so it can never add a SKU the turn
didn't actually have live, verified data for. A qualifying candidate
missing from `product_skus` is appended with an honest, code-generated
reason ("Priced at $45.00, matching your requested price range.") —
never a claim invented on the model's behalf. A selected product that
fails the constraint is removed, and any `AssistantAction` left
referencing a removed SKU is pruned (the whole action dropped if every
SKU was removed). A response needing no correction is returned as the
exact same object instance.

## Live verification — three thresholds, cross-checked against real data

**"find me jackets below $60"** — the model again selected only 4;
reconciliation added Proteus Fitness Jackshirt ($45) with the
generated reason `"Priced at $45.00, matching your requested price
range."`, landing on the correct 5-product set. Debug trace:
`"price_constraint":{"detected":{"max":60,"max_inclusive":false,...},
"added_skus":["MJ12"],"removed_skus":[]}`.

A repeat run where the model separately called `search_products`
mid-conversation (discovering two more real candidates beyond the
up-front retrieval set) still reconciled correctly against the full
merged verified set — adding a genuine match *and* removing one priced
at exactly $60 (correctly excluded from an exclusive "below" bound).
This confirms reconciliation isn't limited to the up-front retrieval
path.

**"find me jackets below $50"** — the model got all 3 real qualifying
products right unaided. `added_skus`/`removed_skus` both empty,
response object unchanged — reconciliation is a true no-op when
nothing needs correcting.

**"show me jackets over $60"** — a min-bound constraint, correctly
detected as `min: 60, exclusive`. The model again got it right unaided
(Orion Two-Tone Fitted Jacket $72, Taurus Elements Shell $65; a
candidate priced at exactly $60 correctly excluded) — reconciliation
again a no-op.

All four runs' `system.log` checked for the debug-log leakage class of
bug Task 24 fixed — none found.

## Verification

Full suite 1341 → 1372 unit tests (+31), 3245 → 3318 assertions, 0
failures, run before this task's changes and again after. `php -l` on
every changed/new PHP file, `setup:di:compile`, `cache:flush` clean
(no schema change, no console command added this task, `setup:upgrade`
not needed).

## Known gaps / TODOs left for later tasks

- The detector only recognizes a price threshold stated in the
  customer's own query text — a constraint stated only implicitly, or
  introduced earlier in a multi-turn conversation without being
  restated, is neither detected nor enforced on a later turn.
- Reconciliation only corrects `products[]`; an added product isn't
  retroactively woven into the response's own `message` text, so a
  customer may see a product card with no matching narrative sentence.
  An accepted, disclosed trade-off — a card with no text mention is a
  smaller problem than a real match silently missing.
- Non-price constraints (color, size, brand, category) are entirely
  out of this task's scope, per its own "keep it simple, price is the
  concrete case" framing.

## Skill files updated

`references/progress-log.md` — status row 6 updated; header summary
updated; a full Task 25 history entry added.

## Not done / blocked

Nothing blocked.
