<?php
declare(strict_types=1);

namespace CommerceKings\Consent\Model\ResourceModel\Export;

use Magento\Framework\Data\Collection\AbstractDb;
use Magento\Framework\Data\Collection\Db\FetchStrategyInterface;
use Magento\Framework\Data\Collection\EntityFactoryInterface;
use Psr\Log\LoggerInterface;

/** Native paginated collection; the left join retains grants without wording snapshots. */
class Collection extends AbstractDb
{
    public function __construct(
        EntityFactoryInterface $entityFactory,
        LoggerInterface $logger,
        FetchStrategyInterface $fetchStrategy,
        private readonly Record $resource
    ) {
        parent::__construct($entityFactory, $logger, $fetchStrategy, $resource->getConnection());
        $this->_idFieldName = 'consent_id';
        $this->getSelect()->from(['main_table' => $resource->getMainTable()])
            ->joinLeft(['wording' => $resource->getTable('ck_consent_snapshot')],
                'main_table.snapshot_hash = wording.snapshot_hash',
                ['wording_hash' => 'snapshot_hash', 'wording_created_at' => 'created_at', 'wording_content' => 'content']);
        foreach (['consent_id', 'website_id', 'source', 'banner_revision', 'snapshot_hash'] as $field) {
            $this->addFilterToMap($field, 'main_table.' . $field);
        }
    }

    public function getResource()
    {
        return $this->resource;
    }
}
