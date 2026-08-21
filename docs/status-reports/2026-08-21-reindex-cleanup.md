# STATUS REPORT — OpenSearch index retention: stop leaking physical indices on every reindex

Fixed `FullProductReindexer`/`OpenSearchProductDocumentWriter` leaving a
new, unreferenced physical OpenSearch index behind on every successful
reindex since the module's earliest runs (Task 16, still open going
into this task). Live-verified against the real, previously-broken
cluster, not just the fake-client test suite.

## Diagnosis (done first, before any code changed)

Read the real `OpenSearchProductDocumentWriter::activateRun()`:
it already builds one atomic `updateAliases()` call per store —
`remove` the store's read alias from whatever it currently points at,
`add` it to the new physical index — but never deletes the OLD physical
index afterward. `abortRun()` (the failed-run cleanup path) already
deletes unaliased, ownership-proven run indexes correctly; only the
SUCCESS path leaked.

Confirmed live before writing any fix: store 1's real cluster had
**19** physical `aavirbhava_ai_product_rag_store_1_run_*` indices (the
task's own bug report said 17 — six more real reindexes happened
between when that number was written and this task actually starting,
consistent with ordinary use in between), with the store's read alias
pointing at only one of them. The other 18 were pure leftovers: none
referenced by any alias, confirmed via a direct `_cat/aliases` query
before touching any code.

## Files

- `Api/Indexing/AssistantSearchClientInterface.php` — 3 new methods:
  `listIndices(string $pattern): array` (physical indexes matching a
  wildcard pattern), `indexAliases(string $indexName): array` (every
  alias currently pointing at one exact index, in either direction),
  `indexCreatedAt(string $indexName): int` (an index's own OpenSearch-
  native creation timestamp, for ordering only).
- `Model/Indexing/Client/OpenSearchAssistantClient.php` — real
  implementations via `indices().get()` / `indices().getAlias()` /
  `indices().getSettings()`, matching this class's existing sanitized-
  exception, not-found-is-empty-not-a-failure conventions.
- `Model/Indexing/Client/UnavailableAssistantSearchClient.php` — the 3
  new methods added, fail-closed, matching every other method there.
- `Test/Unit/Fake/FakeAssistantSearchClient.php` — in-memory support
  for the 3 new methods (pattern matching via `fnmatch()`, alias lookup
  by scanning tracked alias targets, a creation-order counter).
- `Api/Indexing/IndexNamingServiceInterface.php` /
  `Model/Indexing/Naming/IndexNamingService.php` — new
  `runIndexPattern(string $prefix, StoreScopeInterface $scope): string`
  returning the wildcard `<prefix>_store_<storeId>_run_*` pattern
  pruning candidates are discovered from.
- `Model/Indexing/OpenSearchProductDocumentWriter.php` — the actual
  fix: new `pruneOldIndexes()` / `pruneOldIndexesForStore()` methods
  called from `activateRun()` right after the alias switch succeeds;
  new `INDEX_RETENTION_COUNT = 2` class constant; new constructor
  `Psr\Log\LoggerInterface` dependency for best-effort failure logging
  (no `di.xml` change needed — Magento's own PSR logger preference
  auto-wires it).
- Tests: `Test/Unit/Model/Indexing/OpenSearchProductDocumentWriterTest.php`
  (5 new tests), `Test/Unit/Model/Indexing/Client/OpenSearchAssistantClientTest.php`
  (11 new tests), `Test/Unit/Model/Indexing/Naming/IndexNamingServiceTest.php`
  (2 new tests).
- `CLAUDE.md` — removed the resolved "Known open issues" bullet for
  this exact leak (originally flagged Task 16); added a new "OpenSearch
  index retention (Task 39)" section with the binding design
  constraints for maintaining this fix.
- `references/progress-log.md` — header summary replaced, Task 39
  history entry added.

## Key decisions

- **Retention count is a class constant (`INDEX_RETENTION_COUNT = 2`),
  not a new admin field.** The task's own wording explicitly accepted
  either ("configurable or a sane constant, e.g. 1-2"). Index retention
  is an internal rollback-safety margin with no merchant-facing
  meaning, unlike this module's other genuinely admin-configurable
  knobs (MerchandisingBoost, cost cap) — a constant was the simpler,
  equally correct choice, not a shortcut. `2` means the newly-activated
  index plus exactly one immediate predecessor survives every
  successful reindex, as a rollback margin — never fewer.
- **Pruning candidates are discovered live from the backend, never from
  local state.** The writer only ever tracks the CURRENT run's own
  physical indexes in memory; a past run's index name is gone the
  moment that process exits. The new `listIndices()` client method
  (`indices().get()` against a wildcard pattern) is the only way to
  rediscover them — which is also exactly what made cleaning up the
  real, already-existing 19 leftover indices possible in the same fix
  that also prevents future leaks, with no separate one-off migration
  script needed.
- **"Still referenced by anything," not just "unaliased by this
  store's own canonical alias."** The task explicitly required
  confirming an old index isn't "still referenced by anything (e.g. a
  prior alias generation, an in-flight read)" before deleting it. The
  new `indexAliases()` method returns every alias currently pointing at
  an exact index in either direction (OpenSearch's real
  `GET /<index>/_alias`) — a non-empty result skips that candidate
  unconditionally, regardless of which alias it is. This is strictly
  stronger than only checking the one alias name this store happens to
  use today.
- **Ownership proof reuses `abortRun()`'s `_meta` check, but
  deliberately not its full strictness.** A candidate must pass
  `metaProvesAssistantOwnership()` — the same check `abortRun()`
  already used (`assistant_index`, matching `store_id`/`website_id`,
  matching `physical_index`) — before it's ever deleted; failing it
  skips the candidate rather than deleting it (the task's own "do not
  delete blindly" edge case). It deliberately does NOT also require
  matching the CURRENT run's own `run_id` the way `abortRun()`'s check
  does, because retention pruning legitimately considers indexes from
  many different PAST runs, not the one run currently in flight.
- **Real OpenSearch `creation_date`, never a new custom `_meta`
  field.** Ordering candidates from newest to oldest needed a real
  timestamp — run ids are random UUIDv4s with no time ordering of their
  own. A new custom `_meta` field would have been absent on every one
  of the 19 real leftover indices this fix also had to clean up
  retroactively, since they predate this change. OpenSearch's own
  native `settings.index.creation_date` (present on every index
  unconditionally, from creation) sidesteps that; the new
  `indexCreatedAt()` reads it directly, used only for ordering, never
  as an ownership or correctness signal on its own.
- **Pruning is best-effort and can never fail the run.** By the time
  `pruneOldIndexes()` runs, the alias switch — the one correctness-
  critical, load-bearing operation — has already succeeded. A pruning
  failure (a `listIndices()` transport error, an unverifiable
  candidate, a failed `deleteIndex()`) is caught per-store, logged via
  the writer's new `LoggerInterface` dependency, and never rethrown.
  This is deliberately the opposite tradeoff from `abortRun()`'s own
  cleanup, which DOES report a failed cleanup via
  `ProductIndexAbortFailedException` — intentional asymmetry: abort's
  cleanup failure means an already-failed run's mess wasn't cleaned up
  and the caller should know; a pruning failure after a SUCCESSFUL
  activation just delays cleanup of one or more old indexes to the next
  successful run, a storage-hygiene delay, not a correctness problem.

## Verification — full test suite

**1697 tests / 4240 assertions / 0 failures** (up from 1615/3897) — 82
new tests. Concentrated in:
- `OpenSearchProductDocumentWriterTest` — pruning old, unaliased
  indexes beyond the retention window; preserving the exact retention
  count across four successive real activation cycles against the fake
  client; never pruning an index some OTHER alias still references;
  surviving a total pruning failure (an injected `listIndices()`
  exception) without failing the run, with the failure logged.
- `OpenSearchAssistantClientTest` — all three new client methods
  against a mocked real OpenSearch client, including the not-found-is-
  empty-not-a-failure case for `listIndices()`/`indexAliases()`, and
  credential-sanitization on every failure path (matching this file's
  existing convention for every other method).
- `IndexNamingServiceTest` — `runIndexPattern()` shape (never matches
  the store's own read alias) and prefix validation.

`setup:di:compile` completed cleanly (confirms the new
`LoggerInterface` constructor dependency auto-wires correctly with no
`di.xml` change needed — Magento's own PSR logger preference already
covers it). `phpcs` against the whole module shows only the same
pre-existing `final`-keyword-prohibited errors and docblock warnings
already present across the rest of this module's established
all-classes-`final` convention — no new categories of issue in any
file this task touched.

## Verification — real, live cluster (not just the fake-client suite)

This was the task's own explicit requirement 3 and 6, not optional:

```
Before: 19 physical indices for store 1, alias pointing at 1
  $ curl .../_cat/indices/aavirbhava*   → 19 rows
  $ curl .../_cat/aliases/aavirbhava*   → alias → run_78916276...

$ bin/magento indexer:reindex ai_product_rag
  AI Shopping Assistant Product Index index has been rebuilt successfully

After 1st real reindex: exactly 2 physical indices remain
  run_78916276... (the prior live index, kept as the rollback margin)
  run_57880c6f...  (newly activated, alias now points here)

$ bin/magento indexer:reindex ai_product_rag   (run again)

After 2nd real reindex: still exactly 2, steady state confirmed
  run_57880c6f... (now the rollback margin)
  run_9e775dc4...  (newly activated, alias now points here)
```

`aavirbhava:ai-shopping-assistant:index-coverage` reported full
181/181 real catalog coverage after both real reindexes (0 missing,
0 orphaned). `var/log/exception.log` and `var/log/system.log` showed
no errors from either run — pruning succeeded cleanly, it wasn't merely
non-fatal.

## Not done / blocked

Nothing. Every numbered requirement in the task prompt — diagnosis
first, retention with a rollback margin, live cleanup of the real
existing leftovers (not just a prospective fix), the four specific
test cases, progress-log/CLAUDE.md updates before completion, full
suite + real reindex + `index-coverage` confirmation — was completed
and verified against the real, previously-broken cluster state.
