# Consent deployment review

This package provides permission records and integrations, not a declaration that an entire shop complies with GDPR. Apply this review to the deployed shop and its jurisdictions, including national rules for terminal storage and email marketing.

## Implemented hardening

- Source adapter resolution waits for HTML parsing. Banner modules depend on the provider, so its listeners are registered before the banner renders.
- Purpose requests serialize, including the initial grants for multiple purposes, so they share the server-issued browser identity.
- Only an acknowledged grant for the current saved state notifies features. Withdrawal notifies OFF immediately, remains queued behind any active request, and retains its browser hint until an acknowledged response. Network reconnection / subsequent page loads retry hints; page navigation sends a beacon. A browser that never contacts the server again cannot deliver an outstanding withdrawal.
- Browser identity is HttpOnly, SameSite=Lax and Secure on HTTPS. Identity and retry-hint lifetimes use the configured source-cookie lifetime.
- Server checks and the SQL guard cap permission at the configured lifetime from the saved choice time; reducing the lifetime also invalidates older permissions. A source may expire consent sooner. This prevents old choices outliving the withdrawal-mark retention safety minimum.
- Always-on categories cannot grant feature purposes. The default reminder switch describes use of the checkout email explicitly and remains independent of analytics.
- Withdrawal marks cannot be removed before the longest configured website source-cookie lifetime plus one day. Consent evidence retention remains the configured period after expiry or withdrawal.
- Withdrawal and pending module cleanup commit together. Handlers run afterward; failed cleanup never restores permission. Unfinished `ck_consent_cleanup` tasks retain pseudonymous scope independently of evidence until their registered handler succeeds. Monitor pending tasks and restore disabled handlers; successful tasks are deleted. Module cleanup must preserve later grants and native Magento data outside its ownership.
- `consent:config:export` captures store wording, mappings, revisions, banner configuration and an optional supplied copy of the published policy. Preserve exports with the release, policy and visual evidence.

## Shop information needed before publication

Complete the [notice draft](privacy-notice-draft.md) with the legal controller/contact, real providers and recipients, data types, retention, rights and any international transfers. The local audited shop had an empty privacy URL. Do not link an unfinished draft as a published policy.

Identify the applicable rules for reminder emails and make the consent text and withdrawal mechanisms meet them. Magento holding an email is independent of permission to use it for a reminder. Keep unrelated purposes independently selectable. Retain the information presented for each revision, and raise the source revision when renewed agreement is required.

## Magento history storage finding

The local homepage audit found `recently_viewed_product`, `recently_viewed_product_previous`, `recently_compared_product`, `recently_compared_product_previous` and `product_data_storage` cookies/storage namespaces before a choice. Names alone do not establish their content or necessity.

Magento_Catalog initializes these namespaces globally and its product view provider adds product identifiers/details. Magento_Reports can separately record product views on the server. Turning off the viewed widget or reducing its display count does not stop this storage. The server's Product View report flag alone does not stop the browser cache.

Choose one deployment policy: disable the optional history feature including browser and server collection, integrate it under a distinct optional purpose such as `product_history`, or document why a particular use is strictly necessary for a service the visitor requested. Do not use `cart_reminder` permission as permission for browsing history. Compare-list functionality explicitly requested by a visitor needs separate assessment from automatic browsing history.

History policy remains a shop decision; the consent core does not silently replace Magento's catalog storage or reports. Any integration should live in a separate feature package and cover initial writes, synchronization, logged-in views and withdrawal cleanup.

## Verification scope

Verify no optional collection before any choice or after Reject; affirmative choices record their mapped purposes; withdrawal is acknowledged and invalidates queued work; revision changes prompt again; network failures and rapid changes preserve the latest state. Test the deployed theme and all selected sources.

Email-link GET/HEAD must not withdraw consent or synchronize banner preferences. Confirm through the signed, form-key-protected POST. Test scanner visits, missing/malformed form keys, other-browser confirmation, repeated POST and original-browser application of an existing withdrawal. The optional RFC 8058 Yes/No setting adds a cookie-free mail-client POST authorized by the existing signed URL alongside this flow. Verify both unsubscribe headers are covered by DKIM in production; one-click revokes the linked browser consent and does not synchronize banner cookies.

A live audit of a shop's homepage confirmed no Google tracking before acceptance and analytics cookies removed after withdrawal. Extend deployment testing to product, cart, checkout, logged-in and custom-script paths. Test optional integrations individually; the core cannot block scripts that do not consult its purpose API.

## Guidance

- [EDPB Guidelines 05/2020: consent, information, evidence and withdrawal](https://www.edpb.europa.eu/system/files/documents/files/file1/edpb_guidelines_202005_consent_en.pdf)
- [EDPB Cookie Banner Taskforce report](https://www.edpb.europa.eu/system/files/2023-01/edpb_20230118_report_cookie_banner_taskforce_en.pdf)
- [European Commission: legal grounds for processing data](https://commission.europa.eu/law/law-topic/data-protection/information-business-and-organisations/legal-grounds-processing-data_en)
