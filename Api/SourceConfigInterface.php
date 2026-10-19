<?php
declare(strict_types=1);

namespace CommerceKings\Consent\Api;

/**
 * The shop's consent settings per website (admin: Consent) - decisions a package upgrade must never change:
 * which source, the cookie the visitor's choice is kept in, the revision visitors answered for, how long
 * their choice is kept.
 */
interface SourceConfigInterface
{
    public const REVISION_LENGTH = 32;

    /** Code of the source chosen for the website (null = current website). */
    public function getSourceCode(?int $websiteId = null): string;

    /** The cookie the source keeps the visitor's choice in; changing it asks every visitor again. */
    public function getCookieName(?int $websiteId = null): string;

    /**
     * The revision a choice must have been made for; raising it asks every visitor again. At most REVISION_LENGTH
     * (32) characters (it is stored with each consent): with a longer one no consent is recorded.
     */
    public function getRevision(?int $websiteId = null): string;

    /** Maximum consent lifetime from the saved choice time; a source may specify an earlier expiry. */
    public function getCookieLifetimeDays(?int $websiteId = null): int;
}
