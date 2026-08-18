# STATUS REPORT — Fallback execution + free-text price-fabrication check

Task 5 of the Aavirbhava_AiShoppingAssistant build sequence: close a real,
flagged gap in the Output Validator (a fabricated price in free text was
uncaught), then wire fallback execution (retry, circuit breaker, fallback
provider, non-AI safe response) around `ChatGenerationService`.

## Files created/changed

**Price-fabrication check:**
- `Model/Chat/OutputValidator.php` — added `containsFabricatedPrice()`, `extractMentionedPrices()`, `matchesAnyRealPrice()`, and a new `REASON_FABRICATED_PRICE` constant; extends the existing URL check rather than replacing it.
- `Test/Unit/Model/Chat/OutputValidatorTest.php` — 10 new tests.

**Fallback execution:**
- `Api/Chat/CircuitBreakerInterface.php` (new) / `Model/Chat/Fallback/CacheCircuitBreaker.php` (new) — per-store, per-provider-role circuit breaker backed by Magento's application cache.
- `Model/Chat/Fallback/BackoffSleeperInterface.php` / `Model/Chat/Fallback/SystemBackoffSleeper.php` (new) — millisecond-granular retry backoff.
- `Model/Chat/FallbackChatGenerationService.php` (new) — decorates `ChatGenerationServiceInterface`: retry, circuit breaker, fallback provider.
- `Model/Dto/ChatResponse.php` (modified) — added `usedFallback` (trailing optional, default `false`) and `withFallbackUsed()`.
- `Model/Chat/Response/ResponseMetadata.php` (modified) — docblock update only (behavior already correct; the field just wasn't populated accurately before).
- `Model/Chat/ChatEntryPipeline.php` (modified) — wraps the chat-generation call in a try/catch, new `REASON_ASSISTANT_UNAVAILABLE`.
- `etc/di.xml` (modified) — `ChatGenerationServiceInterface` now resolves to `FallbackChatGenerationService`; new preferences for `CircuitBreakerInterface`/`BackoffSleeperInterface`.

**Tests:** 30 net new tests (full suite 971 → 1001) across `OutputValidatorTest` (+10), `FallbackChatGenerationServiceTest` (9, new), `CacheCircuitBreakerTest` (6, new), `SystemBackoffSleeperTest` (2, new), `ChatResponseTest` (2, new), `ChatEntryPipelineTest` (+1).

## Conventions followed

- `FallbackChatGenerationService` is a pure decorator behind the existing `ChatGenerationServiceInterface` — `ChatEntryPipeline` needed no constructor change for the wrapping itself.
- Price check mirrors the URL check's shape exactly (a private predicate method, checked right after it, same `OutputValidationResult::invalid()` pattern).
- `ChatResponse::withFallbackUsed()` mirrors `SearchCandidate::withScore()`'s established wither pattern for an otherwise-immutable DTO.
- Circuit breaker and eligibility checks reuse the existing `FallbackEligibilityPolicyInterface` rather than inventing new failure classification.
- Test style continues mirroring precedent: the real `ChatGenerationService` (not a mock — it's `final`, PHPUnit can't mock it, but it's also genuinely the right thing to exercise for real) wired with mocked `LlmProviderInterface`/`ConfiguredProviderResolverInterface` at the true I/O boundary.

## Deviations from existing conventions

1. **Did not reuse `Model\Indexing\Clock\SleeperInterface`.** It clamps to a 1-second minimum sleep, appropriate for its async queue-recovery use case but far too coarse for synchronous in-request retry backoff where a customer is waiting on the HTTP response. Built a new `BackoffSleeperInterface` (milliseconds) instead, documented why in its own docblock.
2. **Circuit-breaker state lives in Magento's generic cache, not a new database table.** Every other piece of durable cross-process state in this module (rebuild fence, incremental work ledger) uses a dedicated table with its own schema. A circuit breaker's state is a simple counter-with-TTL — exactly what a cache entry models — so a new migration for this task was judged disproportionate. Documented the resulting limitation: cache read-modify-write isn't atomic, so concurrent failures at the exact same instant could under-count toward the threshold. Accepted because a missed trip just costs one extra provider attempt, not a safety issue.
3. **`ResponseMetadata.fallbackUsed` population changed from a hardcoded `false` to reading `ChatResponse::usedFallback`.** Verified `OutputValidator` was the only place constructing `ResponseMetadata` before changing this.
4. **Price-fabrication detection is not store-currency-aware.** `OutputValidatorInterface::validate()` has no store-scope parameter to read a currency symbol/code from; only US-style `$`/`dollars`/`USD` phrasing is matched. Threading a store id through the interface to fix this properly was judged a larger change than this task's regex-pass scope — flagged, not silently ignored.

## Price-fabrication check design

**Pattern used:** two regexes scan the LLM's free-text `message` field — `\$\s?(\d{1,3}(?:,\d{3})*(?:\.\d{1,2})?)` for `$25`/`$25.99`/`$1,299.99`, and `(\d+(?:\.\d{1,2})?)\s?(?:dollars|USD)\b` for word-form mentions. Every extracted number is compared against every revalidated product's `price` and `specialPrice` (not just the products the LLM ultimately recommended — the full verified candidate set).

**Tolerance chosen:** `$0.50`. This covers the single most common casual-rounding phrasing ("about $25" for an item priced $24.99 or $25.49 — both at most $0.50 from the nearest whole dollar) without being loose enough to let a genuinely different price slip through undetected.

**Explicit statement of limits (not oversold as complete coverage):**
- This is regex, not NLP. It will miss phrasings it doesn't recognize (other currencies, "twenty-five bucks," embedded in a larger number, etc.).
- A mentioned price only has to match *some* revalidated product, not necessarily the one it's textually attached to — a regex pass has no way to attribute a number to a specific product in the sentence.
- **Known, accepted false-positive source, identified and tested:** a non-price currency mention — a shipping threshold ("free shipping over $75"), a discount amount ("$5 off"), or a price range — will be rejected as `fabricated_price` even though nothing was actually fabricated, because this check can't distinguish that from a genuine product-price claim. `OutputValidatorTest::testKnownFalsePositiveNonPriceCurrencyMentionIsStillRejected` documents this exact scenario and its currently-accepted outcome; it is not treated as a bug to fix in this task.
- Not store-currency-aware (see Deviations #4).

This is a real, meaningful improvement over no check at all — the exact scenario in the task's own framing ("that's about $25" when the real price is $34) is now caught — but it is not a claim of complete price-fabrication coverage.

## Fallback execution design

**Sequence** (per `architecture.md`: primary call → limited retry → circuit breaker → fallback provider → non-AI safe response):

1. If the primary provider's circuit breaker is open for this store, skip straight to step 3.
2. Otherwise call the primary provider. On a `FallbackEligibilityPolicy`-eligible failure (timeout, rate limit, transport, unavailable), retry up to **3 total attempts** (1 initial + 2 retries) with short exponential backoff (**200ms, then 400ms**, capped at 800ms) — short because a customer is waiting synchronously on this response, unlike the second/minute-scale backoff used for async queue recovery elsewhere in this module. A non-eligible failure (auth/config/safety) propagates immediately with no retry.
3. If the primary ultimately failed (eligibly), record one circuit-breaker failure (not one per retry attempt) against `failure_threshold`/`cooldown_seconds` (both already-existing `fallback` config fields). Once threshold consecutive failures accumulate, the breaker opens for `cooldown_seconds` and subsequent requests skip the primary entirely until it elapses.
4. Resolve the configured fallback provider (`fallback.enabled` + `ConfiguredProviderResolverInterface::fallbackLlmProvider()`). If none is configured, misconfigured, or its own circuit breaker is open, propagate the primary's last exception.
5. Otherwise call the fallback provider once (no retry — it has no further fallback of its own). On success, mark the response `usedFallback: true`. On an eligible failure, record a circuit-breaker failure against the fallback role too; either way, propagate.
6. `ChatEntryPipeline` catches whatever ultimately propagates from step 4/5 and returns the same `SafeResponse` shortCircuit shape every other pipeline rejection already uses, with reason `assistant_unavailable` — product search keeps working even when every configured LLM provider is down.

**What triggers each stage:** only `FallbackEligibilityPolicy::isEligible()` failures are retried, tracked by the circuit breaker, or cause a fallback attempt at all — this is the existing, unmodified eligibility policy, so a configuration or authentication failure from the primary provider still propagates on the very first attempt and never causes a fallback provider to be consulted, exactly matching the policy's documented "never bypass a safety boundary" contract.

## Container verification

- `bin/cli php -l` on all ~13 new/changed files — clean.
- `bin/magento setup:upgrade`, `setup:di:compile` (validates the decorator wiring — `ChatGenerationServiceInterface → FallbackChatGenerationService`, concrete `ChatGenerationService` resolved without a DI cycle), `cache:flush`, `module:status`, structure validator, `git diff --check` — all clean.
- **Live check 1 (safe fallback reached, not an uncaught exception):** set `general/enabled=1` via the real `bin/magento config:set` CLI (a separate process — writing and reading Magento config within one PHP script hits an in-process cache and reads stale data, a real gotcha hit and fixed during this check). Built a real `ChatEntryPipeline` from DI-resolved components with only retrieval faked (no live OpenSearch index for this store yet, consistent with prior tasks) and confirmed `ChatGenerationServiceInterface` resolves to `FallbackChatGenerationService`. With no LLM credentials configured and no fallback provider configured either, `handle()` returned a short-circuited result with `reasonCode = assistant_unavailable` instead of throwing — **PASS**.
- **Live check 2 (fabricated price caught):** fed the real `OutputValidator` a fake `ChatResponse` mentioning the real sample-data SKU `24-MB01` (live price `$34.00`) but stating `$19.99` in the message text — rejected with `reasonCode = fabricated_price` — **PASS**. A control case with the correct `$34.00` passed validation — **PASS**.
- Config was restored to its documented default (`general/enabled=0`) via CLI afterward and independently re-verified.

## Test results

- New tests: 30 net new (971 → 1001), across `OutputValidatorTest` (+10 price tests), `FallbackChatGenerationServiceTest` (9, new), `CacheCircuitBreakerTest` (6, new), `SystemBackoffSleeperTest` (2, new), `ChatResponseTest` (2, new), `ChatEntryPipelineTest` (+1).
- Full module suite: **1001 tests, 2459 assertions, 0 failures** — zero regressions.
- One real environment gotcha hit and resolved during live verification (config write/read in the same PHP process reads stale cached config) — not a code bug, a live-check methodology fix, documented above.

## Known gaps / TODOs left for later tasks

Explicitly confirmed **not** built:
- **Tool-calling / `CommerceToolInterface`** — unchanged, still not built.
- **Admin UI** — unchanged, still not built.

**Residual price-fabrication risk, stated plainly:** this check catches the specific pattern it was built for (a dollar-sign or word-form number that doesn't match any live price) and nothing more. It cannot catch a fabricated price stated in a form the regex doesn't recognize, cannot verify a mentioned price is attached to the *correct* product when multiple candidates are in play, and will false-positive on legitimate non-price currency mentions (shipping thresholds, discounts, price ranges) — a documented, accepted limitation, not a claim this problem is solved. Full coverage would need real NLP/LLM-based extraction, which is out of proportion for this task.

## Skill files updated

- `references/progress-log.md` — updated in place: status table row 7 (Fallback chain → done) and row 8 (response contract note on the new price check); added the Task 5 history entry; "Next up" now points at Task 6 (tool-calling/`CommerceToolInterface`) with a note that customer-group threading and the price-check's regex limits remain unowned in the sequence.
- This file: `docs/status-reports/2026-08-16-fallback-execution-price-check.md`.

## Not done / blocked

Nothing was left incomplete relative to this task's scope. Every Step 0–5 instruction was completed and verified live.
