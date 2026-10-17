<?php
declare(strict_types=1);

namespace CommerceKings\Consent\Model;

use CommerceKings\Consent\Api\BannerSettingsInterface;
use CommerceKings\Consent\Api\SourceConfigInterface;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\UrlInterface;
use Magento\Store\Model\ScopeInterface;

/** Admin: Stores > Configuration > CommerceKings > Consent. */
class Config implements SourceConfigInterface, BannerSettingsInterface
{
    /** 24 months - the longest an EU authority accepts for cookie consent (Spain's AEPD); the admin field accepts 1 to this. */
    public const MAX_LIFETIME_DAYS = 730;

    private const PREFIX = 'commercekings_consent/general/';

    public function __construct(
        private readonly ScopeConfigInterface $scopeConfig,
        private readonly UrlInterface $urlBuilder,
        private readonly PurposePool $purposes
    ) {
    }

    /**
     * Is each consent for this purpose recorded? Always when the purpose requires it; an optional one
     * only when ticked in "Record consent for" (default: not recorded).
     */
    public function isRecorded(string $purpose, ?int $websiteId = null): bool
    {
        if (!$this->purposes->isRecordOptional($purpose)) {
            return true;
        }
        $ticked = array_filter(array_map('trim', explode(',', (string)$this->value('record_purposes', $websiteId))));
        return in_array($purpose, $ticked, true);
    }

    /** @return string[] declared purposes that are recorded */
    public function getRecordedPurposes(?int $websiteId = null): array
    {
        return array_values(array_filter(
            $this->purposes->getCodes(),
            fn (string $purpose) => $this->isRecorded($purpose, $websiteId)
        ));
    }

    public function getPrivacyUrl(): string
    {
        $url = trim((string)$this->scopeConfig->getValue(self::PREFIX . 'privacy_url', ScopeInterface::SCOPE_STORE));
        if ($url !== '' && !preg_match('#^https?://#', $url)) {
            $url = $this->urlBuilder->getDirectUrl(ltrim($url, '/'));
        }
        return $url;
    }

    public function getSourceCode(?int $websiteId = null): string
    {
        return trim((string)$this->value('source', $websiteId));
    }

    public function getCookieName(?int $websiteId = null): string
    {
        return (string)preg_replace('/[^a-z0-9_-]/i', '', (string)$this->value('cookie_name', $websiteId)) ?: 'cc_cookie';
    }

    public function getRevision(?int $websiteId = null): string
    {
        return (string)max(1, (int)$this->value('revision', $websiteId));
    }

    /** 1 to MAX_LIFETIME_DAYS: a longer value (e.g. a typo) is capped, not stored as an out-of-range expiry. */
    public function getCookieLifetimeDays(?int $websiteId = null): int
    {
        return min(self::MAX_LIFETIME_DAYS, max(1, (int)$this->value('cookie_lifetime_days', $websiteId)));
    }

    /** Consent records are deleted this many days after expiry / withdrawal; marks have a lifetime minimum. */
    public function getKeepDays(): int
    {
        return max(1, (int)$this->scopeConfig->getValue(self::PREFIX . 'keep_days'));
    }

    private function value(string $field, ?int $websiteId): mixed
    {
        return $websiteId === null
            ? $this->scopeConfig->getValue(self::PREFIX . $field, ScopeInterface::SCOPE_STORE)
            : $this->scopeConfig->getValue(self::PREFIX . $field, ScopeInterface::SCOPE_WEBSITE, $websiteId);
    }
}
