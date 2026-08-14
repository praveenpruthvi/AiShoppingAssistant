# Compatibility Policy

## Initial target

The initial development line targets:

- Magento Open Source and Adobe Commerce 2.4.7 through 2.4.9.
- PHP 8.2 through 8.5 where supported by the installed Magento release.
- OpenSearch 2 and 3 where supported by the installed Magento release.

The Composer framework constraint is `magento/framework >=103.0.7 <104.0` and PHP is constrained to `>=8.2 <8.6`.

## Compatibility rules

- Do not assume the latest PHP version is accepted by every supported Magento release.
- Do not call version-specific APIs without a capability check or isolated compatibility adapter.
- Do not modify Magento's core catalogue index mapping for assistant vectors.
- OpenSearch requests must be generated through a compatibility boundary and tested against both supported major versions.
- CI must eventually include the oldest and newest supported Magento/PHP combinations.
- When Adobe ends security support for a Magento line, reassess rather than silently claiming indefinite support.

## Verification status

Milestone 0 validates the standalone package structure only. Installation, compilation, and Admin rendering must still be tested inside clean Magento instances before compatibility is claimed as verified.
