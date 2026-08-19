# STATUS REPORT — Fix empty-products[] responses (retry-budget starvation)

The model could produce a fully product-specific text answer — naming
real, verified products with real prices — while `product_skus`/
`final_product_skus` shipped completely empty, with `outcome:
"generated"` (not rejected). Diagnosed via a direct raw-parse capture,
not assumption: the completeness checker's own matching logic was proven
*correct* for an empty array — the real cause was `ChatEntryPipeline`'s
shared 2-attempt retry budget being exhausted by an unrelated correction
before completeness ever got its own turn. Fixed with a targeted, bonus
completeness-only attempt that never inflates the common case's cost.

## Files created/changed

**Modified (production):** `Model/Chat/ChatEntryPipeline.php` — new
`MAX_TOTAL_ATTEMPTS` constant; the malformed/invalid-response branches
keep their original 2-attempt cap unchanged; the completeness branch now
gates on its own `$completenessRetryUsed` flag instead of the shared
attempt counter, guaranteeing it always gets one real try.

**Modified (tests):** `Test/Unit/Model/Chat/ChatEntryPipelineTest.php`
(+3 tests).

**Temporary, fully reverted:** `Model/Chat/OutputValidator.php` briefly
carried a raw-parse diagnostic capture during Step 1 — confirmed clean
via `grep -rn "file_put_contents"` before any verification ran.

**Tests:** 3 net new (1393 → 1396 unit, 3363 → 3376 assertions), 0
failures.

## Step 1 — reproduction

Live-sent "show me some hoodies for men" repeatedly. The model
consistently named real hoodies in text, but ~20 live attempts across
several phrasings never organically forced the exact zero-products
failure — the compound sequence this bug requires (a malformed response,
*then* a valid-but-incomplete one) is genuinely rare, and local-model
output is stochastic.

Rather than continuing to burn live-call budget hoping for luck, added a
temporary `file_put_contents()` capture directly inside
`OutputValidator::validate()` (this module's established
capture-then-revert technique), dumping every raw parsed response before
any processing. Real, captured evidence from that run:

```
call: product_skus_count=0, message: "...The Oslo Trek Hoodie (MH08) has
      organic cotton in its blend..."
call: product_skus_count=1, message: [same text, now with MH08 included]
```

**This directly disproves the most literal reading of the task's own
hypothesis** — a matching-logic gap specific to a totally-empty array.
`ProductMentionCompletenessChecker` caught this 0-of-1 miss and the
existing retry corrected it, exactly like a partial miss, whenever a
spare attempt was actually available. Reported honestly rather than
assumed confirmed.

## The real structural cause

Re-tracing the retry loop with that finding in hand: `ChatEntryPipeline`'s
single `MAX_STRUCTURED_OUTPUT_ATTEMPTS` (2) budget is shared across
**three** distinct retry purposes — malformed JSON (Task 16), an
invalid/empty provider response (Task 23), and completeness (Task 23).
The completeness branch's own guard:

```php
if ($missingProducts === [] || !$attemptsRemain) {
    break;
}
```

unconditionally gave up once `$attempt` reached the shared cap, **with no
retry sent, including on the attempt where a completeness gap is first
evaluated** — which happens precisely when an *earlier* attempt was
already spent correcting an unrelated malformed/invalid response. A
completeness gap that only surfaces on the final allowed attempt
therefore had exactly zero chance of ever being corrected.

**This is a budget-starvation bug, not a matching-logic bug — and it
applies to a partial miss exactly as much as a total one.** "Total" miss
was simply the shape that got reported and reproduced first.

`system.log`'s own real notice-level retry logging from earlier live
testing this session confirms the compound case's rarity: every
"retrying after a malformed structured-output response" and "retrying to
include products" notice captured so far shows `"attempt":1` — the
compound (attempt-1-consumed-by-something-else) case is real, just
uncommon in this environment.

## The fix

A new `MAX_TOTAL_ATTEMPTS = MAX_STRUCTURED_OUTPUT_ATTEMPTS + 1` (3)
constant, and a completeness-specific `$completenessRetryUsed` flag
replacing the shared attempt counter as *its* retry gate. The
malformed-response and `ProviderInvalidResponseException` branches keep
gating on a renamed `$complianceAttemptsRemain` — numerically identical
to the old logic — so their own cap and behavior are completely
unchanged; **they can never consume the bonus 3rd attempt.**

The ordinary case (a completeness gap on attempt 1, no compliance issue)
is unaffected — still exactly 2 calls total, proven by the untouched,
still-passing `testIncompleteProductsAreRetriedOnceAndTheRetryAddsTheMissingSku`.
The extra, 3rd call is paid **only** in the specific compound case this
bug requires — not blanket-raised for every turn's worst case, unlike
Task 23's own reverted attempt to fix a related latency concern by
raising `guardrails.max_tool_calls` across the board.

## Live verification

Re-ran "show me some hoodies for men" 5 times post-fix — every one
produced real, non-empty, grounded `final_product_skus`
(`outcome: "generated"` every time, confirmed via the real debug log).

Re-ran the exact two-turn **"hoodies" → "cotton materials"** sequence 4
times. One clean run's debug trace:

```
carried_over_skus: ["MH01","MH08","WH04","MH07","MH12","MH06","MH13","WH06"]
final_product_skus: ["MH01","MH08","WH04","MH07","MH12","MH06","MH13","WH06"]
outcome: "generated"
```

with a fully grounded turn-2 answer directly referencing the carried-over
products ("Of the hoodies I showed you, two are cotton...").

The exact malformed-then-empty compound sequence did not recur live
within this session's remaining test budget — deterministic unit tests
instead directly reproduce and prove the fix for that specific sequence,
the appropriate verification for a compound event this rare to force on
demand.

## Honestly reported: a separate, still-present limitation

One of the four two-turn re-runs showed turn 1 succeeding with 8 real
products (`carried_over_skus` correctly populated for turn 2), **yet turn
2 still failed with `fabricated_sku`**. Checking `system.log` for that
exact turn: no retry notice fired at all — the model's *first* attempt
already selected a SKU outside the verified set (including the 8 real
carried-over ones), and `fabricated_sku` is deliberately never retried by
this pipeline's existing design (retrying a hallucination risks
encouraging another one).

This is a genuine, pre-existing local-model reliability limitation,
entirely separate from the bug this task fixed — carry-over correctly
made real data available, the model simply didn't use it correctly on
that attempt. **Not claimed as fixed by this task.**

## Verification

Full suite 1393 → 1396 unit tests (+3), 3363 → 3376 assertions, 0
failures, run before this task's changes and again after. `php -l` on
every changed file, `setup:di:compile`, `cache:flush` clean (no
schema/DI-wiring change, no console command, `setup:upgrade` not
needed). Confirmed the temporary raw-parse capture was fully reverted
before this verification ran.

## Known gaps / TODOs left for later tasks

- The fabricated_sku-on-first-attempt limitation above remains open —
  the same class of "local model invents something despite correct data
  being available" limitation this module has documented repeatedly
  (Tasks 18, 23, 25, 27, 28), not something a retry-budget fix can
  address.
- The bonus completeness attempt is bounded at exactly one — a turn
  needing *two* separate compliance corrections (e.g. malformed on
  attempt 1, invalid-response on the completeness-bonus attempt,
  incomplete on a hypothetical 4th) would still exhaust its budget and
  ship best-available. Judged an acceptably rare compound-of-compound
  case, not worth a further, more open-ended budget increase.

## Skill files updated

`references/progress-log.md` — status row 6 updated; header summary
updated; a full Task 29 history entry added.

## Not done / blocked

Nothing blocked.
