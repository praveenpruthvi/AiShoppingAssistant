# STATUS REPORT — System prompt refinement and price-detector coverage

Two independent fixes: (A) a leading persona + strict-grounding paragraph
added to the one system message present on every turn, closing a real
gap found by auditing both places the model's instructions are actually
assembled — neither carried a "you are a shopping assistant" statement,
and the existing anti-fabrication rule only ever reached the model on a
turn with candidates. (B) `PriceConstraintDetector` gained "within $X" (a
confirmed gap) and "around $X" (a new, deliberately range-shaped
pattern); "budget of $X"/"$X budget"/"$X or under" were checked and found
already covered. `OutputValidator` is unchanged throughout — this is
prompt-side prevention, not a new enforcement layer.

## Files created/changed

**Modified (production):**
- `Model/Chat/ResponseContractFormatter.php` — new leading persona +
  strict-grounding paragraph; every existing paragraph (JSON shape,
  product_skus completeness, the "not a tool" warning, reason
  authenticity) kept character-for-character unchanged.
- `Model/Chat/PriceConstraintDetector.php` — `'within'` added to
  `INCLUSIVE_MAX_PHRASES`; new "around $X" symmetric-range handling
  with a new `AROUND_TOLERANCE` constant.

**Modified (tests):** `Test/Unit/Model/Chat/
ResponseContractFormatterTest.php` (+3), `Test/Unit/Model/Chat/
PriceConstraintDetectorTest.php` (+7).

**Not touched:** `ProductContextFormatter.php` (its own grounding
sentence from Task 26 was deliberately left as-is — see below),
`PriceConstraint.php` (its `isSatisfiedBy()` logic already supported
everything the detector change needed).

**Tests:** 10 net new (1381 → 1391 unit, 3332 → 3357 assertions), 0
failures.

## Part A — system prompt refinement

Audited both places the model's system/instruction message is actually
assembled before writing anything:

- `ResponseContractFormatter` — always included, every turn.
- `ProductContextFormatter` — included only when this turn has
  candidates (`$candidates !== []`).

Neither carried a persona statement anywhere. And neither had a rule
against inventing a fact absent from this turn's data that was
guaranteed to reach the model on *every* turn —
`ProductContextFormatter`'s own "never invent a SKU/price/stock/URL"
sentence (added in Task 26) only sends when candidates exist, so a turn
where retrieval genuinely found nothing had no such reinforcement beyond
the response contract's narrower "only SKUs you were actually shown"
line.

**Added, as a new leading paragraph in `ResponseContractFormatter`:**

> You are a shopping assistant for this store. You help customers find,
> compare, and learn about real products and services this store
> actually sells, using only the retrieved candidates, live tool
> results, and any product carried over from earlier in this
> conversation that are actually provided to you for this turn. Never
> invent a product, price, SKU, URL, stock status, or attribute that is
> not present in that data — not even one you believe is a plausible,
> realistic product for a store like this. If nothing provided to you
> for this turn actually matches what the customer is asking for, say so
> plainly instead of describing something that merely sounds right.

Placed in `ResponseContractFormatter`, not `ProductContextFormatter`,
specifically because it's the one message present unconditionally — the
"say so plainly" instruction needs to reach the model even on a
no-candidates turn. It deliberately overlaps with
`ProductContextFormatter`'s own sentence rather than replacing it — the
same redundant-validation philosophy this codebase already uses
elsewhere (e.g. `AbstractEmbeddingProvider` re-checking fields its own
DTO already guarantees): two independent reinforcements of the same
rule are cheaper insurance than one.

**Every existing instruction was kept verbatim** — this was strictly
additive, not a rewrite. `OutputValidator`'s fabricated_sku/
fabricated_price/fabricated_url checks are entirely unchanged and remain
the actual enforcement boundary; this prompt change can only reduce how
often a response needs rejecting or reconciling in the first place,
never substitute for those checks.

## Part B — price-detector coverage

**"within $X" — a real, confirmed gap.** Live-reproduced "show me price
within $50" detecting no constraint at all before this fix.
`OutputValidator`'s own, separately-maintained threshold-phrase list has
included `'within'` since Task 22, but `PriceConstraintDetector` (a new,
independent detector built in Task 25) never had it added to its own
list. Fixed by adding `'within'` to `INCLUSIVE_MAX_PHRASES`, matching
"up to"/"no more than"'s existing inclusive-bound semantics.

**"around $X" — a genuinely new pattern, deliberately not a single max
bound.** "around"/"about" mean "somewhere near this figure," not "up to
this figure" — a customer asking for something around $50 would still
reasonably expect a genuinely close $55 item to surface, which folding
"around" into `INCLUSIVE_MAX_PHRASES` would have incorrectly excluded.
Modeled instead as a symmetric ±20% band producing both a min and a max
— "around $50" detects as $40–$60, inclusive both ends. A simple,
easily-explained figure, not one derived from UX testing. Deliberately
"around" only, not also "about": "about" collides with its far more
common non-price sense ("tell me about $50 gift cards" means the $50
gift card product line specifically, not "somewhere near $50"), and
treating that as a fuzzy range would be a real regression, not a
coverage improvement — confirmed with a test asserting "tell me about
$50 gift cards" detects no constraint.

**Already covered — checked, not assumed.** "budget of $X" (backward
phrase), "$X budget" (bare "budget" already checked in both backward and
forward context), and "$X or under" (already in
`INCLUSIVE_MAX_PHRASES`) were all read against the existing code before
touching anything. All three already worked — no code change needed;
each got a new test instead, converting "should already work" into
"verified to work."

## Live verification

**"show me price within $50"** — a first run hit a genuine, unrelated
`assistant_unavailable` provider hiccup (an already-documented
local-model flakiness class, not caused by this task), but the debug
trace still showed:

```
"price_constraint":{"detected":{"max":50.0,"max_inclusive":true,"min":null,"min_inclusive":true},...}
```

confirming detection happens before the provider call and is independent
of whether that call succeeds. A retry produced a full real response:
every one of 12 real products returned priced at $50 or under, several
added by the existing `PriceConstraintReconciler` (Task 25) with its own
`"Priced at $X.00, matching your requested price range"` reason — direct
proof the "within" fix and the earlier reconciler now chain together
correctly.

Several other varied real queries confirmed the persona/grounding change
introduces no fabrication:

- **"do you sell snowboards"** (a product this store genuinely doesn't
  carry) — correctly answered "does not carry snowboards... I didn't
  find any snowboard products" with an empty `products` array, rather
  than inventing one.
- **"what are your yoga pants made of"** — returned a rich, fully
  real-attribute-grounded answer: 8 named products, 8 matching
  `product_skus` entries, real material attributes (CoolTech™,
  LumaTech™, organic cotton, Cocona®) matching the actual catalog.
- **A fresh two-turn conversation** ("show me jogging pants" / "the
  cheaper one") confirmed Task 26's follow-up carry-over still works
  correctly under the new prompt wording — no regression.

`system.log` was checked for the Task 24 debug-log-leakage class of bug
across every live query this task ran — none found.

## Verification

Full suite 1381 → 1391 unit tests (+10), 3332 → 3357 assertions, 0
failures, run before this task's changes and again after. `php -l` on
every changed file, `setup:di:compile`, `cache:flush` clean (no schema
change, no new DI-wired class, no console command, `setup:upgrade` not
needed).

## Known gaps / TODOs left for later tasks

- The persona/grounding paragraph is a prompt-level nudge, exactly like
  every other instruction in this file — it measurably reduces how
  often the model states something ungrounded, per this task's own live
  testing, but cannot *guarantee* it the way `OutputValidator` does. A
  genuinely convincing hallucination could still slip past a purely
  textual instruction and would still need `OutputValidator` to catch
  it, unchanged.
- `AROUND_TOLERANCE`'s ±20% figure is a simple, reasoned choice, not one
  validated against real customer query logs or A/B data — a later task
  with real usage data could tune it.
- "about $X" remains deliberately undetected — a disclosed trade-off,
  not an oversight.

## Skill files updated

`references/progress-log.md` — status rows 6 and 8 updated; header
summary updated; a full Task 27 history entry added.

## Not done / blocked

Nothing blocked.
