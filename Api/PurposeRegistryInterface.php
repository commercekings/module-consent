<?php
declare(strict_types=1);

namespace CommerceKings\Consent\Api;

/**
 * Declared consent purposes. Modules declare theirs in di.xml (Model\PurposePool "purposes"):
 *
 *   <item name="cart_reminder" xsi:type="array">
 *       <item name="label" xsi:type="string">Abandoned cart reminder</item>
 *       <item name="terms_revision" xsi:type="string">1</item>
 *       <item name="record" xsi:type="string">required</item>
 *   </item>
 *
 * record: "required" (default) - the feature needs the saved consent to work (e.g. a cron or queue
 * checks it before sending), so every consent is recorded. "optional" - the feature works from the
 * source's cookie alone (analytics, pixels); the shop decides in the admin whether to keep each choice
 * as evidence (Record consent for, default: not recorded). An unrecorded purpose is never valid on
 * the server (getCurrent() null, left out of the guard).
 *
 * category (optional): the default banner category the purpose belongs to (e.g. "analytics",
 * "marketing" in ConsentDefaults). With the preset categories, the purpose is shown in that category
 * without the preset having to know it; explicit source categories list their purposes themselves.
 * One purpose per integration (google_analytics, google_ads, ...) so each has its own consent,
 * records, recording setting, cookies and active_if - several can share one banner category.
 *
 * active_if (optional): config flags (store view scope) - the purpose counts only while at least one
 * is on, e.g. analytics while Magento's GA or Consent Mode is enabled. Off, it is treated as not
 * declared: its banner category is hidden and it never has consent. Only for purposes the browser
 * uses - a cron or queue checks consents without a store view.
 *
 * Raise terms_revision when what the visitor agrees to changes: every consent for that purpose given
 * under the old terms stops being valid.
 */
interface PurposeRegistryInterface
{
    /** @return string[] the active purposes */
    public function getCodes(): array;

    /**
     * @param int|null $websiteId whose settings decide "active_if" (null: the current store) – cron and queue
     *     consumers check consents of every website from one store
     */
    public function isDeclared(string $purpose, ?int $websiteId = null): bool;

    public function getLabel(string $purpose): string;

    public function getTermsRevision(string $purpose): string;

    public const RECORD_REQUIRED = 'required';
    public const RECORD_OPTIONAL = 'optional';

    /** The default banner category the purpose declares ('' = none). */
    public function getDefaultCategory(string $purpose): string;

    /** True when the purpose declares record = optional: the shop may choose not to record it. */
    public function isRecordOptional(string $purpose): bool;

    /** @return string[] Feature-owned cookie-name regexes. Cleanup belongs to the feature module. */
    public function getCookiePatterns(string $purpose): array;
}
