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
