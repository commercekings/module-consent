<?php
declare(strict_types=1);

namespace CommerceKings\Consent\Api\Data;

/**
 * The visitor's latest saved choice in a consent source, as the source reports it.
 *
 *  - reference: the source's stable id for this visitor's choices (e.g. a banner's consent id), required; at most
 *    REFERENCE_LENGTH (80) characters - with a longer one the choice is not recorded;
 *  - givenAt: when the choice was saved, UTC Y-m-d H:i:s, required - reference + givenAt identify one choice;
 *  - revision: the source revision the choice was made for (e.g. a banner revision);
 *  - expiresAt: when the source forgets the choice (UTC), '' = the module's default lifetime;
 *  - purposes: the purposes this choice agrees to.
 */
class SourceChoice
{
    public const REFERENCE_LENGTH = 80;

    /** @param string[] $purposes */
    public function __construct(
        public readonly string $reference,
        public readonly string $givenAt,
        public readonly string $revision,
        public readonly array $purposes,
        public readonly string $expiresAt = ''
    ) {
    }

    public function accepts(string $purpose): bool
    {
        return in_array($purpose, $this->purposes, true);
    }
}
