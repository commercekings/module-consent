<?php
declare(strict_types=1);

namespace CommerceKings\Consent\Model\Export;

use Magento\Eav\Model\Entity\AttributeFactory;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\Data\Collection\AbstractDb;
use Magento\Framework\Exception\InputException;
use Magento\Framework\Stdlib\DateTime\TimezoneInterface;
use Magento\ImportExport\Model\Export;
use Magento\ImportExport\Model\Export\AbstractEntity;
use Magento\ImportExport\Model\Export\Factory;
use Magento\ImportExport\Model\ResourceModel\CollectionByPagesIteratorFactory;
use Magento\Store\Model\StoreManagerInterface;

/** Non-EAV entities using the same attribute metadata and paging as Magento's Stock Sources export. */
abstract class AbstractConsent extends AbstractEntity
{
    protected const COLUMNS = [];
    protected const EVIDENCE_COLUMNS = [];

    public function __construct(
        ScopeConfigInterface $scopeConfig,
        StoreManagerInterface $storeManager,
        Factory $collectionFactory,
        CollectionByPagesIteratorFactory $resourceColFactory,
        AttributeFactory $attributeFactory,
        private readonly TimezoneInterface $timezone,
        array $data = []
    ) {
        parent::__construct($scopeConfig, $storeManager, $collectionFactory, $resourceColFactory,
            $data + ['page_size' => 200]);
        foreach (static::COLUMNS as $code => [$label, $type]) {
            $attribute = $attributeFactory->create();
            $attribute->setId($code);
            $attribute->setAttributeCode($code);
            $attribute->setDefaultFrontendLabel((string)__($label));
            $attribute->setBackendType($type);
            $this->_attributeCollection->addItem($attribute);
        }
    }

    public function export(): string
    {
        $this->getWriter()->setHeaderCols($this->_getHeaderColumns());
        $collection = $this->_getEntityCollection();
        foreach (static::COLUMNS as $code => [$label, $type]) {
            $value = $this->_parameters[Export::FILTER_ELEMENT_GROUP][$code] ?? null;
            if ($value !== null && $value !== '' && $value !== []) {
                $this->applyFilter($collection, $code, $type, $value);
            }
        }
        $this->_exportCollectionByPages($collection);
        return $this->getWriter()->getContents();
    }

    protected function _getHeaderColumns(): array
    {
        return array_merge($this->_getExportAttributeCodes(), static::EVIDENCE_COLUMNS);
    }

    /** Escape spreadsheet formulas in display cells; JSON evidence preserves the original values. */
    protected function writeRow(array $row): void
    {
        $output = [];
        foreach ($this->_getHeaderColumns() as $code) {
            $value = $row[$code] ?? '';
            if (is_string($value) && preg_match('/^[\s]*[=+@\-\t\r]/', $value)) {
                $value = "'" . $value;
            }
            $output[$code] = $value;
        }
        $this->getWriter()->writeRow($output);
    }

    protected function json(array $evidence): string
    {
        return json_encode($evidence, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    }

    /** Only declared columns and native bound collection conditions can reach SQL. Dates use UTC calendar days. */
    private function applyFilter(AbstractDb $collection, string $code, string $type, mixed $value): void
    {
        if ($type === 'varchar') {
            if (!is_string($value)) {
                throw new InputException(__('Invalid export filter for %1.', $code));
            }
            $collection->addFieldToFilter($code, ['like' => '%' . $value . '%']);
            return;
        }
        if (!is_array($value)) {
            throw new InputException(__('Invalid export range for %1.', $code));
        }
        $range = [];
        foreach (['from' => 0, 'to' => 1] as $bound => $index) {
            $limit = $value[$index] ?? '';
            if ($limit === '') {
                continue;
            }
            if (!is_scalar($limit) || is_bool($limit) || ($type === 'int' && !is_numeric($limit))) {
                throw new InputException(__('Invalid export range for %1.', $code));
            }
            try {
                $range[$bound] = $type === 'datetime'
                    ? $this->timezone->date($limit, null, false, false)->format('Y-m-d')
                        . ($bound === 'from' ? ' 00:00:00' : ' 23:59:59')
                    : $limit;
            } catch (\Exception $exception) {
                throw new InputException(__('Invalid export date for %1.', $code), $exception);
            }
        }
        if ($range !== []) {
            $collection->addFieldToFilter($code, $range);
        }
    }
}
