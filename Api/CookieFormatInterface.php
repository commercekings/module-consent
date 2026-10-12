<?php
declare(strict_types=1);

namespace CommerceKings\Consent\Api;

/**
 * Reads a cookie banner's (CMP's) cookie as written by one version of its library. A banner source
 * registers one reader per cookie layout it can read (its own di.xml); the newest matching one wins, so
 * cookies written by an older version are still read after a library upgrade. Implemented by banner source modules (e.g.
 * CommerceKings_ConsentVanilla CookieFormat\V3).
 */
interface CookieFormatInterface
{
    /**
     * The full library version this reader was written for, e.g. "3.1.0" - shown in admin; when several
     * readers match a cookie, the newest version wins.
     */
    public function getVersion(): string;

    /** Does this decoded cookie look like this format? */
    public function matches(array $cookie): bool;

    /** @return string[] accepted category names */
    public function acceptedCategories(array $cookie): array;

    /** When the visitor last saved their choice (UTC Y-m-d H:i:s), '' if the format has none. */
    public function consentTime(array $cookie): string;

    /** The library's own id for this visitor's choices (stays the same when they change), '' if none. */
    public function reference(array $cookie): string;

    /** The banner revision the choice was made for. */
    public function revision(array $cookie): string;

    /** When the library forgets the choice (UTC Y-m-d H:i:s), '' if the format doesn't say. */
    public function expiresAt(array $cookie): string;
}
