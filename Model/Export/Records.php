<?php
declare(strict_types=1);

namespace CommerceKings\Consent\Model\Export;

use CommerceKings\Consent\Model\RecordedEvidence;
use CommerceKings\Consent\Model\ResourceModel\Export\CollectionFactory;
use Magento\Eav\Model\Entity\AttributeFactory;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\Stdlib\DateTime\TimezoneInterface;
use Magento\ImportExport\Model\Export\Factory;
use Magento\ImportExport\Model\ResourceModel\CollectionByPagesIteratorFactory;
use Magento\Store\Model\StoreManagerInterface;

class Records extends AbstractConsent
{
    protected const COLUMNS = [
        'consent_id' => ['Consent ID', 'varchar'],
        'purpose' => ['Purpose', 'varchar'],
        'browser_hash' => ['Browser hash', 'varchar'],
        'website_id' => ['Website ID', 'int'],
        'source' => ['Source', 'varchar'],
        'reference' => ['Choice reference', 'varchar'],
        'source_time' => ['Choice time (UTC)', 'datetime'],
        'banner_revision' => ['Source revision', 'varchar'],
        'terms_revision' => ['Terms revision', 'varchar'],
        'granted_at' => ['Recorded at (UTC)', 'datetime'],
        'expires_at' => ['Expires at (UTC)', 'datetime'],
        'withdrawn_at' => ['Withdrawn at (UTC)', 'datetime'],
        'withdrawn_via' => ['Withdrawal method', 'varchar'],
    ];
    protected const EVIDENCE_COLUMNS = [
        'snapshot_hash', 'wording_first_seen', 'wording_hash_matches', 'wording_json', 'evidence_json',
    ];
    protected $_permanentAttributes = ['consent_id'];

    public function __construct(
        ScopeConfigInterface $scopeConfig,
        StoreManagerInterface $storeManager,
        Factory $collectionFactory,
        CollectionByPagesIteratorFactory $resourceColFactory,
        AttributeFactory $attributeFactory,
        TimezoneInterface $timezone,
        private readonly CollectionFactory $records,
        private readonly RecordedEvidence $evidence,
        array $data = []
    ) {
        parent::__construct($scopeConfig, $storeManager, $collectionFactory, $resourceColFactory,
            $attributeFactory, $timezone, $data);
    }

    public function getEntityTypeCode(): string
    {
        return 'consent_records';
    }

    protected function _getEntityCollection(): \Magento\Framework\Data\Collection\AbstractDb
    {
        return $this->records->create()->setOrder('consent_id', 'ASC');
    }

    public function exportItem($item): void
    {
        $row = $item->getData();
        $snapshot = $row['wording_hash'] === null ? null : [
            'snapshot_hash' => $row['wording_hash'],
            'created_at' => $row['wording_created_at'],
            'content' => $row['wording_content'],
        ];
        unset($row['wording_hash'], $row['wording_created_at'], $row['wording_content'], $row['id_field_name']);
        $proof = $this->evidence->format($row, $snapshot);
        $this->writeRow($row + [
            'wording_first_seen' => $proof['wording']['first_seen'] ?? '',
            'wording_hash_matches' => $snapshot === null ? '' : (int)$proof['wording']['hash_matches'],
            'wording_json' => $snapshot['content'] ?? '',
            'evidence_json' => $this->json($proof),
        ]);
    }
}
