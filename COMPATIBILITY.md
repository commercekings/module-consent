# Compatibility and verification

The Composer PHP range is a dependency constraint, not a claim that every Magento/PHP
combination has passed runtime testing.

| Magento Open Source | PHP | Runtime verification |
| --- | --- | --- |
| 2.4.9 | 8.5 | Passed locally: Composer, PHP lint, DI compilation and package unit tests |
| Older supported releases | 8.1–8.4 | Pending; use a matching Magento dependency set and services |

The five-package sandbox also checks consent mapping against temporary database tables,
the JavaScript lifecycle, and native Vanilla consent choices, add-to-cart and checkout in
a browser. Endpoint regressions also cover website isolation, strict form keys, malformed input,
HEAD safety and link replay. Its database is MariaDB 11.8. These checks do not certify other themes, third-party
tracking scripts, checkout customizations or Adobe Commerce-specific features.

The GitHub workflow checks package metadata, PHP syntax on 8.1–8.5 and Git archive contents.
Local syntax checks passed on PHP 8.1.34, 8.2.30, 8.3.30, 8.4.26 and 8.5.11.
The workflow does not install Magento or run runtime tests, and has not run on GitHub yet.

For runtime tests, install the source checkout in a compatible Magento development project
and follow the README. Distribution archives omit `Test/`; source checkouts retain it.
Keep package constraints and the verified matrix aligned before each release.

Supported Magento service/version combinations are listed in
[Adobe's system requirements](https://experienceleague.adobe.com/en/docs/commerce-operations/installation-guide/system-requirements).
