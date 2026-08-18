# STATUS REPORT — Robustness and consistency: real query-variation testing, remaining price/URL false positives, completeness, image fill

Five parts: (A) two more genuine price-filter bugs found and fixed
beyond Task 22's earlier fix, plus a matching false positive in the URL
check, plus the real previously-undiagnosed cause of most
`assistant_unavailable` occurrences; (B) "here are 2 jackets" rendering
only 1 card, traced to the model naming a product in text without
selecting its SKU, fixed with a new completeness check + retry; (C) the
placeholder-description text, confirmed model-authored and fixed by
prompting; (D) product-card images now fill their frame consistently on
both themes; (E) a broad, realistic 19-query × 3-rep test (57 real
Ollama calls) built to measure genuine reliability across real query
variation, not just repeated clean examples — used to drive the fixes
above, then re-verified with a targeted 12-call confirmation run
reaching 11/12 (91.7%) success.

## Files created/changed

**New:**
- `Model/Chat/ProductMentionCompletenessChecker.php` — mechanical
  (exact literal substring, not fuzzy NLP) check for a verified
  product named in message text but missing from `product_skus`.
- `Test/Unit/Model/Chat/ProductMentionCompletenessCheckerTest.php`
  (6 tests).

**Modified (production):**
- `Model/Chat/OutputValidator.php` — price-threshold detection
  broadened and fixed (new backward + forward threshold-phrase lists,
  context-window bleed fix so one threshold word can no longer
  "reuse" itself across an adjacent, unrelated price); `containsUrl()`
  rewritten as `containsFabricatedUrl()`, exempting any URL that
  matches a real revalidated product's URL.
- `Model/Chat/ChatEntryPipeline.php` — retry loop restructured around
  a `$bestValidValidation`/`$bestValidToolResult` fallback pair; new
  `ProviderInvalidResponseException`-specific catch + retry (separate
  from genuine-availability `ProviderException`, which still
  short-circuits immediately, unchanged); new completeness-retry
  branch driven by `ProductMentionCompletenessChecker`; new
  `logProviderFailure()` / `missingProductsCorrectionMessage()`
  helpers; new `ProductMentionCompletenessChecker` constructor
  dependency.
- `Model/Chat/ResponseContractFormatter.php` — two new instructions:
  never call a tool named `"product_skus"` (it's a JSON field, not a
  tool), and each `reason` must be a genuine, customer-useful
  description, never a bare price-comparison restatement.
- `Model/Config/ConfigurationReader.php` — `DEFAULT_MAX_TOOL_CALLS`
  tried at 6, reverted to 4 after live data (see Part A below); a
  code comment now documents the attempt and the reason for reverting
  it, so it isn't retried blindly in a future task.
- `view/frontend/templates/chat/{widget,widget-hyva}.phtml` —
  product-image `object-fit`/`object-*` switched from `contain` to
  `cover` on both themes.

**Modified (tests):** `Test/Unit/Model/Chat/OutputValidatorTest.php`
(net +8), `Test/Unit/Model/Chat/ChatEntryPipelineTest.php` (net +6),
`Test/Unit/Model/Chat/ResponseContractFormatterTest.php` (net +2).

**Tests:** 21 net new (1298 → 1319 unit, 3150 → 3197 assertions), 0
failures.

## Conventions followed

Every fix in this task followed the module's established
capture-then-immediately-revert diagnostic discipline: temporary
`file_put_contents()` logging added directly to `OutputValidator.php`,
`ChatEntryPipeline.php`, and `ChatHttpTransport.php` to capture real
raw model output, real HTTP response bodies, and previously-silently-
discarded exception detail — always fully reverted right after use,
confirmed via a final `grep -rn "file_put_contents"` returning
nothing. The URL fix reuses the exact "exempt only a real, matching
mention" shape Task 22's price fix already established, rather than
inventing a new pattern. The completeness retry reuses
`ChatEntryPipeline`'s existing `MAX_STRUCTURED_OUTPUT_ATTEMPTS`-based
retry budget for a third purpose (malformed JSON, invalid/empty
response, now incompleteness too) instead of adding a separate retry
mechanism alongside it.

## Deviations from existing conventions

None.

## Part A — price-filter reliability, the real remaining causes

Reproduced the identical "show me jackets less than $40" query
repeatedly and found Task 22's earlier fix, while real, wasn't the
whole story. Two more genuine bugs, found by reading the actual regex
logic against real captured text, not by assuming "model
inconsistency":

1. **A missing threshold word.** `"all other jackets exceed $40"` was
   rejected outright even though the same message's own `"is under
   $40"` was already correctly exempted — `exceed` was simply absent
   from the threshold-word list. Fixed by substantially broadening the
   list (exceed(s)/exceeding, priced at/under/over/below/above, costs/
   costing less/more than, greater/higher/lower than, beyond, in
   excess of, starting at/from, range/ranging, ceiling/cap of, as low/
   high as, and more).
2. **A context-window bleed bug.** The backward-only lookback window
   that decides whether a price is threshold-qualified could let one
   threshold word "leak" across an intervening, unrelated price:
   `"...is under $40, with a price of $32"` incorrectly exempted the
   genuine $32 claim from ever being checked, because `"under"`
   (qualifying $40) fell inside $32's naive 30-character backward
   window. Root-caused with a precise character-offset diagnostic
   script before writing any fix. Fixed by clipping both the backward
   window AND a newly added forward-looking window to the previous/
   next matched price's actual string offset, so no threshold word can
   be "reused" across more than the one price it actually qualifies.
   This also activated a forward-phrase list (`"or less"`, `"or
   under"`, `"and below"`, etc.) that was previously dead code, since
   the original implementation only ever looked backward.

**A second false positive, found the same way, in the URL check.**
`containsUrl()` rejected *any* URL the model mentioned at all — live-
caught rejecting `https://magento.test/jade-yoga-jacket.html`, a
100% real, accurate product URL the model had legitimately retrieved
via `get_product_details` and repeated back in a "compare these two
products" answer. Renamed to `containsFabricatedUrl()` and given the
exact same "only reject a non-matching mention" shape
`containsFabricatedPrice()` already used: a URL only fails the check
now if it doesn't match any revalidated product's real URL.

**The real, previously-silent cause of most `assistant_unavailable`
occurrences.** `ChatEntryPipeline`'s `catch (ProviderException) { ... }`
had no variable bound and logged nothing — genuinely undiagnosable
without instrumentation, which this task added (temporarily, reverted
after use). Real captured HTTP bodies showed every provider call
itself succeeded (status 200, well-formed JSON) — the *content* was
the problem: the model sometimes emits a tool call literally named
`"product_skus"`, confusing the JSON response-schema's field name with
a real callable tool. That call always fails as `unknown_tool`,
burning a round of `guardrails.max_tool_calls`, and once the round
budget runs out and the model is force-answered with no tools offered,
it sometimes returns a genuinely empty completion — thrown as
`ProviderInvalidResponseException` well before `OutputValidator` ever
sees it. Fixed two ways: (1) `ResponseContractFormatter` now
explicitly warns against calling a tool named `"product_skus"`; (2)
`ChatEntryPipeline`'s retry loop now also retries once specifically on
`ProviderInvalidResponseException` (never on a genuine availability
exception — those are unchanged and still short-circuit immediately).
The catch block now logs the real exception via a new
`logProviderFailure()`, closing the diagnosability gap for good.

**A real trade-off tried and reverted.** Reasoned that raising
`guardrails.max_tool_calls`'s default from 4 to 6 would give the model
more slack to recover from a wasted round before being force-answered.
Implemented, then the broad Part E test below showed the opposite of
the intended effect for genuinely ambiguous queries: more rounds
mostly meant "spend longer failing to find anything," not converting a
failure into a success. Worse, since each `converse()` attempt already
costs up to `maxToolCalls`+1 real provider calls and
`ChatEntryPipeline`'s own retry budget can invoke `converse()` twice,
six rounds pushed the theoretical worst case to 14 real calls (~280s
at the 20s default LLM timeout) — and this environment's nginx has a
real, unoverridden ~60s default `fastcgi_read_timeout`, confirmed by
inspecting the actual config, not assumed. Reverted to 4; a targeted
re-test of the same previously-timing-out queries afterward all
completed well under that ceiling. Kept: the prompt fix and the
invalid-response retry, both of which address the same root confusion
without touching the round-cap ceiling at all.

## Part B — "here are 2 jackets" rendering only 1 card

Traced precisely rather than guessed at the three hypotheses the task
raised. `final_products` candidate caps weren't the cause — 8
candidates were consistently retrieved and threaded into context, more
than enough for a 2-jacket answer, confirmed via a direct retrieval
query. `OutputValidator`'s `fabricated_sku` check couldn't be the
cause either — it's all-or-nothing per response (any unverified SKU
rejects the *whole* response), with no mechanism to silently drop one
product while keeping the rest. The real cause, confirmed by reading
live-captured model output: the model itself sometimes names a real,
verified second product in its message text without selecting its SKU
into `product_skus`, despite Task 18's existing instruction to do
exactly that.

**Fix:** a new `ProductMentionCompletenessChecker`, deliberately
mechanical — exact, case-insensitive literal product-name substring
matching, not fuzzy NLP, so it never fires a false-alarm retry — wired
into `ChatEntryPipeline`'s existing retry budget. A valid-but-
incomplete response gets one retry naming exactly which SKU(s) were
missing; if still incomplete afterward, the response is used as-is
rather than discarded, since a response with 1 real card is strictly
better than the generic fallback with none. A regressed retry (the
correction attempt coming back malformed/fabricated instead of better)
falls back to the earlier valid response via a new
`$bestValidValidation` tracked separately from the loop's per-attempt
`$validation` — unit-tested directly.

## Part C — the placeholder-description text

Confirmed by direct code search that no template anywhere in this
module generates `"price 32 is below 50"`-style text — it's entirely
model-authored via the `reason` field `ResponseContractFormatter`
already asks for. Fixed with a prompting instruction, not a code
change: each `reason` must be a genuine, customer-useful description
(material, use case, why it fits), never a bare number-comparison
restatement — a price comparison is fine *alongside* a real reason,
never as the whole of one.

## Part D — image sizing

`.aavirbhava-chat-product-image` / Hyva's equivalent `<img>` switched
`object-fit`/`object-*` from `contain` to `cover` on both themes, so
every card's image area fills consistently regardless of the source
photo's own aspect ratio — no letterboxing or uneven padding depending
on which product's real photo is shown.

## Part E — broad realistic-query robustness testing

Built a 19-query set spanning clean/detailed, terse/vague,
typo/informal, incomplete-grammar, and genuinely ambiguous real
customer phrasings — e.g. "show me waterproof running shoes under $60
in size 10" (clean), "jackets?" (terse), "wat jakets u got" (typo),
"men jacket less 40 dollar" (incomplete grammar), "something for the
gym" (ambiguous) — each run 3 times (57 real calls total) against the
real pipeline: real retrieval, real Ollama chat, real Output
Validator, no scripting/mocking at any layer. Run *before* this task's
Part A/B fixes, to establish real baseline failure modes grouped by
root cause rather than treating each as one-off.

**Baseline results by category** (excluding calls that hit this test
harness's own 60s cap, broken out separately below since that's a
harness/latency artifact, not a pipeline outcome):

| Category | Success | Rate |
|---|---|---|
| Clean/detailed | 11/13 | 84.6% |
| Terse/vague | 10/11 | 90.9% |
| Typo/informal | 9/12 | 75.0% |
| Incomplete grammar | 6/8 | 75.0% |
| Ambiguous | 2/2 | 100%* |
| **Overall (non-timeout)** | **38/46** | **82.6%** |

\* Only 2 of 9 ambiguous-category calls avoided the harness timeout —
the 100% figure is of a very small, filtered sample and is not
representative on its own; see the timeout note below.

11 of the 57 calls (19.3%) hit the test harness's own 60-second cap
rather than reaching a real pipeline outcome, concentrated heavily in
the ambiguous category (7/9). This is the same latency issue the Part
A `max_tool_calls` trade-off investigated and reverted — discovered
*because* of this broad test, not assumed beforehand. The specific
failures this data surfaced (`fabricated_price` on genuine multi-price
comparisons, `fabricated_url`, `fabricated_sku`, `assistant_
unavailable`) are exactly what Parts A/B's fixes above address,
traced directly from this data.

## Confirmation re-test, after all fixes

Did not re-run the full 57-query set after the final round of fixes
(each full pass costs ~20–30 minutes of real Ollama time, out of this
task's own time budget) — stated honestly rather than implied. Instead
ran a targeted 12-call confirmation re-test (4 queries × 3 reps) using
the specific queries the baseline found most problematic: the
"compare the Jade Yoga Jacket and the Montana Wind Jacket" query,
"something for the gym" (an ambiguous, previously all-timeout query),
"jackets?" (terse, previously inconsistent), and "show me jackets less
than $40" (a Part A regression check).

**Result: 11/12 (91.7%) success** — meeting the task's ~9/10 bar.

- The "compare" query went from mostly failing to 3/3 real comparisons
  rendered correctly with both product cards.
- "something for the gym" went from 3/3 harness timeouts (the
  `max_tool_calls` regression) to 3/3 real, on-topic responses.
- "show me jackets less than $40" stayed reliable at 3/3, confirming
  no regression from the other fixes.
- The single remaining failure was a `fabricated_sku` on "jackets?" —
  a genuine model hallucination (inventing a SKU never in the verified
  candidate set), correctly caught and rejected by the existing safety
  check exactly as designed. This is not a code bug.

Product images confirmed rendering with `object-fit: cover` live via
computed style plus a screenshot showing every card's image filling
its frame consistently.

## Container verification

`php -l` on every changed PHP file and both `.phtml` templates,
`setup:di:compile`, `cache:flush` all clean (no schema change this
task, `setup:upgrade` not needed).

## Test results

1298 → 1319 unit tests (+21), 3150 → 3197 assertions, 0 failures — run
repeatedly through this task's several rounds of fix-then-verify, both
before and after the final change set.

## Known gaps / TODOs left for later tasks — stated honestly

- **Not every failure mode is fixable by code changes alone.** The
  `fabricated_sku` case in the final confirmation run is a genuine
  local-model hallucination — inventing a SKU that was never offered
  in the verified candidate set. No prompt or retry change removes the
  underlying inconsistency of a small local model under Ollama; the
  safety check correctly catches and rejects it every time, which is
  the intended behavior, not a bug to chase further.
- **Would a more capable primary provider help?** Plausibly yes — the
  two systemic root causes this task traced (the model confusing a
  schema field name with a callable tool, and occasionally naming a
  product without selecting its SKU) both look like the kind of
  instruction-following slippage that scales with model capability,
  not like a code-level defect. This task did not execute a provider
  switch, per its own instructions — it's a plausible direction for a
  future task, not a decision made here.
- A price mentioned with no threshold-qualifying word (a bare discount
  amount, e.g. "$5 off") remains an accepted, documented
  false-positive source (Task 22, unchanged by this task).
- `ProductMentionCompletenessChecker`'s exact-substring matching
  under-reports a paraphrased mention ("the Jade jacket" instead of
  the real "Jade Yoga Jacket") — a deliberate, documented trade-off
  favoring zero false-positive retries over catching every possible
  incompleteness.
- The full 57-query Part E baseline was not re-run in full after the
  final round of fixes (see Confirmation re-test above) — the targeted
  12-call re-test is strong, but narrower, evidence than a full
  re-run would be.

## Skill files updated

`references/progress-log.md` — status rows 8 and 12 updated; header
summary updated; a full Task 23 history entry added.

## Not done / blocked

Nothing blocked. The one deliberate scope trade-off (not re-running
the full 57-query Part E set after the final fixes) is disclosed above
under Known gaps, not concealed.
