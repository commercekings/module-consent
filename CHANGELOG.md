# Changelog

## 0.1.1 - 2026-10-20

- A purpose's `active_if` setting is read for one explicit target: a visitor's grant or withdrawal uses the current
  store view (the banner they see); validity and the guard of cron jobs and queue consumers use the consent's website,
  where the purpose counts when it is on in ANY of the website's store views. Two cases this fixes:
  - Google Analytics (or another `active_if` integration) enabled in one store view only, off at website/default:
    the grant was refused (the website's own scope was off), so analytics never loaded there and the banner asked
    again on every page. Now the grant is recorded and stays valid.
  - A purpose on for one website only (e.g. the consent bridge for abandoned carts on website 2), with the cron or
    consumer running in another website's store view: the source's categories were built for the worker's store
    view, the purpose dropped out and that website got no reminders. Now the categories are built for each
    website being checked.
- `PurposeRegistryInterface::isDeclared()` takes an optional `$storeId` (additive: existing calls keep working; a
  class implementing the interface must add the parameter). `PurposePool::inContext()` runs code for one website or
  store view (getCodes(), CategoryMapper and the source's categories follow it). `PurposePool` gets the store manager
  (etc/di.xml) to see a website's store views.
- `Config::getRecordedPurposes($websiteId)` lists that website's purposes (it used the current store view's).

## 0.1.0 - 2026-10-19

- A purpose's `active_if` settings are read for each consent's own website (cron and queue consumers check every
  website from one store).
- Browser times MySQL cannot store (before 1970, after 2038) count as none instead of becoming 0000-00-00 rows.
- A yes the server has not acknowledged (refused or lost) stays off on later pages, which ask again.
- A cleanup task that keeps failing is given up after 72 attempts (about three days), with an error in the log.
- A consent withdrawn with the email link on another device also switches the banner off in the browser that gave
  it: opening Cookie settings asks `GET /ckconsent/status` (one small request per opening, nothing on page loads).
  Sources announce the opening with the window event `ck-consent:preferences`.
- Requires Magento 2.4.4 or later (`magento/framework ^103.0.4`): Magento 2.4.0–2.4.3 do not run on PHP 8.1.
- Consent lifetime is 1 to 730 days (24 months, the longest an EU authority accepts; admin validation and in code),
  and an expiry is capped at the last TIMESTAMP second (2038-01-19), so no consent is stored already expired.
- A withdrawal that finds another one for the same browser still running (a double click) answers 503 on the
  banner endpoint and for one-click POSTs (both retry), and returns the email confirmation page with "Please try
  again" – instead of Magento's error page.
- Withdrawal writes its marks first, then reads the active consents with a locking read: a "yes" recorded at the
  same moment as a "no" is withdrawn too, instead of staying active.
- Purpose labels and source names are translated (`__()`); `i18n/en_US.csv` lists every phrase for translators.
- Email withdrawal links signed with an older key still listed in `env.php` keep working after `encryption:key:change`
  (new links use the newest key), so recipients can still unsubscribe from emails already sent. Removing the old key
  from `env.php` ends them ("Link expired"); after a leaked key, remove it once nothing else needs it.
- An email withdrawal link that no longer verifies (a key removed from `env.php`, removed consent, damaged URL) shows
  "Link expired": nothing is changed, and it points to "Cookie settings" – instead of a silent redirect home.
- A purpose belongs to one banner category (the first in display order); the server no longer grants it through a
  second category the banner does not watch.
- The email withdrawal link confirms the result in every browser (generic text outside the browser that consented);
  it used to redirect other browsers home without feedback. `consent:evidence:show` returns Magento's
  `Cli::RETURN_*` codes, which also exist on Magento 2.4.4 (Symfony Console 4.4).
- `source_time` and `expires_at` declare an explicit default. On MariaDB before 10.10 (and MySQL with
  `explicit_defaults_for_timestamp=OFF`) the first timestamp column otherwise became `ON UPDATE CURRENT_TIMESTAMP`,
  so a withdrawal overwrote the recorded choice time.
- **Hyvä:** the purpose API without RequireJS (`js/consent/hyva.js`, loaded only under Hyvä's `hyva_default`
  handle). Sources register their adapter in `window.CommerceKingsConsent.adapters`; features use the
  `ck-consent:ready` event or `window.CommerceKingsConsent.provider`. The provider logic is shared with the AMD module
  (`provider-core.js`); the public AMD API and PHP contracts are unchanged.
- The consent controllers extend Magento's `Action`, so Magento sets the store and currency for their responses and
  consent changes and email withdrawal keep the shopper's store view and currency in the page cache.
- Add `grantCurrent()` and `withdrawCurrent()` to the pre-release `ConsentInterface` contract and use that contract
  in both banner controllers, so DI replacements handle browser and email operations consistently.
- Add `Api\ConsentOwnershipInterface::isCurrentVisitor($consentId)`: whether a consent was given in the current
  browser, for features that hand back something the consenting visitor owns.
- Exercise withdrawal lock contention, cleanup completion/retry storage failures, grant persistence failures and CLI/export rejection paths with dedicated failure tests.
- Use CommerceKings_Core for the shared admin tab, crown icon and corporate-site footer.
- Group admin settings into Source, Records and Privacy; simplify wording, show required recording purposes as locked selections, and hide other sections when no source is selected.
- Accept RFC 8058 one-click POSTs on the signed opt-out URL (cookie-free, alongside the confirmation flow). The setting that adds the email headers lives in CommerceKings_Notification.
- Make signed email opt-out GET/HEAD read-only. Require explicit form-key-protected POST confirmation, keep cross-browser withdrawal and later-grant protection, and synchronize banner preferences only in the confirmed POST result.
- Centralize banner and email withdrawals in one atomic core checkpoint with typed, DI-registered module handlers. Persist unfinished actions alongside revocation, run cleanup after commit, and retry failures through native cron and locks without restoring consent or touching later grants. Retain the legacy event as a notification.
- Use Magento's cookie path and domain for browser identities and withdrawal hints; keep their admin-configured consent lifetime and remove the hint's hardcoded lifetime fallback.
- Generate browser and consent identifiers with Magento's random generator while retaining 32 lowercase hex characters; use Magento SQL expressions for withdrawal timestamps.
- Generate and verify withdrawal signatures exclusively through Magento's native encryptor, without a legacy raw-key fallback; reject invalid deployment keys or signing failures without reading or withdrawing consent.
- Explain that a newer consent remains active when an already-used email withdrawal link is reopened in its original browser.

- Reject malformed endpoint/cookie inputs, enforce form keys for AJAX and keep withdrawal HEAD requests free of side effects.
- Add a shared signed withdrawal-link API, purpose-specific confirmation page and browser preference sync, independent of feature data; protect later explicit grants from repeated link use.
- Consent recording, browser identity, evidence snapshots and reliable withdrawal through shared APIs.
- Prepare Composer metadata, Magento dependency bounds and source/distribution packaging for the first public release.
- Adapt unit tests to PHPUnit 12 while retaining older data-provider annotations.
- Repair the Composer/source-mount integration-test bootstrap and inherit the production consent-id collation in temporary fixtures.
- Add PHP syntax/metadata/archive CI checks and explicit compatibility notes.
- Correct admin renderer ownership, store interface annotations and scoped locale lookup for Magento-configured PHPStan level 5.

No public release has been tagged or published yet.
