<?php
declare(strict_types=1);

namespace CommerceKings\Consent\Model;

use CommerceKings\Consent\Api\PurposeRegistryInterface;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Store\Model\ScopeInterface;

/**
 * Consent purposes, declared by the modules that use them (di.xml array argument "purposes") - see
 * Api\PurposeRegistryInterface for the format. A purpose whose "active_if" settings are all off is
 * treated as not declared: its banner category is hidden and it never has consent.
 */
class PurposePool implements PurposeRegistryInterface
{
    /**
     * @param array<string, array{label?: string, terms_revision?: string, cookies?: string[], record?: string, active_if?: string[], category?: string}> $purposes
     * @param ScopeConfigInterface|null $scopeConfig set in etc/di.xml; without it every purpose is active
     */
    public function __construct(
        private readonly array $purposes = [],
        private readonly ?ScopeConfigInterface $scopeConfig = null
    ) {
        // stored with every consent: Magento's database session would cut a longer value silently
        foreach ($purposes as $code => $purpose) {
            if (mb_strlen((string)$code) > self::CODE_LENGTH
                || mb_strlen((string)($purpose['terms_revision'] ?? '')) > self::TERMS_REVISION_LENGTH) {
                throw new \InvalidArgumentException(sprintf('Consent purpose "%s": the code may have at most %d'
                    . ' characters, terms_revision at most %d.', $code, self::CODE_LENGTH,
                    self::TERMS_REVISION_LENGTH));
            }
        }
    }

    /** Active purposes. */
    public function getCodes(): array
    {
        return array_values(array_filter($this->getAllCodes(), [$this, 'isActive']));
    }

    /** Every declared purpose, active or not (admin lists). */
    public function getAllCodes(): array
    {
        return array_map('strval', array_keys($this->purposes));
    }

    public function isDeclared(string $purpose, ?int $websiteId = null): bool
    {
        return isset($this->purposes[$purpose]) && $this->isActive($purpose, $websiteId);
    }

    /**
     * No "active_if" -> always active. Otherwise active while at least one of the listed settings
     * (config flags, store view scope) is on - e.g. analytics while Magento's GA or Consent Mode is.
     */
    public function isActive(string $purpose, ?int $websiteId = null): bool
    {
        $flags = array_filter(array_map('strval', (array)($this->purposes[$purpose]['active_if'] ?? [])));
        if (!$flags || $this->scopeConfig === null) {
            return true;
        }
        foreach ($flags as $path) {
            $on = $websiteId === null ? $this->scopeConfig->isSetFlag($path, ScopeInterface::SCOPE_STORE)
                : $this->scopeConfig->isSetFlag($path, ScopeInterface::SCOPE_WEBSITE, $websiteId);
            if ($on) {
                return true;
            }
        }
        return false;
    }

    public function getLabel(string $purpose): string
    {
        // declared in di.xml in English; shown to shoppers in the store's language
        return (string)__((string)($this->purposes[$purpose]['label'] ?? $purpose));
    }

    public function getCookiePatterns(string $purpose): array
    {
        return array_values(array_map('strval', (array)($this->purposes[$purpose]['cookies'] ?? [])));
    }

    public function getTermsRevision(string $purpose): string
    {
        return (string)($this->purposes[$purpose]['terms_revision'] ?? '1');
    }

    public function getDefaultCategory(string $purpose): string
    {
        return (string)($this->purposes[$purpose]['category'] ?? '');
    }

    /** Only an explicit "optional" - when in doubt, recording is required. */
    public function isRecordOptional(string $purpose): bool
    {
        return ($this->purposes[$purpose]['record'] ?? self::RECORD_REQUIRED) === self::RECORD_OPTIONAL;
    }
}
