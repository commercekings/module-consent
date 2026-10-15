<?php
declare(strict_types=1);

namespace CommerceKings\Consent\Api;

/**
 * Is a recorded consent this visitor's own? A consent belongs to the browser it was given in (the module's browser
 * id), so a feature may hand back something the consenting visitor owns - e.g. a saved cart - only to that browser.
 *
 * @api
 */
interface ConsentOwnershipInterface
{
    /** true when the consent exists and was given in the current browser; false otherwise, also without a browser id. */
    public function isCurrentVisitor(string $consentId): bool;
}
