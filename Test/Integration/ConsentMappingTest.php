<?php
declare(strict_types=1);

namespace CommerceKings\Consent\Test\Integration;

use CommerceKings\Consent\Api\BrowserIdentityInterface;
use CommerceKings\Consent\Api\SourceInterface;
use CommerceKings\Consent\Model\Config;
use CommerceKings\Consent\Model\ConfiguredSource;
use CommerceKings\Consent\Model\ConsentService;
use CommerceKings\Consent\Model\ConsentStore;
use CommerceKings\Consent\Model\PurposePool;
use CommerceKings\Consent\Model\WordingSnapshot;
use Magento\Framework\App\Bootstrap;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Adapter\AdapterInterface;
use Magento\Framework\Event\ManagerInterface;
use Magento\Store\Api\Data\WebsiteInterface;
use Magento\Store\Model\StoreManagerInterface;
use PHPUnit\Framework\TestCase;

/** Executes the production SQL guard against isolated temporary tables in the local Magento database. */
class ConsentMappingTest extends TestCase
{
    private AdapterInterface $db;
    private array $categories;
    private array $lifetimes = [9901 => 182, 9902 => 182];
    private const CONSENTS = 'codex_test_consent_mapping';
    private const JOBS = 'codex_test_consent_jobs';

    protected function setUp(): void
    {
        $magentoRoot = getenv('MAGENTO_ROOT') ?: (defined('BP') ? BP : getcwd());
        if (!is_file($magentoRoot . '/app/bootstrap.php')) {
            throw new \RuntimeException('Run from the Magento root or set MAGENTO_ROOT to an installed Magento project.');
        }
        require_once $magentoRoot . '/app/bootstrap.php';
        $om = Bootstrap::create(BP, $_SERVER)->getObjectManager();
        $resource = $om->get(ResourceConnection::class);
        $this->db = $resource->getConnection();
        $this->db->query('CREATE TEMPORARY TABLE ' . self::CONSENTS . ' LIKE '
            . $this->db->quoteIdentifier($resource->getTableName(ConsentStore::TABLE)));
        // Match the production consent_id collation, regardless of the database server's default.
        $this->db->query('CREATE TEMPORARY TABLE ' . self::JOBS
            . ' AS SELECT consent_id FROM ' . self::CONSENTS . ' WHERE 0');
        $this->db->query('ALTER TABLE ' . self::JOBS
            . ' ADD job_id INT PRIMARY KEY, ADD processed TINYINT NOT NULL DEFAULT 0');
        $this->categories = [
            9901 => ['statistics' => ['purposes' => ['analytics']]],
            9902 => ['reminders' => ['purposes' => ['cart_reminder']]],
        ];
    }

    protected function tearDown(): void
    {
        if (isset($this->db)) {
            $this->db->query('DROP TEMPORARY TABLE IF EXISTS ' . self::JOBS);
            $this->db->query('DROP TEMPORARY TABLE IF EXISTS ' . self::CONSENTS);
        }
    }

    public function testStoredConsentAndGuardedJobsRequireTheMappingOnTheirOwnWebsite(): void
    {
        $service = $this->service();
        foreach ([
            [1, 9901, 'analytics'],
            [2, 9901, 'cart_reminder'],
            [3, 9902, 'cart_reminder'],
            [4, 9902, 'analytics'],
        ] as [$jobId, $websiteId, $purpose]) {
            $id = str_pad((string)$jobId, 32, '0', STR_PAD_LEFT);
            $this->db->insert(self::CONSENTS, [
                'consent_id' => $id,
                'purpose' => $purpose,
                'browser_hash' => str_repeat('a', 64),
                'website_id' => $websiteId,
                'source' => 'fixture-' . $websiteId,
                'reference' => 'fixture-' . $jobId,
                'source_time' => gmdate('Y-m-d H:i:s'),
                'banner_revision' => '1',
                'terms_revision' => '1',
                'expires_at' => gmdate('Y-m-d H:i:s', time() + 86400),
            ]);
            $this->db->insert(self::JOBS, ['job_id' => $jobId, 'consent_id' => $id]);
        }

        $this->assertEligibleJobs($service, [1, 3]);

        // Site wiring disappears while its adapter remains explicitly selected in admin.
        $this->categories[9902] = [];
        $this->assertEligibleJobs($service, [1]);

        $this->categories[9901] = [];
        $this->assertSame('0 = 1', $service->validCondition('q.consent_id'));
        $this->assertEligibleJobs($service, []);

        // Configuration removal preserves evidence; restoring it is not a fresh visitor choice.
        $this->categories[9902] = ['reminders' => ['purposes' => ['cart_reminder']]];
        $this->assertEligibleJobs($service, [3]);
        $this->assertSame(4, (int)$this->db->fetchOne('SELECT COUNT(*) FROM ' . self::CONSENTS));
        $this->assertSame(0, (int)$this->db->fetchOne('SELECT COUNT(*) FROM ' . self::CONSENTS
            . ' WHERE withdrawn_at IS NOT NULL'));
        $this->db->update(self::CONSENTS, ['source_time' => gmdate('Y-m-d H:i:s', time() - 30 * 86400)]);
        $this->assertEligibleJobs($service, [3]);
        $this->lifetimes[9902] = 7;
        $this->assertEligibleJobs($service, []);
    }

    private function assertEligibleJobs(ConsentService $service, array $expected): void
    {
        $this->db->update(self::JOBS, ['processed' => 0]);
        $guard = $service->validCondition('q.consent_id');
        $this->assertSame($expected, array_map('intval', $this->db->fetchCol(
            'SELECT q.job_id FROM ' . self::JOBS . ' q WHERE ' . $guard . ' ORDER BY q.job_id'
        )));
        foreach ($this->db->fetchAll('SELECT job_id, consent_id FROM ' . self::JOBS) as $row) {
            $this->assertSame(in_array((int)$row['job_id'], $expected, true), $service->isValid($row['consent_id']));
        }
        $affected = $this->db->query('UPDATE ' . self::JOBS . ' q SET q.processed = 1 WHERE ' . $guard)->rowCount();
        $this->assertSame(count($expected), $affected);
        $this->assertSame($expected, array_map('intval', $this->db->fetchCol(
            'SELECT job_id FROM ' . self::JOBS . ' WHERE processed = 1 ORDER BY job_id'
        )));
    }

    private function service(): ConsentService
    {
        $store = $this->createStub(ConsentStore::class);
        $store->method('connection')->willReturn($this->db);
        $store->method('tableName')->willReturn(self::CONSENTS);
        $store->method('load')->willReturnCallback(function (string $id): ?array {
            return $this->db->fetchRow($this->db->select()->from(self::CONSENTS)->where('consent_id = ?', $id)) ?: null;
        });
        $sources = [];
        $websites = [];
        foreach ([9901, 9902] as $websiteId) {
            $source = $this->createStub(SourceInterface::class);
            $source->method('getCode')->willReturn('fixture-' . $websiteId);
            $source->method('getCategories')->willReturnCallback(fn () => $this->categories[$websiteId]);
            $sources[$websiteId] = $source;
            $website = $this->createStub(WebsiteInterface::class);
            $website->method('getId')->willReturn($websiteId);
            $websites[] = $website;
        }
        $configured = $this->createStub(ConfiguredSource::class);
        $configured->method('get')->willReturnCallback(fn (?int $id = null) => $sources[$id] ?? null);
        $stores = $this->createStub(StoreManagerInterface::class);
        $stores->method('getWebsites')->willReturn($websites);
        $config = $this->createStub(Config::class);
        $config->method('getRevision')->willReturn('1');
        $config->method('isRecorded')->willReturn(true);
        $config->method('getCookieLifetimeDays')->willReturnCallback(fn ($websiteId) => $this->lifetimes[$websiteId]);
        return new ConsentService(
            $store,
            $configured,
            new PurposePool(['analytics' => ['label' => 'Analytics'], 'cart_reminder' => ['label' => 'Reminder']]),
            $this->createStub(BrowserIdentityInterface::class),
            $config,
            $stores,
            $this->createStub(ManagerInterface::class),
            $this->createStub(WordingSnapshot::class),
            new \Magento\Framework\Math\Random(),
            new \CommerceKings\Consent\Model\WithdrawalPool(),
            $this->createStub(\CommerceKings\Consent\Model\WithdrawalCleanupStore::class),
            $this->createStub(\CommerceKings\Consent\Model\WithdrawalCleanup::class),
            $this->createStub(\Magento\Framework\Lock\LockManagerInterface::class),
            new \Psr\Log\NullLogger()
        );
    }
}
