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
 * declared: its banner category is hidden and it never has consent. Where it counts: in a store view,
 * that store view's settings (the banner a visitor sees); for a website, on in ANY of its store views
 * (a consent given in one store view stays valid wherever its website's consents are checked, e.g. by a
 * cron or queue consumer running in another store view).
 *
 * Raise terms_revision when what the visitor agrees to changes: every consent for that purpose given
 * under the old terms stops being valid.
 *
 * Both are stored with every consent: a purpose code has at most CODE_LENGTH (64) characters and a terms_revision at
 * most TERMS_REVISION_LENGTH (32); a longer one is refused when the purposes are declared.
 */
interface PurposeRegistryInterface
{
    public const CODE_LENGTH = 64;
    public const TERMS_REVISION_LENGTH = 32;

    /** @return string[] the active purposes */
    public function getCodes(): array;

    /**
     * Whether the purpose is declared and active (active_if) for a target:
     * - $storeId: that store view's settings (where a visitor grants or withdraws);
     * - $websiteId alone: active in any of the website's store views (validity, guards - cron and queue
     *   consumers check consents of every website from one store view);
     * - neither: the current store view (or, while consents of one website or store view are being checked,
     *   that target - e.g. a source's categories built for it).
     *
     * @param int|null $websiteId the website (null: see above)
     * @param int|null $storeId the store view (since 0.1.1; null: see above)
     */
    public function isDeclared(string $purpose, ?int $websiteId = null, ?int $storeId = null): bool;

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
