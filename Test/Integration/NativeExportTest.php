<?php
declare(strict_types=1);

namespace CommerceKings\Consent\Test\Integration;

use CommerceKings\Consent\Model\ConfigurationEvidence;
use CommerceKings\Consent\Model\Export\Records;
use Magento\Framework\App\Bootstrap;
use Magento\Framework\App\Filesystem\DirectoryList;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Adapter\AdapterInterface;
use Magento\Framework\Filesystem;
use Magento\Framework\Notification\NotifierInterface;
use Magento\Framework\ObjectManagerInterface;
use Magento\ImportExport\Api\ExportManagementInterface;
use Magento\ImportExport\Model\Export;
use Magento\ImportExport\Model\Export\Consumer;
use Magento\ImportExport\Model\Export\Entity\ExportInfoFactory;
use Magento\Store\Model\StoreManagerInterface;
use PHPUnit\Framework\TestCase;

/** Native filter metadata, SQL collections, CSV writer and export consumer; connection-local fixture tables. */
class NativeExportTest extends TestCase
{
    private ObjectManagerInterface $om;
    private AdapterInterface $db;
    private string $recordsTable;
    private string $wordingTable;
    private array $files = [];
    private const TEXT = "{\"text\":\"Earlier wording, café\\nLine two\"}";

    protected function setUp(): void
    {
        $root = getenv('MAGENTO_ROOT') ?: (defined('BP') ? BP : getcwd());
        require_once $root . '/app/bootstrap.php';
        $this->om = Bootstrap::create(BP, $_SERVER)->getObjectManager();
        $this->om->get(\Magento\Framework\App\State::class)->setAreaCode('crontab');
        $this->om->get(StoreManagerInterface::class)->setCurrentStore('default');
        $resource = $this->om->get(ResourceConnection::class);
        $this->db = $resource->getConnection();
        $this->recordsTable = $resource->getTableName('ck_consent');
        $this->wordingTable = $resource->getTableName('ck_consent_snapshot');
        // Same names shadow the real tables on this connection only. DROP TEMPORARY never touches shop data.
        foreach ([$this->recordsTable, $this->wordingTable] as $table) {
            $quoted = $this->db->quoteIdentifier($table);
            $template = $this->db->quoteIdentifier($table . '_export_template');
            $this->db->query('CREATE TEMPORARY TABLE ' . $template . ' LIKE ' . $quoted);
            $this->db->query('CREATE TEMPORARY TABLE ' . $quoted . ' LIKE ' . $template);
            $this->db->query('DROP TEMPORARY TABLE ' . $template);
        }
        $this->db->insert($this->wordingTable, [
            'snapshot_hash' => hash('sha256', self::TEXT), 'website_id' => 1, 'store_id' => 1,
            'banner_revision' => 'earlier', 'content' => self::TEXT, 'created_at' => '2026-01-01 00:00:00',
        ]);
        foreach ([1, 2, 3] as $id) {
            $this->db->insert($this->recordsTable, [
                'consent_id' => str_pad((string)$id, 32, '0', STR_PAD_LEFT),
                'purpose' => $id === 3 ? 'analytics' : 'cart_reminder',
                'browser_hash' => str_repeat((string)$id, 64), 'website_id' => $id === 3 ? 2 : 1,
                'source' => 'old-source', 'reference' => $id === 2 ? '=1+1' : 'fixture-' . $id,
                'source_time' => '2026-01-01 00:00:00', 'banner_revision' => 'earlier', 'terms_revision' => '1',
                'granted_at' => '2026-01-0' . $id . ' 12:00:00', 'expires_at' => '2026-06-01 00:00:00',
                'withdrawn_at' => $id === 2 ? '2026-02-01 00:00:00' : null,
                'snapshot_hash' => $id === 3 ? null : hash('sha256', self::TEXT),
            ]);
        }
    }

    protected function tearDown(): void
    {
        if (isset($this->db)) {
            $this->db->query('DROP TEMPORARY TABLE IF EXISTS ' . $this->db->quoteIdentifier($this->recordsTable));
            $this->db->query('DROP TEMPORARY TABLE IF EXISTS ' . $this->db->quoteIdentifier($this->wordingTable));
        }
        if (isset($this->om)) {
            $directory = $this->om->get(Filesystem::class)->getDirectoryWrite(DirectoryList::VAR_IMPORT_EXPORT);
            foreach ($this->files as $file) {
                if ($directory->isExist($file)) { $directory->delete($file); }
            }
        }
    }

    public function testBothEntitiesProvideNativeFilterMetadata(): void
    {
        foreach (['consent_records' => 13, 'consent_configuration' => 3] as $entity => $count) {
            $export = $this->om->create(Export::class)->setEntity($entity);
            $attributes = $export->getEntityAttributeCollection();
            $this->assertCount($count, $attributes);
            foreach ($attributes as $attribute) {
                $this->assertContains(Export::getAttributeFilterType($attribute), ['input', 'number', 'date']);
            }
        }
    }

    public function testPagedExportRetainsExpiredWithdrawnAndLegacyRecordsWithOriginalWording(): void
    {
        $adapter = $this->om->create(Records::class, ['data' => ['page_size' => 1]]);
        $writer = $this->om->create(Export\Adapter\Csv::class, ['destination' => $this->path()]);
        $rows = $this->parse($adapter->setParameters([])->setWriter($writer)->export());
        $this->assertCount(3, $rows);
        $this->assertSame(self::TEXT, $rows[0]['wording_json']);
        $this->assertSame(hash('sha256', $rows[0]['wording_json']), $rows[0]['snapshot_hash']);
        $this->assertSame('1', $rows[0]['wording_hash_matches']);
        $this->assertSame('2026-02-01 00:00:00', $rows[1]['withdrawn_at']);
        $this->assertSame("'=1+1", $rows[1]['reference']);
        $this->assertSame('=1+1', json_decode($rows[1]['evidence_json'], true)['consent']['reference']);
        $this->assertSame('', $rows[2]['wording_json']);
        $this->assertNull(json_decode($rows[2]['evidence_json'], true)['wording']);
    }

    public function testNativeFiltersCombineWebsitePurposeAndInclusiveUtcDates(): void
    {
        $rows = $this->export('consent_records', [
            'website_id' => ['1', '1'], 'purpose' => 'cart_reminder', 'granted_at' => ['1/2/2026', '1/2/2026'],
        ]);
        $this->assertCount(1, $rows);
        $this->assertSame('2026-01-02 12:00:00', $rows[0]['granted_at']);
    }

    public function testExclusionsDoNotRemoveIdentityOrStructuredEvidence(): void
    {
        $rows = $this->export('consent_records', ['purpose' => 'cart_reminder'], ['purpose', 'reference', 'consent_id']);
        $this->assertCount(2, $rows);
        $this->assertArrayNotHasKey('purpose', $rows[0]);
        $this->assertArrayNotHasKey('reference', $rows[0]);
        $this->assertArrayHasKey('consent_id', $rows[0]);
        $this->assertSame('cart_reminder', json_decode($rows[0]['evidence_json'], true)['consent']['purpose']);
    }

    public function testQuotedFilterCannotExpandTheSelection(): void
    {
        $this->assertSame([], $this->export('consent_records', ['reference' => "' OR 1=1 --"]));
    }

    public function testMalformedRangeIsRejectedBeforeQuerying(): void
    {
        $this->expectException(\Magento\Framework\Exception\InputException::class);
        $this->export('consent_records', ['website_id' => [['eq' => 1], '']]);
    }

    public function testBrokenSnapshotIsReportedWithoutReplacingItWithCurrentText(): void
    {
        $this->db->update($this->wordingTable, ['content' => '{"text":"Tampered"}']);
        $rows = $this->export('consent_records', ['purpose' => 'cart_reminder']);
        $this->assertSame('0', $rows[0]['wording_hash_matches']);
        $this->assertSame('{"text":"Tampered"}', $rows[0]['wording_json']);
    }

    public function testConfigurationUsesTheSameStorefrontEvidenceAsTheCli(): void
    {
        $store = $this->om->get(StoreManagerInterface::class)->getStore('default');
        $rows = $this->export('consent_configuration', ['store_code' => 'default']);
        $this->assertCount(1, $rows);
        $snapshot = json_decode($rows[0]['configuration_json'], true, 512, JSON_THROW_ON_ERROR);
        $expected = $this->om->get(ConfigurationEvidence::class)->get((int)$store->getId());
        unset($expected['exported_at'], $snapshot['exported_at']);
        $this->assertSame($expected, $snapshot);
        $this->assertSame((string)$store->getId(), $rows[0]['store_id']);
    }

    public function testNativeConsumerPublishesTheFinishedCsv(): void
    {
        $info = $this->om->get(ExportInfoFactory::class)->create('csv', 'consent_records', ['purpose' => 'cart_reminder'], [], 'en_US');
        $name = basename($this->path());
        $info->setFileName($name);
        $this->files[] = 'export/' . $name . '.tmp';
        $notifier = $this->createMock(NotifierInterface::class);
        $notifier->expects($this->once())->method('addMajor');
        $notifier->expects($this->never())->method('addCritical');
        $this->om->create(Consumer::class, ['notifier' => $notifier])->process($info);
        $directory = $this->om->get(Filesystem::class)->getDirectoryRead(DirectoryList::VAR_IMPORT_EXPORT);
        $this->assertFalse($directory->isExist('export/' . $name . '.tmp'));
        $this->assertCount(2, $this->parse($directory->readFile('export/' . $name)));
    }

    private function export(string $entity, array $filters, array $skip = []): array
    {
        $info = $this->om->get(ExportInfoFactory::class)->create('csv', $entity, $filters, $skip, 'en_US');
        $name = basename($this->path());
        $info->setFileName($name);
        $this->files[] = 'export/' . $name . '.tmp';
        $result = $this->om->get(ExportManagementInterface::class)->export($info);
        $this->assertSame('__RESULT_WRITTEN_TO_FILE__', $result);
        $directory = $this->om->get(Filesystem::class)->getDirectoryRead(DirectoryList::VAR_IMPORT_EXPORT);
        return $this->parse($directory->readFile('export/' . $name . '.tmp'));
    }

    private function path(): string
    {
        $path = 'export/ck-test-' . bin2hex(random_bytes(8)) . '.csv';
        $this->files[] = $path;
        return $path;
    }

    private function parse(string $csv): array
    {
        $stream = fopen('php://temp', 'w+');
        fwrite($stream, $csv);
        rewind($stream);
        $header = fgetcsv($stream, null, ',', '"', '');
        $rows = [];
        while (($row = fgetcsv($stream, null, ',', '"', '')) !== false) {
            $rows[] = array_combine($header, $row);
        }
        fclose($stream);
        return $rows;
    }
}
