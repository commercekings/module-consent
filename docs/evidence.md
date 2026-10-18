# Evidence

What a visitor agreed to, exports and shop disclosures. Start with the [README](../README.md).

## Wording evidence (what the visitor agreed to)

Every recorded consent stores `snapshot_hash`: the sha256 of the wording current in that store view when it was recorded – banner texts (the source's banner config, translated), categories and the purposes each grants, purposes and their terms revisions, source and version, source revision, store and locale, the privacy URL and, when it is a CMS page of this shop, the page's title and content. Each distinct wording is saved once in `ck_consent_snapshot` (`Model\WordingSnapshot`); the same wording always gives the same hash, so one row serves every visitor until something changes.

- `bin/magento consent:evidence:show <consent_id>` prints the consent record with the wording it was given under (and whether the stored text still matches its hash).
- Admin, *Source text status* (Consent > Source): per store view, whether the wording changed since visitors first answered the current source revision. A changed text never asks anyone again – raising the source revision does; the row only tells you when that decision is due.
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

Always-on categories cannot grant a feature purpose. Give unrelated optional purposes independently selectable categories and explain each data use clearly. AbandonedCart declares `cart_reminder` with `category = personalization`, and the optional default preset's Personalization description names that use (an email reminder to the checkout address); every purpose added to a category must be named in its description.

The shop supplies its controller/contact details, actual recipients/providers, data uses, retention and rights information, with a published privacy-page link. See [the notice draft](privacy-notice-draft.md) and [the deployment review](privacy-review.md). These must reflect the shop's real processing. Feature modules must enforce permissions and withdrawals; the core does not intercept arbitrary third-party scripts or automatically classify every Magento storage entry. Cookie access and reminder emails also need review under applicable national ePrivacy/marketing rules.
