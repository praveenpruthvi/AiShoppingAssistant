# STATUS REPORT — Multi-turn follow-up conversation handling

A short follow-up right after a successful product query ("i need it in
medium size" / "the cheaper one" after "show me jogging pants")
intermittently fell all the way back to the generic fallback message.
The real debug log proved conversation history genuinely reached the
model, but this turn's own retrieval — run on the follow-up text alone —
returned candidates completely unrelated to the prior turn, and whether
the model recovered depended entirely on it independently choosing to
call a tool with a remembered SKU. Fixed by carrying the prior turn's
real, re-revalidated products forward into every follow-up turn's
verified set, and relaxing a prompt instruction that was actively
discouraging the model from referencing them.

## Files created/changed

**New:**
- `Model/Chat/PriorTurnProductCarryOver.php` — recovers the SKUs from
  the most recent already-validated assistant turn.
- `Test/Unit/Model/Chat/PriorTurnProductCarryOverTest.php` (5 tests).

**Modified (production):**
- `Model/Chat/ChatEntryPipeline.php` — new constructor dependency; the
  prior turn's SKUs are fetched, live-revalidated, and merged into
  this turn's verified set whenever conversation history exists.
- `Model/Chat/ProductContextFormatter.php` — prompt wording relaxed to
  permit a product already named earlier in the conversation.
- `Model/Chat/Debug/ChatDebugTrace.php` / `ChatDebugLogger.php` — new
  `carriedOverSkus` field, so the fix is directly visible in the same
  debug log that surfaced the bug.

**Modified (tests):** `Test/Unit/Model/Chat/ChatEntryPipelineTest.php`
(net +3 end-to-end tests), `Test/Unit/Model/Chat/
ProductContextFormatterTest.php` (+1), `Test/Unit/Model/Chat/Debug/
ChatDebugLoggerTest.php` (updated for the new field).

**Tests:** 9 net new (1372 → 1381 unit, 3318 → 3332 assertions), 0
failures.

## Step 1 — reproduction, confirmed via the real debug log

Sent a real two-turn conversation (shared session cookie) through the
actual pipeline. Turn 1, "show me jogging pants", succeeded with real
products. Turn 2, "the cheaper one", fell back to the generic message:

```
"reason_code":"fabricated_sku"
```

The trace for turn 2 showed:

```
"scope":{"in_scope":true,"reason_code":null}
"retrieval":{"candidates":["24-UG04","24-WG080","24-MG04",...]}  // gift cards, bags — not pants
"availability_filter":{"before_count":8,"after_count":8,"dropped_skus":[]}
"final_product_skus":null,"outcome":"invalid:fabricated_sku"
```

The classifier never rejected it (`in_scope: true`) — the model was
reached, retrieved eight real but completely unrelated candidates, then
selected something that failed validation. A repeat with "i need it in
medium size" instead *succeeded* on one run — the response included
`actions: [{"type":"check_inventory","skus":[...5 prior SKUs...]}]`,
meaning the model recognized the prior products from conversation-history
text and independently called a tool to re-verify them, recovering on
its own. Two near-identical follow-ups, one recovering only through
unreliable model-initiated behavior and one failing outright — exactly
the "sometimes works" symptom reported, not a single deterministic
trigger.

## Step 2 — root cause

- **(a) Conversation history threading — ruled out.** It was genuinely
  present every turn (Task 8 working as designed): the model correctly
  recalled prior SKUs by name/price in its own text on every run, and
  `ChatEntryPipeline` unconditionally loads `recentMessages()` whenever
  a `conversationId` is given, unchanged since Task 8.
- **(c) Scope classifier — ruled out.** `CommerceScopeClassifier` is
  default-allow, keyword/pattern-based against a fixed off-topic/
  injection/code-gen/external-url list. Read directly: neither "medium
  size" nor "the cheaper one" could ever match any of its patterns, and
  every real trace showed `in_scope: true`.
- **(b) Retrieval — confirmed as the structural cause.**
  `ProductContextResolver::resolve()`/`HybridRetrievalService::
  retrieve()` are called with the current turn's raw message text alone
  — no conversation history, no product-type carry-over. A short,
  context-dependent follow-up with no product-type signal reliably
  retrieves irrelevant candidates.
- **A second, compounding contributor**, found while designing the fix
  and not in the task's original candidate list: `ProductContextFormatter`'s
  system message told the model this turn's candidate list was *"the
  complete and only set of products you may mention"* — actively
  instructing it not to reference anything else, including a real
  product it had already named with its real SKU one turn earlier. This
  is exactly why the model's own recovery (calling a tool) sometimes
  worked — a tool result is new, legitimate data, not "mentioning"
  something outside the list — while directly selecting a remembered SKU
  without a tool call was precisely what the prompt discouraged.

## The fix

`PriorTurnProductCarryOver::skus()` reads
`recentMessagesWithResponsePayloads()` (Task 20's structured UI-restore
read path — the only one carrying real SKUs, not just message text) and
returns the SKUs from the most recent assistant message that actually had
products, scanning past any more recent product-less turn. It only ever
reads from an already-*persisted* assistant message, which — per
`ChatEntryPipeline`'s own persistence rule — only happens for a turn that
already passed `OutputValidator`, so there is no path for a hallucinated
SKU to be carried forward.

`ChatEntryPipeline` calls this after loading conversation history
whenever a `conversationId` is present, **live-revalidates every returned
SKU** (never trusts the stored snapshot — a product shown two turns ago
may have sold out since), and merges the result into this turn's verified
set via the same `mergeVerifiedProducts()` helper tool-call-verified
products already use.

Separately, `ProductContextFormatter`'s instructions were reworded to
explicitly permit "a product you already named with its real SKU earlier
in this same conversation," alongside this turn's own candidate list and
any tool result. Safe to relax precisely because `OutputValidator`'s
fabricated_sku check — not the prompt wording — is the actual security
boundary: the wording change can only make the model more willing to
reference something already legitimately available in the verified set,
never able to smuggle in something that wasn't.

## Live verification — two follow-up phrasings, both via the real debug log

**"i need it in medium size"** after a real "show me jogging pants":

```
"retrieval":{"candidates":["MSH05","WP09","24-WB04",...]}  // still unrelated — unchanged, as intended
"carried_over_skus":["MP03","WP04","MP01"]                 // the exact three real products from turn 1
"final_product_skus":["MP03","WP04","MP01","MP12","MP02"]  // a real, relevant, correct answer
"outcome":"generated"
```

A real answer, not the fallback — the customer received a genuine
description of medium-sized jogging pants with real prices.

**"the cheaper one"** — a fresh, differently-worded conversation:

```
"carried_over_skus":["MP03","WP04","MP01","MP09","MP12"]
"final_product_skus":["MP01"]
```

The model correctly identified the Caesar Warm-Up Pant as the genuinely
cheapest of the five carried-over options (comparing real, live-revalidated
prices) — confirming the fix isn't overfit to one exact wording.

Both runs' `system.log` checked for the debug-log leakage class of bug
Task 24 fixed — none found.

## Verification

Full suite 1372 → 1381 unit tests (+9), 3318 → 3332 assertions, 0
failures, run before this task's changes and again after. `php -l` on
every changed/new PHP file, `setup:di:compile`, `cache:flush` clean (no
schema change, no console command, `setup:upgrade` not needed).

## Known gaps / TODOs left for later tasks

- Carry-over reaches back to the single most recent assistant turn with
  products, not an arbitrary number of turns back — a follow-up
  referring to a turn two or more messages earlier is not covered.
- This turn's own retrieval quality for a weak query is unchanged by
  this task — the fix works around it by making prior context
  available rather than improving retrieval's own handling of
  short/ambiguous text, which remains a real, separate limitation.
- The relaxed `ProductContextFormatter` wording is a prompt-level nudge,
  not a hard guarantee the model will actually reference a carried-over
  product when it should — `OutputValidator`/`PriorTurnProductCarryOver`
  together guarantee *safety* (never a fabricated SKU), not that the
  model always chooses to use what's now available to it.

## Skill files updated

`references/progress-log.md` — status row 6 updated; header summary
updated; a full Task 26 history entry added.

## Not done / blocked

Nothing blocked.
