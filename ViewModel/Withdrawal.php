<?php
declare(strict_types=1);

namespace CommerceKings\Consent\ViewModel;

use CommerceKings\Consent\Api\BrowserIdentityInterface;
use CommerceKings\Consent\Api\PurposeRegistryInterface;
use CommerceKings\Consent\Model\ConfiguredSource;
use CommerceKings\Consent\Model\ConsentStore;
use CommerceKings\Consent\Model\WithdrawalLink;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\View\Element\Block\ArgumentInterface;

/** Purpose-specific confirmation without feature data or changes to another browser's preferences. */
class Withdrawal implements ArgumentInterface
{
    public function __construct(
        private readonly RequestInterface $request,
        private readonly WithdrawalLink $links,
        private readonly PurposeRegistryInterface $purposes,
        private readonly ConsentStore $store,
        private readonly ConfiguredSource $sources,
        private readonly BrowserIdentityInterface $browser
    ) {
    }

    public function getPurposeLabel(): string
    {
        $consent = $this->links->getConsent(
            (string)$this->request->getParam('c'),
            (string)$this->request->getParam('t')
        );
        return $consent ? $this->purposes->getLabel($consent->purpose) : '';
    }

    public function getBannerConfigJson(): string
    {
        $id = (string)$this->request->getParam('c');
        $consent = $this->links->getConsent($id, (string)$this->request->getParam('t'));
        $row = $consent ? $this->store->load($id) : null;
        $hash = $this->browser->hash();
        $config = [];
        if ($consent && $row && $hash !== '' && hash_equals((string)$row['browser_hash'], $hash)) {
            $source = $this->sources->get($consent->websiteId);
            $choice = $source?->getChoice();
            // Only turn off a choice that was withdrawn. A later explicit choice remains untouched.
            $current = $source && $choice ? $this->store->find([
                'purpose' => $consent->purpose, 'browser_hash' => $hash, 'website_id' => $consent->websiteId,
                'source' => $source->getCode(), 'reference' => $choice->reference, 'source_time' => $choice->givenAt,
            ]) : null;
            if ($current && $current['withdrawn_at'] !== null) {
                $config = ['*' => ['CommerceKings_Consent/js/withdrawal-banner' => ['purpose' => $consent->purpose]]];
            }
        }
        return (string)json_encode((object)$config, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
    }
}
