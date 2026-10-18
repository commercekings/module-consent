<?php
declare(strict_types=1);

namespace CommerceKings\Consent\Model;

use CommerceKings\Consent\Api\BrowserIdentityInterface;
use CommerceKings\Consent\Api\PurposeRegistryInterface;
use Magento\Store\Model\StoreManagerInterface;

/**
 * The purposes this browser's current banner choice still accepts although the consent was withdrawn elsewhere (an
 * email link opened on another device). Without a banner choice it does not touch the database, otherwise one
 * lookup on the unique choice index.
 */
class WithdrawnPurposes
{
    public function __construct(
        private readonly BrowserIdentityInterface $browser,
        private readonly ConfiguredSource $sources,
        private readonly PurposeRegistryInterface $purposes,
        private readonly ConsentStore $store,
        private readonly StoreManagerInterface $storeManager
    ) {
    }

    /** @return string[] */
    public function forCurrentChoice(): array
    {
        $hash = $this->browser->hash();
        if ($hash === '') {
            return [];
        }
        $websiteId = (int)$this->storeManager->getWebsite()->getId();
        $source = $this->sources->get($websiteId);
        $choice = $source?->getChoice();
        $accepted = $choice ? array_intersect($choice->purposes, $this->purposes->getCodes()) : [];
        if (!$source || !$choice || !$accepted) {
            return [];
        }
        return $this->store->findWithdrawnPurposes(
            $accepted,
            $hash,
            $websiteId,
            $source->getCode(),
            $choice->reference,
            $choice->givenAt
        );
    }
}
