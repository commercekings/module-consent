<?php
declare(strict_types=1);

namespace CommerceKings\Consent\Model\Export;

use CommerceKings\Consent\Model\ConfigurationEvidence;
use Magento\Eav\Model\Entity\AttributeFactory;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\Stdlib\DateTime\TimezoneInterface;
use Magento\ImportExport\Model\Export\Factory;
use Magento\ImportExport\Model\ResourceModel\CollectionByPagesIteratorFactory;
use Magento\Store\Model\ResourceModel\Store\CollectionFactory;
use Magento\Store\Model\StoreManagerInterface;

class Configuration extends AbstractConsent
{
    protected const COLUMNS = [
        'store_id' => ['Store view ID', 'int'],
        'store_code' => ['Store view code', 'varchar'],
        'website_id' => ['Website ID', 'int'],
    ];
    protected const EVIDENCE_COLUMNS = [
        'locale', 'source', 'source_version', 'banner_revision', 'privacy_url', 'exported_at', 'configuration_json',
    ];
    protected $_permanentAttributes = ['store_id', 'store_code', 'website_id'];

    public function __construct(
        ScopeConfigInterface $scopeConfig,
        StoreManagerInterface $storeManager,
        Factory $collectionFactory,
        CollectionByPagesIteratorFactory $resourceColFactory,
        AttributeFactory $attributeFactory,
        TimezoneInterface $timezone,
        private readonly CollectionFactory $stores,
        private readonly ConfigurationEvidence $evidence,
        array $data = []
    ) {
        parent::__construct($scopeConfig, $storeManager, $collectionFactory, $resourceColFactory,
            $attributeFactory, $timezone, $data);
    }

    public function getEntityTypeCode(): string
    {
        return 'consent_configuration';
    }

    protected function _getEntityCollection(): \Magento\Framework\Data\Collection\AbstractDb
    {
        $collection = $this->stores->create();
        $collection->addFilterToMap('store_code', 'main_table.code');
        return $collection->addFieldToFilter('store_id', ['gt' => 0])->setOrder('store_id', 'ASC');
    }

    public function exportItem($item): void
    {
        $snapshot = $this->evidence->get((int)$item->getId());
        $this->writeRow([
            'store_id' => $snapshot['store']['id'],
            'store_code' => $snapshot['store']['code'],
            'website_id' => $snapshot['store']['website_id'],
            'locale' => $snapshot['store']['locale'],
            'source' => $snapshot['source'],
            'source_version' => $snapshot['source_version'],
            'banner_revision' => $snapshot['banner_revision'],
            'privacy_url' => $snapshot['privacy_url'],
            'exported_at' => $snapshot['exported_at'],
            'configuration_json' => $this->json($snapshot),
        ]);
    }
}
