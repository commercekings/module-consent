# Changelog

## Unreleased

- Explain that a newer consent remains active when an already-used email withdrawal link is reopened in its original browser.

- Redirect email withdrawal links to the homepage outside their original browser, while still withdrawing the recorded consent.

- Reject malformed endpoint/cookie inputs, enforce form keys for AJAX and keep withdrawal HEAD requests free of side effects.

- Add a shared signed withdrawal-link API, purpose-specific confirmation page and browser preference sync, independent of feature data; protect later explicit grants from repeated link use.
- Consent recording, browser identity, evidence snapshots and reliable withdrawal through shared APIs.
- Prepare Composer metadata, Magento dependency bounds and source/distribution packaging for the first public release.
- Adapt unit tests to PHPUnit 12 while retaining older data-provider annotations.
- Repair the Composer/source-mount integration-test bootstrap and inherit the production consent-id collation in temporary fixtures.
- Add PHP syntax/metadata/archive CI checks and explicit compatibility notes.
- Correct admin renderer ownership, store interface annotations and scoped locale lookup for Magento-configured PHPStan level 5.

No public release has been tagged or published yet.
