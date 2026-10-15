<?php
declare(strict_types=1);

namespace CommerceKings\Consent\Model\ResourceModel\Export;

use Magento\Framework\Model\ResourceModel\Db\AbstractDb;

/** Table metadata for Magento's read-only export collection. */
class Record extends AbstractDb
{
    protected function _construct()
    {
        $this->_init('ck_consent', 'consent_id');
    }
}
