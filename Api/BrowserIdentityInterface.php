<?php
declare(strict_types=1);

namespace CommerceKings\Consent\Api;

/** The module's own id for a browser (independent of the source) - "is this the browser that consented?" */
interface BrowserIdentityInterface
{
    /** sha256 of this browser's id, '' if it has none yet. */
    public function hash(): string;

    /** sha256 of this browser's id - created now if missing (call when a consent is recorded). */
    public function ensure(): string;
}
