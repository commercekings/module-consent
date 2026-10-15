# CommerceKings_Consent

**Development status:** first-release feature scope frozen; polishing and verification remain before publication.

Install in a Magento project with `composer require commercekings/module-consent:^0.1` after the first
release is published. Then enable its modules:

```sh
bin/magento module:enable CommerceKings_Core CommerceKings_Consent
bin/magento setup:upgrade
bin/magento cache:clean
```

Use the shop's normal DI compilation and static-content deployment process for production.
For development/tests use source checkouts (`--prefer-source`); release archives omit `Test/`.
See [compatibility and verification](COMPATIBILITY.md) and [changes](CHANGELOG.md).

Consent records for Magento 2: "may we do **purpose X** for this visitor?", answered from a recorded, server-side consent that can be checked later, withdrawn reliably and shown as evidence. No dependency on any banner or feature module: **sources** (cookie banners, …) report the visitor's choice, **features** (e.g. the abandoned cart reminder) ask and store the consent id with their data.

```
             CommerceKings_Consent   (shared admin branding: CommerceKings_Core)
              ▲                  ▲
   registers a source      uses the API (subscriptions, withdrawal cleanup)
CommerceKings_ConsentVanilla   CommerceKings_Notification
                                 ▲
            registers a subscription type (its purpose is declared here, in Consent)
                               CommerceKings_AbandonedCart
```

## The model

- **Consent** = one choice the visitor made in the source, for one purpose, in one browser, on one **website**. Recorded once in `ck_consent` with a **server-issued `consent_id`** (random, 32 hex), the source's own identity of the choice (`reference` + `source_time`, e.g. a banner's consent id and when it was saved), the banner revision and purpose terms revision it was given for, `granted_at` (server time), `expires_at`, the hash of the **wording** the visitor saw (`snapshot_hash`, see below), and later `withdrawn_at` / `withdrawn_via` (`banner`, `email`, …). Pseudonymous browser hashes and ids, without email addresses; these are not anonymous records.
- **Valid** = not withdrawn, not expired, same source as configured for the website now, same banner revision as now, same purpose terms revision as now, within the source-cookie lifetime counted from the earlier of the source's choice time and `granted_at` (a browser clock running ahead cannot extend it; a choice dated more than a day in the future is not recorded), purpose still declared and mapped by at least one category of that source. Both PHP checks and the SQL guard enforce these conditions for each website. Anything else – no source, unknown source, no choice, a choice without identity – is **no consent** (fail closed).
- **Browser identity:** cookie `ck_consent_browser` (random, HttpOnly, SameSite Lax, Secure on HTTPS, configured source-cookie lifetime), created when a consent is recorded; only its sha256 is stored.
- **Withdrawal** withdraws every active consent of that browser for the purpose on that website and leaves a **mark** in `ck_consent_withdrawal` (per browser + purpose + website + the source's choice series): the same choices are never recorded again – an old "yes" cookie on another device, or a request that was still on its way when the visitor said no.
- **Renewal needs an explicit choice.** A choice seen for the first time is recorded when a feature asks – unless this browser withdrew that series before; then only switching the purpose on again in the source counts (`/ckconsent/grant`). Bundled sources may confirm all purposes on an explicit Save or Accept all; loading a saved choice never renews permission. A missing cookie never creates a consent.

## For features

Email notifications: use `commercekings/module-notification`. Its subscriptions do steps 2, 3 and 5 below for
you (consent at subscribe time, the guarded write, withdrawal cleanup); the feature declares its purpose (step 1)
and checks consent in its own send claim (step 4).

1. **Declare the purpose** (your `di.xml`):
   ```xml
   <type name="CommerceKings\Consent\Model\PurposePool">
       <arguments>
           <argument name="purposes" xsi:type="array">
               <item name="cart_reminder" xsi:type="array">
                   <item name="label" xsi:type="string">Abandoned cart reminder</item>
                   <item name="terms_revision" xsi:type="string">1</item>
               </item>
           </argument>
       </arguments>
   </type>
   ```
   Raise `terms_revision` when what the visitor agrees to changes – every consent given under the old terms stops being valid.

   `record` (optional): `required` (default) when your feature needs the saved consent to work – a cron, queue or email checks it; every consent is recorded. `optional` when the feature works from the source's cookie alone (analytics, pixels): the shop chooses in the admin (*Record consent for*, default not recorded) whether to keep evidence. Unrecorded, the JS provider makes no server call for it, nothing is stored, and the server never treats it as valid (`getCurrent()` null, left out of the guard) – so never make a purpose `optional` if server code checks it.

   `category` (optional): the default banner category the purpose belongs to (`analytics`, `marketing`, … of `CommerceKings_ConsentDefaults`). With the preset, the purpose appears in that category without the preset knowing it. Declare **one purpose per integration** (e.g. `google_analytics`, `google_ads`) and let several share a category – each keeps its own consent, records, recording setting, cookies and `active_if`.

   `active_if` (optional): config flags (store view scope); the purpose counts only while at least one is on – e.g. `google_analytics` while Magento's GA4 or Universal Analytics is enabled. Off, it is treated as not declared: its banner category is hidden, it never has consent, and the banner only asks about what the shop really does. Use it only for purposes the browser uses (cron and queues check consents without a store view).
2. **When the visitor's data is captured** (a POST request): `Api\ConsentInterface::getCurrent('cart_reminder'): ?Api\Data\Consent`. Store `$consent->consentId` with your data. May set the browser cookie.
3. **Store it race-safely:** put `Api\ConsentGuardInterface::validCondition($column)` into the same UPDATE that writes or uses the consent id (`$column` can be a quoted literal: `$db->quote($consentId)`). The database then orders it against a withdrawal.
4. **Later (cron, queue consumer – no cookies):** `isValid($consentId)`, or the guard condition inside your claim UPDATE.
5. **Withdrawal:** implement `Api\WithdrawalHandlerInterface` and register a named handler in `Model\WithdrawalPool` (see below). Consent commits revocation and pending actions together, then runs cleanup. Your feature owns its data cleanup. For email links, inject `Api\WithdrawalLinkInterface` and call `getUrl($consentId)` in a store on that consent's website. Consent owns the signed link, confirmation page and browser preference sync. Trusted server code can also call `withdraw($consentId, 'email')` directly, outside a caller-owned database transaction.
6. **Browser:** `CommerceKings_Consent/js/consent/provider` – `hasConsent(purpose)`, `onChange(purpose, callback(isOn))` (on "on" the server has recorded the choice before your callback runs), `accept(purpose)` / `revoke(purpose)` (save the mapped source choice while preserving other choices).

### The guarantee
Once a withdrawal has been stored, no guarded write or claim can use that consent any more: either the guarded UPDATE runs first (and the withdrawal handler then finds its row), or it sees the consent withdrawn and changes nothing. A mail already handed to the mail server can't be recalled. This relies on InnoDB locking reads in `UPDATE … WHERE EXISTS (SELECT …)` under REPEATABLE READ (Magento's default) – verified with two connections against MariaDB 10.6.

### Withdrawal handlers

```xml
<type name="CommerceKings\Consent\Model\WithdrawalPool">
    <arguments>
        <argument name="handlers" xsi:type="array">
            <item name="your_purpose" xsi:type="array">
                <item name="your_module_cleanup" xsi:type="object">Vendor\Module\Model\WithdrawalHandler</item>
            </item>
        </argument>
    </arguments>
</type>
```

The handler implements `execute(Api\Data\WithdrawalContext $context): void`. Context contains
`purpose`, `consentIds`, `websiteId`, `browserHash` and `via`; no email or feature payload. Several
handlers may share a purpose. Use a stable registration name (up to 80 characters); pending tasks
refer to that name across deployments. The core works without any handlers.

Both banner and signed-link withdrawal use one checkpoint. Magento's native lock manager serializes
the browser/purpose/website scope. Withdrawal marks, revoked consent records and pending actions in
`ck_consent_cleanup` are written in one database transaction. Handlers run after commit, with no
scope lock held. A failed handler cannot restore permission or stop another handler from running.
The shared browser identity remains available for other purposes; evidence is marked withdrawn,
not deleted immediately.

Execution is **at least once**: a task is removed only after its handler returns successfully.
Handlers must be idempotent, use only the original consent IDs, and work without a visitor session.
The current implementation sends one consent ID per task; do not assume the context is tied to the
current browser, customer or a still-retained evidence row. Preserve later grants and unrelated
data. A module can use its own transaction for local cleanup; external operations must also tolerate
retries. Clearing feature input (such as a checkout email) is separate from withdrawing consent.

Cron `commercekings_consent_retry_withdrawals` runs each minute, processes up to 100 due tasks, and
backs off failed actions from one minute to one hour. Native task locks prevent concurrent workers
from executing the same task; use an appropriate shared Magento lock backend in a multi-node shop.
Successful tasks are deleted. Unfinished tasks survive evidence pruning and remain pending if their
module is disabled; restore its handler to resume cleanup. Review pending tasks in `ck_consent_cleanup`
and cleanup warnings in Magento logs. Logs omit exception messages to avoid leaking feature data.

The existing `commercekings_consent_withdrawn` event retains its payload for compatibility and fires
after commit and immediate handler attempts. It is a notification, has no durable retry, and observer
failures are logged without undoing withdrawal. Move mandatory cleanup into a pool handler, removing
the corresponding observer so the action runs once per attempt. Withdrawal success means permission
is revoked; module cleanup may still be pending.

## What the module does by itself
- `GET /ckconsent/optout?c=&t=` – validates a signed email link and displays a read-only confirmation form. It never withdraws or synchronizes banner preferences, including for links inspected by scanners. A valid unused link works in any browser; other browsers see a generic question without feature data. Already-used links show the result in the original browser and redirect other browsers home. Invalid or cross-website links redirect home; HEAD does not render interactive content.
- `POST /ckconsent/optout` – the visitor explicitly submits the signed consent ID/token with Magento's session form key. Both normal and AJAX POSTs require a valid string form key. The same central withdrawal checkpoint revokes consent and schedules cleanup. The original browser sees the result and synchronizes only a currently withdrawn source choice; another browser redirects home after confirming, without changing its own preferences. Repeated submissions and old links preserve a later explicit grant. GET pages never auto-submit the form or run synchronization JavaScript. The uncached form prevents shared form keys; signed pages send a no-referrer policy.
- The signature binds the consent ID and website to the deployment `crypt/key`; the URL contains no email or feature identifier. Links remain usable while evidence is retained, including after expiry; deleting evidence or rotating the signing key invalidates them. Browser preference sync runs only in the POST result. If withdrawal was confirmed elsewhere, the original browser can explicitly apply it with the form. Other categories and newer choices remain untouched; purposes sharing one category follow that category together. **One-click unsubscribe** is a Yes/No setting under CommerceKings > Consent, per website and set to No by default. It adds mail-client headers alongside the regular confirmation link. The same signed URL accepts a cookie-free form POST with `List-Unsubscribe=One-Click`; the same withdrawal checkpoint and cleanup run, with no redirects or banner synchronization. Already-sent links continue working when the setting is No, and replay preserves newer grants. The provider must DKIM-sign both unsubscribe headers ([RFC 8058](https://www.rfc-editor.org/rfc/rfc8058.html#section-4)). This withdraws the linked browser consent, not independent grants sharing an email address.
- `POST /ckconsent/grant?purpose=` – sent by the provider when the source reports "on"; records the choice if the source really says yes.
- `POST /ckconsent/withdraw?purpose=` – an acknowledged POST on "off", with a navigation beacon and retry hints if it fails; withdraws only if the source's **current** choice says no.
- Browser grant and withdrawal POSTs require a valid Magento form key, including AJAX requests. Malformed form keys and purpose values are rejected before service calls. Malformed source-cookie fields cannot grant consent.
- Grant and withdrawal POSTs are serialized so initial grants share one browser identity. A late grant response cannot notify features after withdrawal. All bundled banners wait for the provider before showing interactive controls.
- On page load and network reconnection the provider catches up a missed "off" (cookie `ck_consent_seen`, only a hint for the script – never trusted by the server). No current choice (e.g. a raised banner revision) is not an "off". Server permission never lasts beyond the configured lifetime from the saved choice time, even if source metadata says longer; reducing the setting also limits existing permissions. The hint lasts for the configured source-cookie lifetime and remains until withdrawal is acknowledged.
- Browser identity and hint cookies use Magento's configured cookie path and domain. Their duration comes from Consent's *Cookie lifetime (days)*, independently of Magento's shorter session-cookie lifetime. An older cached page without a hint lifetime creates a session hint rather than applying a fixed default.
- `#commercekings-consent-config` in `<head>`: chosen source, its JS adapter, the purposes, the source's settings. The loader plugin `js/consent/adapter!` loads **only** the chosen adapter; none / load error → a null adapter (never consent).
- Cron `commercekings_consent_clean_up` (daily 03:30): deletes consent records *Record retention (days)* after expiry or withdrawal. Withdrawal marks are retained for at least the longest website source-cookie lifetime plus one day, even if evidence retention is shorter.

Email withdrawal signatures use Magento's `EncryptorInterface::hash()` (HMAC-SHA256 with
Magento's current, decoded deployment key) for generation and verification. There is no
legacy raw-key fallback; older development links can stop working when their signatures
differ from Magento's current-key signatures. Tokens stay 64 lowercase hex characters and are compared with
`hash_equals`; the password-hash formats accepted by `validateHash()` are not link signatures.
Website isolation, locked withdrawal and replay protection still apply. A missing,
blank or non-string deployment key prevents link generation and signature validation fails closed.

## Admin
**Stores > Configuration > CommerceKings > Consent** (default / website), grouped as **Source**, **Records**, **Withdrawal** and **Privacy**. The privacy policy field is last. Only the **Source** selector remains visible when set to **None**. Editable: *Source* (None or a registered source – its banner is the one shown; changing it invalidates every consent of the old one), *Privacy policy page* (every banner links it – `Api\BannerSettingsInterface`), **Consent cookie name** (the cookie the banner keeps the visitor's choice in; changing it asks everyone again), **Source revision** (raising it asks every visitor again – the shop's decision, so a package upgrade never changes it) and **Consent lifetime (days)** (default 182; it also caps server permission from the saved choice time; a source may expire it sooner), *Record retention (days)* (default 183). **Record consent for** (website; required purposes are selected and locked, optional purposes start unselected – see `record`; selecting an optional purpose records future choices. The field is hidden when no source is selected). **Read-only** – defined in code, shown for review: *Source version*, *Source categories* (with the purposes each grants and the cookies deleted when off). Flush the full page cache after a change.

CommerceKings_Core provides the shared admin tab, crown icon and Developed by **CommerceKings** footer linking to `https://commercekings.net` in a new tab.

## Adding a source
The adapter defines its library, cookie readers and registration in **code**. Select the source in admin or site `etc/config.xml`. `CommerceKings_ConsentDefaults` supplies a shared preset when a source has no explicit categories; it is optional for this core package and required by `CommerceKings_ConsentVanilla`. A site module can replace the preset with complete categories, descriptions and purpose mappings in `di.xml`. The core alone supplies no categories and grants no purpose without a configured source and mapping.

Removing a purpose mapping also invalidates its stored consents, so queued work cannot use them. The records remain as evidence; removing configuration is not a visitor withdrawal. Restoring the mapping can make otherwise valid records usable again. Raise the source revision if visitors must agree again.

`Model\CategoryMapper` provides shared category normalization, accepted-category to purpose conversion, and the first granting category per purpose for frontend configuration. Every source (e.g. `CommerceKings_ConsentVanilla`) injects this helper; each source keeps its own cookie reading, category-key restrictions and frontend configuration shape. The helper accepts an optional `defaultCategories` DI array. Nonempty source categories replace that preset entirely; categories with `hide_if_unmapped=true` are hidden when none of their purposes is declared.

1. A class implementing `Api\SourceInterface`:
   - `getCode()` – stored with every consent; `getVersion()` – the library version (newest cookie format it reads);
   - cookie name, revision and cookie lifetime are the shop's admin settings, not the source's: read them with `Api\SourceConfigInterface::getCookieName()` / `getRevision()` / `getCookieLifetimeDays()`;
   - `getCategories()` – the source categories in display order: key => label, description, required, **purposes** (purposes the category grants); labels through `__()` (Magento i18n);
   - `getChoice(): ?Api\Data\SourceChoice` – the visitor's latest saved choice: `reference` (your CMP's stable id for this visitor's choices), `givenAt` (when it was saved, UTC), `revision`, the purposes of the accepted categories, optional `expiresAt`. Null when there is none or it was made for another revision;
   - `getClientConfig()` – settings for your JS adapter.

   If your banner's library changes its cookie layout between versions, write one `Api\CookieFormatInterface` reader per layout and let your source pick the newest that `matches()` the cookie. Extend `Model\CookieFormat\AbstractCookieFormat` and only declare where each value sits (`VERSION`, `CATEGORIES`, `REFERENCE`, `TIME`, `REVISION`, `EXPIRES` – dotted paths; categories as a list or a `{category: bool}` map) – see ConsentVanilla's `CookieFormat\V3`.
2. A JS adapter returning `{hasChoice(), hasConsent(purpose), onChange(purpose, callback(isOn)), accept(purpose), revoke(purpose)}` – `hasChoice` = a saved choice valid for the current revision; call callbacks only when THAT purpose was saved on/off.
3. Register it (your `di.xml`) in `CommerceKings\Consent\Model\SourcePool` → argument `sources`: `<item name="code" xsi:type="array">` with `label`, `provider` (object), `adapter` (RequireJS module). Optionally register `banner` (object implementing `Api\BannerConfigInterface`) to include the same translated banner configuration in evidence exports.

Sources never store or withdraw anything themselves. Feature modules own tracking, content loading and cleanup. They may declare `cookies` (cookie-name regexes) alongside their purpose label and terms revision; `PurposeRegistryInterface::getCookiePatterns()` exposes this metadata for the admin table, which derives patterns from mapped purposes. Purposes: `Api\PurposeRegistryInterface`. See `CommerceKings_ConsentVanilla` for a complete example.

## Wording evidence (what the visitor agreed to)

Every recorded consent stores `snapshot_hash`: the sha256 of the wording current in that store view when it was recorded – banner texts (the source's banner config, translated), categories and the purposes each grants, purposes and their terms revisions, source and version, source revision, store and locale, the privacy URL and, when it is a CMS page of this shop, the page's title and content. Each distinct wording is saved once in `ck_consent_snapshot` (`Model\WordingSnapshot`); the same wording always gives the same hash, so one row serves every visitor until something changes.

- `bin/magento consent:evidence:show <consent_id>` prints the consent record with the wording it was given under (and whether the stored text still matches its hash).
- Admin, *Wording since revision*: per store view, whether the wording changed since visitors first answered the current source revision. A changed text never asks anyone again – raising the source revision does; the row only tells you when that decision is due.
- Not captured: a privacy page outside Magento (only its URL), theme CSS/layout of the banner. Consents recorded before this existed have no snapshot – use the exports below.
- Snapshots no consent points to are deleted by the clean-up cron after *Record retention (days)*.

## Configuration evidence and shop disclosures

### Admin export

Open **System > Data Transfer > Export** and choose **Consent records** or **Consent configuration**, then **CSV**. Use Magento's filters and Continue button; download the file from the export list when processing finishes. Access uses the existing **System > Data Transfer > Export** role permission, which allows exporting across websites. Exported consent records include pseudonymous browser hashes; restrict access and keep downloads only as long as needed.

- **Consent records** exports recorded choices, including expired and withdrawn records still within retention. Filter by consent ID, purpose, browser hash, website ID, source, revisions or dates. Date filters cover whole UTC days using the admin's date format. Each row includes the original `wording_json`, its `snapshot_hash`, `wording_first_seen`, `wording_hash_matches` (1/0), and `evidence_json` containing the same proof as `consent:evidence:show`. Missing historical wording stays absent; current wording never substitutes for it. No records exist for choices made without recording enabled.
- **Consent configuration** exports one row per storefront store view, including inactive views and excluding the admin store. Filter by store view ID, code or website ID. Each view uses its own locale and settings. `configuration_json` contains the full configuration evidence, including categories, purposes, source wording, retention and the configured CMS policy text when available. It describes the configuration at processing time.

The **Exclude** checkboxes remove optional display columns; consent ID, store identity and structured evidence columns remain present. Full JSON evidence retains the original values even when their display columns are excluded. Cells that could execute as spreadsheet formulas are prefixed with an apostrophe; the JSON evidence and original wording remain unchanged. CSV rows are fetched in pages through Magento's collection iterator.

Exports use Magento's standard `import_export.export` queue and **exportProcessor** consumer, with the site's configured queue transport. If consumers do not run automatically:

```sh
bin/magento queue:consumers:start exportProcessor --max-messages=1
```

Files use Magento's normal export storage and download lifecycle; they are not a permanent archive. Preserve reviewed exports outside Magento's temporary storage.

### CLI export

The existing JSON commands remain available. Run `bin/magento consent:config:export --store=<store-view-code>` and redirect stdout to an archival JSON file. Run once for every store view and every source revision before release. The export includes translated category wording, exact purpose mappings and terms revisions, source configuration, version and retention settings. The configured Magento CMS privacy page (URL key) is included automatically when available. For an external policy, pass `--policy-file=<local-published-policy-copy>` to include its exact text and SHA-256; this also overrides the configured CMS copy. External URLs are never fetched. Without an available copy, policy evidence stays explicitly absent; a URL alone cannot preserve historical text.

Keep each export with the deployed commit, `composer.lock`, theme/translation files, a banner screenshot and the published policy. Store archives durably outside `var/` and restrict access to visitor consent records separately. Changes that need renewed agreement require a new source revision and, where appropriate, purpose terms revision. Exports are release-time configuration evidence; per-consent wording is kept automatically (above). Existing consent records reference their banner and terms revisions. Do not overwrite historical exports.

Always-on categories cannot grant a feature purpose. Give unrelated optional purposes independently selectable categories and explain each data use clearly. The optional default preset puts `cart_reminder` under **Personalization** and names the use in the description (an email reminder to the checkout address); every purpose added to a category must be named in its description.

The shop supplies its controller/contact details, actual recipients/providers, data uses, retention and rights information, with a published privacy-page link. See [the notice draft](docs/privacy-notice-draft.md) and [the deployment review](docs/privacy-review.md). These must reflect the shop's real processing. Feature modules must enforce permissions and withdrawals; the core does not intercept arbitrary third-party scripts or automatically classify every Magento storage entry. Cookie access and reminder emails also need review under applicable national ePrivacy/marketing rules.

## Tables
| Table | |
|---|---|
| `ck_consent` | one row per recorded consent (PK `consent_id`, unique per choice) |
| `ck_consent_snapshot` | wording snapshots (PK `snapshot_hash`), one per distinct wording per store view |
| `ck_consent_withdrawal` | withdrawal marks: latest withdrawn source time per browser + purpose + website + choice series |
| `ck_consent_cleanup` | unfinished module cleanup tasks, removed on success; retained independently of evidence |

## Tests
From the Magento root, with the package installed:
```sh
vendor/bin/phpunit --no-configuration --bootstrap vendor/autoload.php vendor/commercekings/module-consent/Test/Unit
```
`Test/Unit/InMemoryConsentStore.php` mirrors the store's SQL rules (unique choice, marks) so the lifecycle tests run without a database.

Run the mapping regression against a local Magento database with the module schema installed:
```sh
vendor/bin/phpunit --no-configuration --bootstrap vendor/autoload.php vendor/commercekings/module-consent/Test/Integration/ConsentMappingTest.php
vendor/bin/phpunit --no-configuration --bootstrap vendor/autoload.php vendor/commercekings/module-consent/Test/Integration/WithdrawalWorkflowTest.php
vendor/bin/phpunit --no-configuration --bootstrap vendor/autoload.php vendor/commercekings/module-consent/Test/Integration/NativeExportTest.php
```
It uses temporary tables and checks stored consent validation and guarded job updates across two websites, including removal and restoration of mappings. It leaves application records unchanged. To run from another directory, set `MAGENTO_ROOT` to the installed Magento root; the test supports Composer installs and source mounts without relying on package directory depth.

The native export test checks filter metadata, paginated SQL reads, historical wording, CSV escaping, exclusions, configuration scope and the standard export consumer's finished file. Its connection-local temporary tables and export files are removed after each test; it does not consume the application's queue.
