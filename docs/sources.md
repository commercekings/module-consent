# Adding a source

Connecting a cookie banner or another consent source. Start with the [README](../README.md).

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
