<?php
declare(strict_types=1);

namespace CommerceKings\Consent\Api;

/** Signed email withdrawal links for recorded consent, independent of feature data. @api */
interface WithdrawalLinkInterface
{
    /**
     * Uses the current store on the consent's website. No email address or feature identifier is needed.
     * Links remain usable while the consent record is retained, including after expiry or withdrawal.
     *
     * @throws \Magento\Framework\Exception\LocalizedException when the record is missing or on another website
     */
    public function getUrl(string $consentId): string;
}
