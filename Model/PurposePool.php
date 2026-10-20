<?php
declare(strict_types=1);

namespace CommerceKings\Consent\Model;

use CommerceKings\Consent\Api\PurposeRegistryInterface;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Store\Model\ScopeInterface;
use Magento\Store\Model\StoreManagerInterface;

/**
 * Consent purposes, declared by the modules that use them (di.xml array argument "purposes") - see
 * Api\PurposeRegistryInterface for the format. A purpose whose "active_if" settings are all off is
 * treated as not declared: its banner category is hidden and it never has consent.
 *
 * The target whose settings count: a store view, a website (on in any of its store views), or - without
 * either - the current store view, unless inContext() names a target: then everything that asks without one
 * (getCodes(), CategoryMapper building a source's categories) answers for that target.
 */
class PurposePool implements PurposeRegistryInterface
{
    /** @var list<array{0: int|null, 1: int|null}> inContext() targets, innermost last */
    private array $context = [];

    /**
     * @param array<string, array{label?: string, terms_revision?: string, cookies?: string[], record?: string, active_if?: string[], category?: string}> $purposes
     * @param ScopeConfigInterface|null $scopeConfig set in etc/di.xml; without it every purpose is active
     * @param StoreManagerInterface|null $storeManager set in etc/di.xml (a website's store views); without it a
     *     website's own scope decides
     */
    public function __construct(
        private readonly array $purposes = [],
        private readonly ?ScopeConfigInterface $scopeConfig = null,
        private readonly ?StoreManagerInterface $storeManager = null
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

    /**
     * Runs $callback with a target for everything that asks without one (getCodes(), isDeclared() without ids,
     * so CategoryMapper and a source's categories): consents of that website or store view are being checked.
     *
     * @template T
     * @param callable(): T $callback
     * @return T
     */
    public function inContext(?int $websiteId, ?int $storeId, callable $callback): mixed
    {
        $this->context[] = [$websiteId, $storeId];
        try {
            return $callback();
        } finally {
            array_pop($this->context);
        }
    }

    /** Active purposes (for the current target). */
    public function getCodes(): array
    {
        return array_values(array_filter($this->getAllCodes(), [$this, 'isActive']));
    }

    /** Every declared purpose, active or not (admin lists). */
    public function getAllCodes(): array
    {
        return array_map('strval', array_keys($this->purposes));
    }

    public function isDeclared(string $purpose, ?int $websiteId = null, ?int $storeId = null): bool
    {
        return isset($this->purposes[$purpose]) && $this->isActive($purpose, $websiteId, $storeId);
    }

    /**
     * No "active_if" -> always active. Otherwise active while at least one of the listed settings
     * (config flags) is on for the target - e.g. analytics while Magento's GA or Consent Mode is.
     */
    public function isActive(string $purpose, ?int $websiteId = null, ?int $storeId = null): bool
    {
        $flags = array_filter(array_map('strval', (array)($this->purposes[$purpose]['active_if'] ?? [])));
        if (!$flags || $this->scopeConfig === null) {
            return true;
        }
        if ($websiteId === null && $storeId === null && $this->context !== []) {
            [$websiteId, $storeId] = $this->context[array_key_last($this->context)];
        }
        foreach ($flags as $path) {
            if ($this->isOn($path, $websiteId, $storeId)) {
                return true;
            }
        }
        return false;
    }

    /** A store view's setting; a website's: on in any of its store views (else its own scope); else the current store view. */
    private function isOn(string $path, ?int $websiteId, ?int $storeId): bool
    {
        if ($storeId !== null) {
            return $this->scopeConfig->isSetFlag($path, ScopeInterface::SCOPE_STORE, $storeId);
        }
        if ($websiteId === null) {
            return $this->scopeConfig->isSetFlag($path, ScopeInterface::SCOPE_STORE);
        }
        $storeIds = [];
        foreach ($this->storeManager?->getStores() ?? [] as $store) {
            if ((int)$store->getWebsiteId() === $websiteId) {
                $storeIds[] = (int)$store->getId();
            }
        }
        if ($storeIds === []) {
            return $this->scopeConfig->isSetFlag($path, ScopeInterface::SCOPE_WEBSITE, $websiteId);
        }
        foreach ($storeIds as $id) {
            if ($this->scopeConfig->isSetFlag($path, ScopeInterface::SCOPE_STORE, $id)) {
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
