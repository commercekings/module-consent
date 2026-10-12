<?php
declare(strict_types=1);

namespace CommerceKings\Consent\Api\Data;

/**
 * One recorded consent action. Times are UTC Y-m-d H:i:s. reference + sourceTime = the source's own
 * identity of the visitor's choice (e.g. a banner's consent id and when it was saved).
 */
final class Consent
{
    public function __construct(
        public readonly string $consentId,
        public readonly string $purpose,
        public readonly int $websiteId,
        public readonly string $source,
        public readonly string $reference,
        public readonly string $sourceTime,
        public readonly string $bannerRevision,
        public readonly string $termsRevision,
        public readonly string $grantedAt,
        public readonly string $expiresAt,
        public readonly ?string $withdrawnAt = null,
        public readonly ?string $withdrawnVia = null,
        /** The wording the visitor saw (ck_consent_snapshot), '' for consents recorded before snapshots. */
        public readonly string $snapshotHash = ''
    ) {
    }
}
