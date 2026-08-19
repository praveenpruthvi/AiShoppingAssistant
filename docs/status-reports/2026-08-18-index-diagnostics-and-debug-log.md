# STATUS REPORT — Index-coverage diagnostic CLI and a dedicated chat debug log

Two new diagnostic tools, both live-verified against this store's real
catalog, real OpenSearch index, and real chat pipeline — not simulated.
(A) A console command comparing real salable/visible/enabled catalog SKUs
to the real OpenSearch document count for a store, listing any SKUs on
either side of the diff. Run against this store, it found the real
catalog and the real index fully in sync. (B) A new, always-on, dedicated
log file recording one compact trace per real chat request — message,
scope decision, retrieval query and scores, the live-revalidation filter's
before/after counts, and the final products returned — fully isolated
from system.log after two live-verified fix rounds, detailed below.

## Files created/changed

**New:**
- `Model/Diagnostics/CatalogSkuProvider.php`,
  `Model/Diagnostics/IndexedSkuProvider.php`,
  `Model/Diagnostics/IndexCoverageReport.php`,
  `Model/Diagnostics/IndexCoverageChecker.php` — Part A's building
  blocks.
- `Console/Command/IndexCoverageCommand.php` — the CLI itself.
- `Model/Chat/Debug/ChatDebugTrace.php`,
  `Model/Chat/Debug/ChatDebugLogger.php` — Part B's building blocks.
- A test file for each of the above (`Test/Unit/Model/Diagnostics/*`,
  `Test/Unit/Console/Command/IndexCoverageCommandTest.php`,
  `Test/Unit/Model/Chat/Debug/ChatDebugLoggerTest.php`).

**Modified (production):**
- `Model/Chat/ChatEntryPipeline.php` — new `ChatDebugLogger`/
  `ChatDebugTrace` dependency, the whole method body now runs inside a
  try/finally so the trace always logs, two new small helper methods
  (`traceCandidates()`, `recordAvailabilityFilter()`).
- `etc/di.xml` — console command registration; the debug-log Monolog
  virtualType chain (see Part B below for why it took two rounds to
  get right).

**Modified (tests):** `Test/Unit/Model/Chat/ChatEntryPipelineTest.php`
(factory updated for the new constructor argument only — no test
logic changed).

**Tests:** 22 net new (1319 → 1341 unit, 3197 → 3245 assertions), 0
failures.

## Conventions followed

`CatalogSkuProvider` reuses Magento's own standard listing filters
(`status`/`visibility` attributes plus `CatalogInventory\Helper\
Stock::addIsInStockFilterToCollection()`) rather than a hand-rolled
MSI query — the same helper category/search listings already use.
`IndexedSkuProvider` queries the store's live read alias the exact
same way `HybridRetrievalService` does. `ChatDebugTrace`'s try/finally
pattern was chosen specifically because `ChatEntryPipeline::handle()`
already has several early-return short-circuit branches (Task 12's own
established shape) — wrapping the body once, rather than adding a
logging call before every individual `return`, keeps the change small
and guarantees no future return path can accidentally skip the trace.
Every finding in Part B below was caught by an actual real HTTP
request and reading the actual resulting log file, never assumed —
this module's standing discipline.

## Deviations from existing conventions

`CatalogSkuProvider`, `IndexedSkuProvider`, and `IndexCoverageChecker`
are deliberately **not** `final`, unlike most classes in this module —
purely so they stay mockable in each other's unit tests. No `Api/`
interface was added for a diagnostic-only feature with a single
implementation, which is the usual reason this module adds one.
`IndexCoverageCommand` is also not `final` — Magento's DI compiler
generates an interceptor for every console command (matching every
Magento core command, none of which are final either), and
`setup:di:compile` fails outright otherwise — caught via a real
`setup:upgrade` run during this task, not assumed in advance.

## Part A — index-coverage command

`aavirbhava:ai-shopping-assistant:index-coverage` (optionally
`--store-id=<id>`, defaults to every active store) compares two
independently-sourced SKU lists per store:

- **Real catalog** (`CatalogSkuProvider`): Magento's own standard
  `status`/`visibility` attribute filters, plus the same
  `CatalogInventory\Helper\Stock::addIsInStockFilterToCollection()`
  helper category/search listings use — which also respects the
  merchant's own "Display Out of Stock Products" setting, so this
  reports what a real shopper would actually see as salable, not a
  stricter stock-table-only definition.
- **Real index** (`IndexedSkuProvider`): a plain match-all query
  against the store's live read alias, capped at 10,000 documents (a
  fast diagnostic, not built to reconcile a store with more SKUs than
  that). Not `is_enabled`-filtered — every document present already
  passed `ProductIndexEligibilityPolicy`'s enabled/visible gate at
  index time, so a plain per-store document count is the correct
  comparison.

`IndexCoverageChecker` composes both into a two-way diff. The command
prints a summary table plus up to 50 specific SKUs per direction (with
a remainder count beyond that), reports "never indexed" rather than
erroring when a store has no alias yet, and reports an unreachable
backend per-store (catching `ProductIndexingException`) rather than
aborting a multi-store run.

**The real finding for this store** — the answer to the task's own
open question, not just confirmation the tool runs:

```
Store 1 (default)
+----------------------------------------+-------+
|                                        | Count |
+----------------------------------------+-------+
| Real catalog (salable/visible/enabled) | 181   |
| Assistant OpenSearch index             | 181   |
| Missing from index                     | 0     |
| Indexed but not in real catalog        | 0     |
+----------------------------------------+-------+
  Fully covered — every real catalog SKU is indexed, no orphaned documents.
```

**181 real salable/visible/enabled products, 181 indexed documents, 0
missing from the index, 0 orphaned documents in the index.** This
store's assistant index is fully in sync with its real catalog right
now.

## Part B — dedicated chat debug log

`ChatEntryPipeline::handle()` now always logs one compact trace to a
new `var/log/aavirbhava_ai_shopping_assistant_chat.log` file,
regardless of outcome — a request-tracing aid, not an error log. The
whole method body runs inside a try/finally around a new mutable
`ChatDebugTrace` accumulator (constructed from the raw incoming
message at the very top, so it is always available to the finally
block even on the earliest possible short-circuit); each field fills
in only once the pipeline actually reaches that stage — a
short-circuited turn logs real nulls for the stages it never reached,
not guessed values.

One real entry, from a real "show me jackets under $40" request:

```
[2026-08-18T19:03:37...] main.DEBUG: chat request trace {"store_id":1,
"conversation_id":"a636...","message":"do you have anything for hiking",
"scope":{"in_scope":true,"reason_code":null},
"retrieval":{"query":"do you have anything for hiking","candidates":[
{"sku":"WP04","bm25_score":13.03,"vector_score":0.76,"rank_score":1.69},
... 7 more real candidates with real scores ...]},
"availability_filter":{"before_count":8,"after_count":8,"dropped_skus":[]},
"final_product_skus":["WP04","MP01","MS08","MJ03","24-UG06","24-UG03"],
"outcome":"generated"}
```

And a real out-of-scope request, showing the null-for-unreached-stages
behavior:

```
[2026-08-18T19:03:49...] main.DEBUG: chat request trace {"store_id":1,
"conversation_id":"e00c...","message":"what is the capital of France",
"scope":{"in_scope":false,"reason_code":"off_topic_request"},
"retrieval":{"query":null,"candidates":null},
"availability_filter":{"before_count":null,"after_count":null,"dropped_skus":null},
"final_product_skus":null,"outcome":"out_of_scope:off_topic_request"}
```

**Scope boundary, disclosed rather than silent:** the trace covers the
up-front retrieval/revalidation step `ChatEntryPipeline` always runs
itself for every turn. A mid-conversation `search_products` tool call
the model makes on its own is not separately traced in this pass —
that would mean threading the debug logger into
`ToolCallingChatService`/`SearchProductsTool` too, a larger change
than this task's scope.

**The one real "filter" in this pipeline:** live revalidation
(`recordAvailabilityFilter()`) drops any retrieved candidate that
turns out disabled/not visible/off-website/out of stock by the time
the turn actually runs. Re-confirmed by code search, consistent with
Task 22/23's own findings, that no structured price/attribute filter
exists anywhere in retrieval or ranking — free-text price phrases
still rely entirely on the model's own reasoning over live-verified
candidate prices.

## Getting real isolation from system.log took two live-verified fix rounds

The first version logged at PSR `info()` level via a virtualType
overriding only the `debug` item of `Magento\Framework\Logger\
Monolog`'s default handler array (`system`/`debug`/`syslog`, declared
in `app/etc/di.xml`). A real chat message correctly produced the trace
in the new file — but `grep` also found it in `system.log`.

**Root cause 1** (found by reading `app/etc/di.xml` directly, not
guessed): Magento's DI merges a virtualType's array argument with its
base type's *by item key*, so the inherited `system` handler
(`Handler\System`, threshold `Logger::INFO`) stayed attached and
caught the `info()`-level call. Fixed by logging at `debug()` level
instead — below `Handler\System`'s `INFO` floor — the same `debug`
key + `debug()`-level combination `Magento_Payment`/
`Magento_Shipping`'s own virtual debug loggers already rely on for
identical isolation, confirmed by reading their real `etc/di.xml`.

That still left the inherited `syslog` handler active (`Handler\
Syslog`, threshold `Logger::DEBUG` — genuinely the OS syslog), so a
`Monolog\Handler\NullHandler` was added to neutralize both the
`system` and `syslog` keys for full isolation regardless of future
log-level choices on this channel.

**Root cause 2**, introduced by that fix and also caught live: a
`NullHandler` built with its default threshold (`DEBUG`) returns
`true` from `handle()` for a `debug()`-level record, and
`Monolog\Logger::addRecord()` stops passing a record to any further
handler the instant one handler returns `true`. Since the inherited
`system` slot sits before the real file handler in the merged array,
every record was being silently swallowed — confirmed by manually
invoking the compiled logger via a container script and observing no
file appeared at all despite the call completing without error.

**Genuine fix:** both `NullHandler` instances were given an explicit
`level` of 601 — one above `Logger::EMERGENCY`, the highest real level
— so `isHandling()` is always false for anything this channel will
ever log, letting every real record fall through untouched to the one
real handler. Confirmed via the same container script showing the
correct handler stack, then a real HTTP chat request producing a
correct entry in the dedicated file with nothing new in `system.log`.

## Verification

Full suite 1319 → 1341 unit tests (+22), 3197 → 3245 assertions, 0
failures — run before this task's changes and again after every fix
round, including both debug-log isolation fixes. `php -l` on every
changed/new PHP file, `setup:upgrade`, `setup:di:compile`,
`cache:flush` all clean. (One `setup:upgrade` warning —
`Magento_CatalogSampleData`'s own data patch failing with "Rolled back
transaction has not been completed correctly" — reproduced identically
on a second, unmodified re-run, confirming it's a pre-existing
environment characteristic unrelated to this task's changes.)

Live verification, all against the real running store:

1. The index-coverage command run with no arguments (all active
   stores), with `--store-id=1`, and with a deliberately invalid
   `--store-id=999` (correctly rejected, non-zero exit, no exception
   page).
2. A real "show me jackets under $40" request and a real out-of-scope
   "what is the capital of France" request, each checked against the
   actual resulting debug-log file content and a `grep` of
   `system.log` for leakage — both before and after each isolation fix
   round, so the fix is demonstrated, not assumed.

## Known gaps / TODOs left for later tasks

- The debug trace does not cover a mid-conversation `search_products`
  tool call's own retrieval (see Part B) — only the turn's own
  up-front retrieval/revalidation, which `ChatEntryPipeline` always
  runs regardless of what the model does afterward.
- The index-coverage command is a snapshot diagnostic with no repair
  action of its own, per the task's own "keep it simple" instruction
  — closing a gap it finds still requires a real reindex run
  separately.
- `IndexedSkuProvider`'s 10,000-document scan cap is untested against
  a catalogue that large in this environment (181 real products here)
  — a store past that size would need a scroll/`search_after`-based
  rewrite, out of this task's scope.
- Unrelated, live-observed while verifying Part B: a couple of
  `reason` fields in a real response still read as a bare price
  comparison ("Jacket above budget at $72") despite Task 23 Part C's
  prompt fix — consistent with that task's own honestly-reported
  finding that prompting alone doesn't reach 100% compliance from this
  local model. Not touched by this task, since it wasn't this task's
  ask.

## Skill files updated

`references/progress-log.md` — status rows 4 and 6 updated; header
summary updated; a full Task 24 history entry added.

## Not done / blocked

Nothing blocked.
