# Provable Consent<a href="https://commercekings.net"><picture><source media="(prefers-color-scheme: dark)" srcset="https://raw.githubusercontent.com/commercekings/module-consent/main/.github/commercekings-dark.svg"><img align="right" width="152" src="https://raw.githubusercontent.com/commercekings/module-consent/main/.github/commercekings-light.svg" alt="Built by CommerceKings. Ready to sell more? Talk to us"></picture></a>

Works with Magento 2.4.4+ · Luma and Hyvä · PHP 8.1–8.5

[![License](https://badgen.net/github/license/commercekings/module-consent)](LICENSE) [![Release](https://badgen.net/github/release/commercekings/module-consent)](https://github.com/commercekings/module-consent/releases) [![Checks](https://github.com/commercekings/module-consent/actions/workflows/checks.yml/badge.svg?branch=main)](https://github.com/commercekings/module-consent/actions/workflows/checks.yml)

**Most banners only set a cookie. This one keeps the proof** – recorded on the server, withdrawn reliably, exported as
evidence.

- **Proof when you need it.** Each consent keeps the exact wording the visitor saw; export it as CSV from the admin.
- **Withdrawal that really stops things.** Queued emails are dropped the moment a shopper says no, and an old "yes"
  cookie can't come back.
- **Changed wording? Everyone is asked again.** Raise the revision; old consent never covers new terms.
- **No email addresses stored.** A consent belongs to a browser, not a person.
- **You decide what to record** – always for server features like reminders, optional for analytics.

It answers "may we do **purpose X** for this visitor?" – also later, in cron or a queue – and knows no banner and no
feature: a **source** (a cookie banner) reports the visitor's choice, a **feature** asks and keeps the consent id.

## Installation

Usually a banner or the privacy bundle brings it. On its own:

```sh
composer require commercekings/module-consent:^0.1
bin/magento setup:upgrade
```

Without a selected source nothing has consent: the module fails closed.

## How it fits

- **A banner** – install a source, e.g. `commercekings/module-consent-vanilla` (it brings the default categories).
- **Email notifications** – install `commercekings/privacy-bundle` (the bridge and the banner).
- **Your own feature** – declare a purpose and ask the API ([for features](docs/for-features.md)).

## The model

- A **consent** is one choice, for one purpose, in one browser, on one website, stored in `ck_consent` with a
  server-issued id.
- It is **valid** while not withdrawn or expired and still under today's source, banner revision and purpose terms.
  Raising either revision asks everyone again.
- A **withdrawal** works in the banner or through an email link.
- **Signing in or out doesn't change it.** The consent stays with the browser, so on a shared computer the next
  visitor sees the previous choice until they change it in the banner.
- **Independent of Magento's customer data:** consents belong to a browser, not an account, so deleting a customer
  doesn't delete them; they expire with *Record retention*.

## Settings

**Stores > Configuration > CommerceKings > Consent** (default / website):

| Field | Default |
|---|---|
| Source | None – select the banner |
| Source revision | 1 – raise it to ask every visitor again |
| Consent cookie name | `cc_cookie` – changing it asks everyone again |
| Consent lifetime (days) | 182 |
| Record consent for | required purposes always; optional ones (e.g. analytics) only if ticked |
| Record retention (days, global) | 183 after expiry or withdrawal |
| Privacy policy page | empty – every banner links it |

Categories and purposes are defined in code and shown read-only. Flush the full page cache after a change.

## Evidence

`bin/magento consent:evidence:show <consent_id>` prints a consent with the wording it was given under.
**System > Data Transfer > Export** exports *Consent records* and *Consent configuration* as CSV. See
[evidence](docs/evidence.md) and the drafts for your [privacy notice](docs/privacy-notice-draft.md) and
[deployment review](docs/privacy-review.md).

## Extending

`Api\ConsentInterface` – ask for and record consent

`Api\ConsentGuardInterface` – SQL guard against a withdrawal at the same moment

`Api\WithdrawalLinkInterface` – a signed withdrawal link for your emails

`Api\PurposeRegistryInterface` – the declared purposes and their categories

`Api\BannerSettingsInterface` – settings every banner shares (privacy policy page)

`Api\WithdrawalHandlerInterface` – your cleanup when consent is withdrawn

`Api\ConsentOwnershipInterface` – is this the browser that consented?

`Api\SourceInterface` – a new banner, see [adding a source](docs/sources.md)

`js/consent/provider` – the same in the browser (Luma)

`ck-consent:ready` / `window.CommerceKingsConsent.provider` – the same on Hyvä, detected automatically

## Limitations

- A consent works only in the browser it was given in; it is not tied to an email address.
- An email already handed to the mail server cannot be recalled by a withdrawal.
- It does not block third-party scripts by itself; each feature checks its purpose.
- Each new banner choice adds a record, and the grant endpoint is anonymous: a script can add rows until they expire.
  Rate-limit `/ckconsent/grant` (commercekings/module-rate-limit suggests 30 a minute per IP) or in your web server.
- Email withdrawal links are signed with Magento's encryption key. After `encryption:key:change` links already sent
  keep working while the old key stays in `env.php` (Magento keeps it); removing it from there ends them – they then
  show "Link expired". If you changed the key because it leaked, remove the old key once nothing else needs it:
  until then it can sign withdrawal links too (they can only withdraw a consent, never grant one, and need the
  consent's random ID).

## More

[How it works](docs/how-it-works.md) · [For features](docs/for-features.md) · [Adding a source](docs/sources.md) ·
[Evidence](docs/evidence.md) · [Testing](docs/testing.md) · [Compatibility](COMPATIBILITY.md) · [Changes](CHANGELOG.md)

## Disclaimer

These modules help you ask for, record and respect your shoppers' choices – they don't make your store compliant on
their own. You are responsible for your store's privacy: what you collect, the wording you show, your privacy policy
and whether your setup meets the laws that apply to you (e.g. GDPR, ePrivacy, email marketing rules). Get legal advice
if you are unsure.

---

**Need it installed, set up or customized?** **[Get in touch →](https://commercekings.net)**
