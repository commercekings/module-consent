# Consent records

Answers "may we do **purpose X** for this visitor?" from a consent recorded on the server – one that can be checked
later (in cron or a queue), withdrawn reliably and shown as evidence. It knows no banner and no feature: a
**source** (a cookie banner) reports the visitor's choice, a **feature** asks and keeps the consent id.

## How it fits

- **A banner** – install a source, e.g. `commercekings/module-consent-vanilla` (it brings the default categories,
  `commercekings/module-consent-defaults`).
- **Email notifications** – install the bridge `commercekings/module-consent-notification`: Notification's
  subscriptions then need a consent, and withdrawing it removes them. Without the bridge they run opt-out.
- **Your own feature** – declare a purpose and ask the API ([for features](docs/for-features.md)).

Without a selected source nothing has consent: the module fails closed.

```sh
composer require commercekings/module-consent-vanilla:^0.1   # brings this module
bin/magento setup:upgrade
```

## The model

- A **consent** is one choice, for one purpose, in one browser, on one website, stored in `ck_consent` with a
  server-issued id – no email address.
- It is **valid** while not withdrawn or expired and still under today's source, banner revision and purpose terms.
  Raising either revision asks everyone again.
- A **withdrawal** – in the banner or through an email link – revokes it at once, and the same old choice is never
  accepted again (e.g. an old "yes" cookie on another device).
- Each consent keeps a hash of the **wording** the visitor saw.

## Settings

**Stores > Configuration > CommerceKings > Consent** (default / website):

| Field | Default |
|---|---|
| Source | None – select the banner |
| Source revision | 1 – raise it to ask every visitor again |
| Consent cookie name | `cc_cookie` – changing it asks everyone again |
| Consent lifetime (days) | 182 |
| Record consent for | required purposes always; optional ones (e.g. analytics) only if ticked |
| Record retention (days) | 183 after expiry or withdrawal |
| Privacy policy page | empty – every banner links it |

Categories and purposes are defined in code and shown read-only. Flush the full page cache after a change.

## Evidence

`bin/magento consent:evidence:show <consent_id>` prints a consent with the wording it was given under.
**System > Data Transfer > Export** exports *Consent records* and *Consent configuration* as CSV. See
[evidence](docs/evidence.md) and the drafts for your [privacy notice](docs/privacy-notice-draft.md) and
[deployment review](docs/privacy-review.md).

## Extending

`CommerceKings\Consent\Api\ConsentInterface` – ask for and record consent

`CommerceKings\Consent\Api\ConsentGuardInterface` – SQL guard against a withdrawal at the same moment

`CommerceKings\Consent\Api\WithdrawalHandlerInterface` – your cleanup when consent is withdrawn

`CommerceKings\Consent\Api\ConsentOwnershipInterface` – is this the browser that consented?

`CommerceKings\Consent\Api\SourceInterface` – a new banner, see [adding a source](docs/sources.md)

`CommerceKings_Consent/js/consent/provider` – the same in the browser

## Limitations

- A consent works only in the browser it was given in; it is not tied to an email address.
- An email already handed to the mail server cannot be recalled by a withdrawal.
- It does not block third-party scripts by itself; each feature checks its purpose.

## More

[How it works](docs/how-it-works.md) · [For features](docs/for-features.md) · [Adding a source](docs/sources.md) ·
[Evidence](docs/evidence.md) · [Testing](docs/testing.md) · [Compatibility](COMPATIBILITY.md) · [Changes](CHANGELOG.md)
