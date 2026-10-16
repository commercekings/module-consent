# For features

Asking for consent and handling withdrawal in your own module. Start with the [README](../README.md).

Email notifications: use `commercekings/module-notification` with `commercekings/module-consent-notification`.
Its subscriptions do steps 2, 3 and 5 below for you (consent at subscribe time, the guarded write, withdrawal
cleanup); the feature declares its purpose (step 1) and checks the permission in its own send claim (step 4).

Before handing a visitor something the consenting visitor owns (e.g. a saved cart), ask
`Api\ConsentOwnershipInterface::isCurrentVisitor($consentId)`: true only in the browser the consent was given in.

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

## The guarantee
Once a withdrawal has been stored, no guarded write or claim can use that consent any more: either the guarded UPDATE runs first (and the withdrawal handler then finds its row), or it sees the consent withdrawn and changes nothing. A mail already handed to the mail server can't be recalled. This relies on InnoDB locking reads in `UPDATE … WHERE EXISTS (SELECT …)` under REPEATABLE READ (Magento's default) – verified with two connections against MariaDB 10.6.

## Withdrawal handlers

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
