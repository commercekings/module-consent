# How it works

The consent model and what `commercekings/module-consent` does by itself. Start with the [README](../README.md).

## Modules

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

## What the module does by itself
- `GET /ckconsent/optout?c=&t=` – validates a signed email link and displays a read-only confirmation form. It never withdraws or synchronizes banner preferences, including for links inspected by scanners. A valid unused link works in any browser; other browsers see a generic question without feature data. Already-used links show the result: the original browser with the purpose, other browsers a generic confirmation. Invalid, damaged or cross-website links show a "Link expired" page that changes nothing; HEAD redirects home and does not render interactive content.
- `POST /ckconsent/optout` – the visitor explicitly submits the signed consent ID/token with Magento's session form key. Both normal and AJAX POSTs require a valid string form key. The same central withdrawal checkpoint revokes consent and schedules cleanup. The original browser sees the result and synchronizes only a currently withdrawn source choice; another browser sees a generic confirmation, without changing its own preferences. Repeated submissions and old links preserve a later explicit grant. GET pages never auto-submit the form or run synchronization JavaScript. The uncached form prevents shared form keys; signed pages send a no-referrer policy.
- The signature binds the consent ID and website to the deployment `crypt/key`; the URL contains no email or feature identifier. Links remain usable while evidence is retained, including after expiry; deleting evidence or rotating the signing key (`encryption:key:change`) invalidates them: such a link, or one damaged by a mail client, shows a "Link expired" page: nothing changes, and it points to "Cookie settings". New emails are signed with the new key, and subscriptions need no action. Browser preference sync runs only in the POST result. If withdrawal was confirmed elsewhere, the original browser can explicitly apply it with the form. Other categories and newer choices remain untouched; purposes sharing one category follow that category together. **One-click unsubscribe:** the headers are switched on in CommerceKings > Notifications (per website, default No); Consent only receives the mail client's POST. The same signed URL accepts a cookie-free form POST with `List-Unsubscribe=One-Click`; the same withdrawal checkpoint and cleanup run, with no redirects or banner synchronization. Already-sent links continue working when the setting is No, and replay preserves newer grants. The provider must DKIM-sign both unsubscribe headers ([RFC 8058](https://www.rfc-editor.org/rfc/rfc8058.html#section-4)). This withdraws the linked browser consent, not independent grants sharing an email address.
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
**Stores > Configuration > CommerceKings > Consent** (default / website), grouped as **Source**, **Records** and **Privacy** (one-click unsubscribe headers are a CommerceKings > Notifications setting). The privacy policy field is last. Only the **Source** selector remains visible when set to **None**. Editable: *Source* (None or a registered source – its banner is the one shown; changing it invalidates every consent of the old one), *Privacy policy page* (every banner links it – `Api\BannerSettingsInterface`), **Consent cookie name** (the cookie the banner keeps the visitor's choice in; changing it asks everyone again), **Source revision** (raising it asks every visitor again – the shop's decision, so a package upgrade never changes it) and **Consent lifetime (days)** (default 182; it also caps server permission from the saved choice time; a source may expire it sooner), *Record retention (days)* (default 183). **Record consent for** (website; required purposes are selected and locked, optional purposes start unselected – see `record`; selecting an optional purpose records future choices. The field is hidden when no source is selected). **Read-only** – defined in code, shown for review: *Source version*, *Source categories* (with the purposes each grants and the cookies deleted when off). Flush the full page cache after a change.

CommerceKings_Core provides the shared admin tab, crown icon and Developed by **CommerceKings** footer linking to `https://commercekings.net` in a new tab.

## Tables
| Table | |
|---|---|
| `ck_consent` | one row per recorded consent (PK `consent_id`, unique per choice) |
| `ck_consent_snapshot` | wording snapshots (PK `snapshot_hash`), one per distinct wording per store view |
| `ck_consent_withdrawal` | withdrawal marks: latest withdrawn source time per browser + purpose + website + choice series |
| `ck_consent_cleanup` | unfinished module cleanup tasks, removed on success; retained independently of evidence |
