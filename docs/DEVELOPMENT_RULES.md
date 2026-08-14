# Development Do's and Don'ts

## Do

- Use `declare(strict_types=1);` in PHP files.
- Use Magento service contracts and dependency injection.
- Keep controllers thin and return explicit response types.
- Use DTOs/value objects at LLM, retrieval, tool, and validation boundaries.
- Define JSON schemas centrally and test provider compatibility.
- Encrypt secrets and support configuration scopes.
- Redact secrets and PII from logs and exception messages.
- Use queue consumers for embeddings and index writes.
- Use content hashes to avoid unnecessary re-embedding.
- Make consumers idempotent and retry-safe.
- Query only the current store/website index or mandatory store filters.
- Revalidate live catalogue facts before showing them.
- Require explicit confirmation for mutations.
- Provide deterministic behavior when AI is disabled or unavailable.
- Write provider contract tests and use recorded/fake responses in routine tests.
- Add extension interfaces before adding merchant-specific logic.
- Update documentation when a contract, configuration path, or security rule changes.
- Use `ProviderIdentifiers` constants for built-in identifiers and `ProviderIdentifiers::isValid()` for syntax; the runtime allowlist is the DI registry, so never reject identifiers merely because they are not built in.
- Contribute provider implementations through the DI-backed provider registries and resolve them via `ConfiguredProviderResolverInterface`; a provider's `identifier()` must equal its DI key.
- Never put a class name in configuration or derive a provider from customer input; configuration stores only registry keys.
- Supply Admin provider labels only through trusted DI metadata (`ProviderLabelRegistryInterface`); never echo customer input into labels.
- Represent provider failures with the sanitized `ProviderException` hierarchy and stable error codes.
- Gate fallback strictly through `FallbackEligibilityPolicyInterface`; only transient availability failures may use the fallback provider.
- Gate indexing through `ProductIndexEligibilityPolicyInterface` before any sanitization or embedding work; it only answers scope and visibility questions and never inspects content.
- Sanitize every untrusted catalogue field through `UntrustedContentSanitizerInterface` and filter attributes through `ProductAttributePolicyInterface`; never embed raw description markup.
- Compute index idempotency from `ContentHashServiceInterface` SHA-256 digests; `embeddingContentHash` must exclude status/scope-only fields so those changes skip re-embedding.
- Route all catalogue normalization through `ProductDocumentNormalizerInterface` and produce `ProductDocument` value objects; never build index payloads in controllers, templates, or consumers.
- Resolve store scopes through `StoreScopeProviderInterface` and load catalogue data only from an active `StoreScopeInterface`; the admin store (id 0) is never a valid scope.
- Load product ids through `ProductIdBatchProviderInterface` keyset batches and snapshots through `ProductSnapshotProviderInterface`; resolve categories and attribute values through their batch resolvers — never per product (no N+1).
- Call product-collection `addCategoryIds()` only after `load()`; it derives category ids from the already-loaded items.
- Drive loading from `ConfigurationReaderInterface::readIndexing`; keep price, stock, salability, URLs, and customer-group data out of snapshots.
- Run full assistant-index rebuilds only through `FullProductReindexerInterface::rebuild()`; the document writer opens a run, streams store-scoped eligible `ProductDocument` batches, and activates only after every enabled store finished, with `abortRun()` called at most once on failure.
- Keep the indexer action class (`Model\Indexer\ProductIndexer`) and any `Magento\Framework\App\Config\Value` subclass non-`final`; Magento generates interceptors for `ActionInterface` implementers and `Config\Value` subclasses and cannot extend final classes.
- Declare `Magento_Indexer` in the module sequence when registering a custom indexer; the framework `IndexerRegistry` used for invalidation lives in `magento/framework`.
- Make index-failure messages generic and stable (`ProductIndexingException::errorCode()`); never leak provider payloads, SQL, or internal traces.
- Never let an index default fail open: until a backend and queue pipeline exist, the writer and incremental scheduler refuse explicitly with sanitized exceptions rather than pretending documents were persisted.
- Invalidate the assistant index only for content-affecting indexing settings; configuration that cannot change persisted content (for example `batch_size`) must never invalidate the index.

## Don't

- Do not call `ObjectManager` directly in production code.
- Do not read or write Magento database tables directly when a supported service exists.
- Do not place business logic in `.phtml`, JavaScript widgets, controllers, observers, or admin UI classes.
- Do not send the whole catalogue or unlimited conversation history to an LLM.
- Do not store embeddings in normal Magento EAV attribute values.
- Do not generate embeddings synchronously during product save/import.
- Do not let the LLM construct SQL, GraphQL, REST URLs, or arbitrary HTTP requests.
- Do not trust tool-call JSON without schema and business validation.
- Do not trust a model-generated price, stock status, URL, image, discount, or SKU.
- Do not share cached responses between store views, customer groups, customers, or carts without correct cache keys.
- Do not log API keys, authorization headers, complete customer messages, or raw cart/order objects.
- Do not make local fallback less restricted than cloud providers.
- Do not mix organic relevance and paid promotion into an unexplained score.
- Do not silently fail open when a guardrail component is unavailable.
- Do not add Phase 2 scope while Phase 1 correctness tests are failing.
- Do not turn configured provider names into class names or instantiate providers dynamically.
- Do not expose raw provider response bodies, request bodies, or secret material in exceptions or logs.
- Do not use fallback to bypass refusals, invalid tool calls, authorization failures, or response-validation failures.
- Do not hard-code a closed provider allowlist: only the DI registry may restrict which identifiers resolve.
- Do not register a provider under a DI key that differs from its `identifier()`.
- Do not add display labels or customer-facing descriptions inside provider implementations; keep UI metadata in the label registry.
- Do not call `addCategoryIds()` before `load()` or call `getCategoryIds()` per product on the fly — both defeat batching and reintroduce N+1 queries.
- Do not bypass `StoreScopeInterface` with the admin store or inactive store views.
- Do not index, embed, or generate anything synchronously inside a config save, a product save, or the indexer action; publish identifiers and rebuild through the bounded orchestration.
- Do not silently discard a product id passed to incremental scheduling; validate and refuse explicitly when the pipeline is unavailable.
- Do not declare an mview `subscriptions` element without at least one `table`; declare an inert view by omitting `subscriptions` entirely.

## Configuration rules

- Every capability defaults to the safest useful state.
- API keys use Magento encrypted config backend models.
- Admin actions require ACL resources and form-key protection.
- “Test connection” endpoints return sanitized status only.
- Model names and endpoints are merchant-configurable; code must not assume one vendor's current default.
- Provider timeouts, retries, maximum tokens, and circuit-breaker settings must be bounded.
- A model/embedding change that makes the index incompatible must invalidate the custom indexer.

## Testing layers

- Unit tests: normalization, schemas, intent policy, ranking math, response verification, provider mapping.
- Integration tests: configuration encryption, indexer behavior, store scoping, queue idempotency, Magento price/inventory verification.
- API tests: authentication, rate limits, session ownership, CSRF/form key, structured response.
- Adversarial tests: jailbreaks, indirect injection, fabricated facts, cross-tenant access.
- Contract tests: provider tool calls and structured output using mocks/fixtures.
- End-to-end tests: search to product cards, comparison, provider outage fallback, confirmed cart changes.

## Pull request checklist

- [ ] Scope matches the current phase.
- [ ] No unrelated refactor is included.
- [ ] Public interfaces and config paths are documented.
- [ ] Security and store-scope impact reviewed.
- [ ] Success, failure, timeout, and retry paths tested.
- [ ] Logs are redacted.
- [ ] Backward compatibility considered.
- [ ] Static analysis, Magento standards, and tests pass.
