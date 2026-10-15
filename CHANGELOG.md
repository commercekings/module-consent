# Changelog

## Unreleased

- Use CommerceKings_Core for the shared admin tab, crown icon and corporate-site footer.
- Group admin settings into Source, Records, Withdrawal and Privacy; simplify wording, show required recording purposes as locked selections, and hide other sections when no source is selected. Link one-click unsubscribe help to RFC 8058.

- Add an optional RFC 8058 Yes/No setting alongside the existing confirmation flow; reuse its signed URL for cookie-free mail-client POSTs.

- Make signed email opt-out GET/HEAD read-only. Require explicit form-key-protected POST confirmation, keep cross-browser withdrawal and later-grant protection, and synchronize banner preferences only in the confirmed POST result.

- Centralize banner and email withdrawals in one atomic core checkpoint with typed, DI-registered module handlers. Persist unfinished actions alongside revocation, run cleanup after commit, and retry failures through native cron and locks without restoring consent or touching later grants. Retain the legacy event as a notification.

- Use Magento's cookie path and domain for browser identities and withdrawal hints; keep their admin-configured consent lifetime and remove the hint's hardcoded lifetime fallback.

- Generate browser and consent identifiers with Magento's random generator while retaining 32 lowercase hex characters; use Magento SQL expressions for withdrawal timestamps.

- Generate and verify withdrawal signatures exclusively through Magento's native encryptor, without a legacy raw-key fallback; reject invalid deployment keys or signing failures without reading or withdrawing consent.

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
