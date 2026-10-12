<?php
declare(strict_types=1);

namespace CommerceKings\Consent\Model;

use Magento\Cms\Api\GetPageByIdentifierInterface;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Store\Model\ScopeInterface;
use Magento\Store\Model\StoreManagerInterface;

/**
 * What the visitor saw when they agreed (GDPR Art. 7(1) - the shop must be able to show it): the banner's
 * texts and categories, what each category grants, the purposes and their terms revisions, the privacy
 * page (its CMS content when it is a page of this shop), store and locale. Saved once per distinct wording
 * (ck_consent_snapshot); every consent stores the hash of the wording current when it was recorded.
 */
class WordingSnapshot
{
    private ?string $currentHash = null;

    public function __construct(
        private readonly Evidence $evidence,
        private readonly ConsentStore $store,
        private readonly StoreManagerInterface $stores,
        private readonly ScopeConfigInterface $scopeConfig,
        private readonly GetPageByIdentifierInterface $pages
    ) {
    }

    /** The current store view's wording. */
    public function content(): array
    {
        $policy = $this->privacyPage();
        $evidence = $this->evidence->snapshot($policy['content'] ?? null);
        $store = $this->stores->getStore();
        return [
            'format' => 1,
            'store' => [
                'id' => (int)$store->getId(),
                'code' => (string)$store->getCode(),
                'locale' => (string)$this->scopeConfig->getValue('general/locale/code', ScopeInterface::SCOPE_STORE),
            ],
            'source' => $evidence['source'],
            'source_version' => $evidence['source_version'],
            'banner_revision' => $evidence['banner_revision'],
            'categories' => $evidence['categories'],
            'purposes' => $evidence['purposes'],
            'banner' => $evidence['banner_config'],
            'privacy_url' => $evidence['privacy_url'],
            'privacy_page' => $policy,
        ];
    }

    /** sha256 of the content - the same wording always gives the same hash. */
    public function hash(array $content): string
    {
        return hash('sha256', $this->encode($content));
    }

    /** The current wording's hash; the wording is saved the first time it is seen. Once per request. */
    public function currentHash(): string
    {
        if ($this->currentHash === null) {
            $content = $this->content();
            $json = $this->encode($content);
            $this->currentHash = hash('sha256', $json);
            $store = $this->stores->getStore();
            $this->store->saveSnapshot(
                $this->currentHash,
                (int)$store->getWebsiteId(),
                (int)$store->getId(),
                (string)$content['banner_revision'],
                $json
            );
        }
        return $this->currentHash;
    }

    /** The privacy page when it is a CMS page of this store: title and content (not its save time - re-saving the same text is the same wording); null otherwise. */
    private function privacyPage(): ?array
    {
        $key = trim((string)$this->scopeConfig->getValue('commercekings_consent/general/privacy_url', ScopeInterface::SCOPE_STORE), '/ ');
        if ($key === '' || preg_match('#^https?://#', $key)) {
            return null;
        }
        try {
            $page = $this->pages->execute($key, (int)$this->stores->getStore()->getId());
        } catch (\Exception) {
            return null;
        }
        return [
            'identifier' => $key,
            'title' => (string)$page->getTitle(),
            'content' => (string)$page->getContent(),
        ];
    }

    private function encode(array $content): string
    {
        return (string)json_encode($content, JSON_UNESCAPED_SLASHES);
    }
}
