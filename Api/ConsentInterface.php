<?php
declare(strict_types=1);

namespace CommerceKings\Consent\Api;

use CommerceKings\Consent\Api\Data\Consent;

/**
 * Recorded consent actions per purpose (e.g. "cart_reminder"), for one browser on one website.
 *
 * A consent is recorded once per choice the visitor made in the source (the source's own identity of
 * that choice), gets a server-issued consent id and is never re-created after a withdrawal - only a new
 * choice in the source gives a new consent. Features store the consent id with their data and check it
 * with isValid() later (queue consumers, cron - no visitor cookies there).
 */
interface ConsentInterface
{
    /**
     * The current visitor's valid consent for the purpose on this website, or null. Records the choice
     * the first time it is seen (and may set the browser identity cookie) - call it from POST requests.
     */
    public function getCurrent(string $purpose): ?Consent;

    /** A recorded consent (valid or not) - the evidence. */
    public function get(string $consentId): ?Consent;

    /** Not withdrawn or expired; current source and revisions; purpose still declared and mapped by that source. */
    public function isValid(string $consentId): bool;

    /**
     * Withdraws this consent and every other active consent of the same browser for the same purpose on
     * the same website; the same choice can never be recorded again. Persisted first, then the event
     * commercekings_consent_withdrawn fires. Returns the consent ids withdrawn now.
     *
     * @param string $via who withdrew: "banner", "email", "admin", ...
     * @return string[]
     */
    public function withdraw(string $consentId, string $via): array;
}
