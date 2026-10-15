<?php
declare(strict_types=1);

namespace CommerceKings\Consent\Block\Adminhtml;

use CommerceKings\Consent\Model\Config;
use CommerceKings\Consent\Model\ConsentStore;
use CommerceKings\Consent\Model\WordingSnapshot;
use Magento\Backend\Block\Template\Context;
use Magento\Config\Block\System\Config\Form\Field;
use Magento\Framework\App\Area;
use Magento\Framework\Data\Form\Element\AbstractElement;
use Magento\Store\Model\App\Emulation;
use Magento\Store\Api\Data\StoreInterface;

/**
 * Per store view: has the wording (source texts, categories, purposes, privacy page) changed since the
 * visitors of the current source revision first answered it? A changed wording does not ask anyone
 * again - only raising the source revision does; this tells the admin when that decision is due.
 */
class WordingCheck extends Field
{
    private const CELL = ' style="padding:1rem 1.2rem;vertical-align:top"';

    public function __construct(
        Context $context,
        private readonly WordingSnapshot $wording,
        private readonly ConsentStore $consents,
        private readonly Config $config,
        private readonly Emulation $emulation,
        array $data = []
    ) {
        parent::__construct($context, $data);
    }

    protected function _getElementHtml(AbstractElement $element)
    {
        $rows = '';
        $i = 0;
        foreach ($this->storesInScope() as $store) {
            $website = $this->_storeManager->getWebsite((int)$store->getWebsiteId());
            $rows .= '<tr class="data-row' . ($i++ % 2 ? ' _odd-row' : '') . '"><td' . self::CELL . '>'
                . $this->escapeHtml($website->getName() . ' / ' . $store->getName())
                . '</td><td' . self::CELL . '>' . $this->status((int)$store->getId(), (int)$store->getWebsiteId()) . '</td></tr>';
        }
        return '<div style="font-size:1.4rem"><div class="admin__data-grid-wrap admin__data-grid-wrap-static"><table class="data-grid">'
            . '<thead><tr><th class="data-grid-th"' . self::CELL . '>' . $this->escapeHtml(__('Store view')) . '</th>'
            . '<th class="data-grid-th"' . self::CELL . '>' . $this->escapeHtml(__('Wording')) . '</th></tr></thead>'
            . '<tbody>' . $rows . '</tbody></table></div></div>';
    }

    protected function _isInheritCheckboxRequired(AbstractElement $element)
    {
        return false;
    }

    private function status(int $storeId, int $websiteId): string
    {
        $revision = $this->config->getRevision($websiteId);
        $first = $this->consents->firstSnapshot($storeId, $revision);
        if (!$first) {
            return $this->escapeHtml(__('No records for revision %1.', $revision));
        }
        $this->emulation->startEnvironmentEmulation($storeId, Area::AREA_FRONTEND, true);
        try {
            $current = $this->wording->hash($this->wording->content());
        } finally {
            $this->emulation->stopEnvironmentEmulation();
        }
        if ($current === $first['snapshot_hash']) {
            return $this->escapeHtml(__('Unchanged since revision %1 (%2).', $revision, $first['created_at']));
        }
        return '<strong>' . $this->escapeHtml(__('Changed since revision %1 (%2).', $revision, $first['created_at']))
            . '</strong><br/>' . $this->escapeHtml(__(
                'Increase the source revision if visitors need to accept these changes.'
            ));
    }

    /** @return StoreInterface[] */
    private function storesInScope(): array
    {
        $websiteId = (int)$this->getRequest()->getParam('website');
        $stores = [];
        foreach ($this->_storeManager->getStores() as $store) {
            if ($store->getIsActive() && (!$websiteId || (int)$store->getWebsiteId() === $websiteId)) {
                $stores[] = $store;
            }
        }
        return $stores;
    }
}
