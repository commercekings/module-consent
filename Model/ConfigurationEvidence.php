<?php
declare(strict_types=1);

namespace CommerceKings\Consent\Model;

use Magento\Framework\App\Area;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\App\State;
use Magento\Store\Model\App\Emulation;
use Magento\Store\Model\ScopeInterface;
use Magento\Store\Model\StoreManagerInterface;

/** Build current configuration in its storefront scope, including the configured CMS policy. */
class ConfigurationEvidence
{
    public function __construct(
        private readonly Evidence $evidence,
        private readonly Emulation $emulation,
        private readonly StoreManagerInterface $stores,
        private readonly State $state,
        private readonly ScopeConfigInterface $scopeConfig,
        private readonly WordingSnapshot $wording
    ) {
    }

    public function get(int|string $storeId, ?string $policyContent = null): array
    {
        $store = $this->stores->getStore($storeId);
        if (!(int)$store->getId()) {
            throw new \InvalidArgumentException('Specify a storefront store view, not the admin store.');
        }
        return $this->state->emulateAreaCode(Area::AREA_FRONTEND, function () use ($store, $policyContent): array {
            $this->emulation->startEnvironmentEmulation((int)$store->getId(), Area::AREA_FRONTEND, true);
            try {
                $policyContent ??= $this->wording->content()['privacy_page']['content'] ?? null;
                $snapshot = $this->evidence->snapshot($policyContent);
                $snapshot['store'] = [
                    'id' => (int)$store->getId(),
                    'code' => $store->getCode(),
                    'website_id' => (int)$store->getWebsiteId(),
                    'locale' => $this->scopeConfig->getValue('general/locale/code', ScopeInterface::SCOPE_STORE, $store->getId()),
                ];
                return $snapshot;
            } finally {
                $this->emulation->stopEnvironmentEmulation();
            }
        });
    }
}
