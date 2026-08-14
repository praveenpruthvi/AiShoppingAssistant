# Aavirbhava AI Shopping Assistant

A reusable Magento 2 module for store-grounded conversational product search, comparison, recommendations, and controlled commerce assistance.

## Status

Early development. The module is disabled by default and is not yet suitable for production storefronts.

## Compatibility target

- Magento Open Source / Adobe Commerce 2.4.7–2.4.9
- PHP 8.2–8.5, subject to the installed Magento release
- OpenSearch 2 or 3, subject to the installed Magento release
- Composer installation

## Package identity

- Composer: `aavirbhava/module-ai-shopping-assistant`
- Magento: `Aavirbhava_AiShoppingAssistant`
- Namespace: `Aavirbhava\AiShoppingAssistant`

## Development principles

- Magento is the source of truth for catalogue and transactional facts.
- Retrieval produces candidates; live Magento services verify final facts.
- LLM providers are interchangeable and untrusted.
- Customer-facing capabilities are deny-by-default and store-only.
- Indexing is asynchronous and isolated from product-save requests.

See `AGENTS.md` and the files under `docs/` before contributing.

## Installation preview

The package is not published yet. Once available through a Composer repository:

```bash
composer require aavirbhava/module-ai-shopping-assistant
bin/magento module:enable Aavirbhava_AiShoppingAssistant
bin/magento setup:upgrade
bin/magento cache:flush
```

Keep the module disabled in Admin until provider, retrieval, and guardrail diagnostics pass.

## Standalone validation

```bash
python3 tools/validate_structure.py
composer validate --strict
composer install
composer lint
composer test
```

Full Magento installation and integration checks will be added to a dedicated test environment.
