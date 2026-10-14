<?php
declare(strict_types=1);

namespace CommerceKings\Consent\Test\Integration;

use CommerceKings\Consent\Api\BrowserIdentityInterface;
use CommerceKings\Consent\Api\Data\SourceChoice;
use CommerceKings\Consent\Api\Data\WithdrawalContext;
use CommerceKings\Consent\Api\SourceInterface;
use CommerceKings\Consent\Api\WithdrawalHandlerInterface;
use CommerceKings\Consent\Cron\RetryWithdrawals;
use CommerceKings\Consent\Model\Config;
use CommerceKings\Consent\Model\ConfiguredSource;
use CommerceKings\Consent\Model\ConsentService;
use CommerceKings\Consent\Model\ConsentStore;
use CommerceKings\Consent\Model\PurposePool;
use CommerceKings\Consent\Model\WithdrawalCleanup;
use CommerceKings\Consent\Model\WithdrawalCleanupStore;
use CommerceKings\Consent\Model\WithdrawalLink;
use CommerceKings\Consent\Model\WithdrawalPool;
use CommerceKings\Consent\Model\WordingSnapshot;
use Magento\Framework\App\Bootstrap;
use Magento\Framework\App\DeploymentConfig;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Adapter\AdapterInterface;
use Magento\Framework\Encryption\EncryptorInterface;
use Magento\Framework\Event\ManagerInterface;
use Magento\Framework\Lock\LockManagerInterface;
use Magento\Framework\Math\Random;
use Magento\Framework\ObjectManagerInterface;
use Magento\Framework\UrlInterface;
use Magento\Store\Model\StoreManagerInterface;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/** Production transactions and SQL against connection-local temporary tables; no shop data is changed. */
class WithdrawalWorkflowTest extends TestCase
{
    private ObjectManagerInterface $om;
    private AdapterInterface $db;
    private ResourceConnection $resource;
    private ConsentStore $consents;
    private WithdrawalCleanupStore $tasks;
    private WithdrawalCleanup $cleanup;
    private WithdrawalPool $pool;
    private SourceChoice $choice;
    private string $browser = 'browser-a';
    private array $calls = [];
    private array $failures = [];
    private const DATA = 'ck_test_withdrawal_data';

    private function table(string $name): string
    {
        return 'ck_test_' . $name;
    }

    protected function setUp(): void
    {
        $root = getenv('MAGENTO_ROOT') ?: (defined('BP') ? BP : getcwd());
        require_once $root . '/app/bootstrap.php';
        $this->om = Bootstrap::create(BP, $_SERVER)->getObjectManager();
        $this->om->get(StoreManagerInterface::class)->setCurrentStore('default');
        $real = $this->om->get(ResourceConnection::class);
        $this->db = $real->getConnection();
        foreach ([ConsentStore::TABLE, ConsentStore::MARKS, WithdrawalCleanupStore::TABLE] as $name) {
            $this->db->query('CREATE TEMPORARY TABLE ' . $this->table($name) . ' LIKE '
                . $this->db->quoteIdentifier($real->getTableName($name)));
        }
        $this->db->query('CREATE TEMPORARY TABLE ' . self::DATA
            . ' (consent_id VARCHAR(32) PRIMARY KEY, email VARCHAR(255) NULL)');
        $this->resource = $this->createStub(ResourceConnection::class);
        $this->resource->method('getConnection')->willReturn($this->db);
        $this->resource->method('getTableName')->willReturnCallback(fn ($name) => $this->table($name));
        $this->consents = new ConsentStore($this->resource);
        $this->tasks = new WithdrawalCleanupStore($this->resource);
        $handlers = [];
        foreach (['first', 'second'] as $name) {
            $handler = $this->createStub(WithdrawalHandlerInterface::class);
            $handler->method('execute')->willReturnCallback(function (WithdrawalContext $context) use ($name) {
                $this->assertSame(0, $this->db->getTransactionLevel(), 'handler runs after commit');
                foreach ($context->consentIds as $id) {
                    $this->assertNotNull($this->consents->load($id)['withdrawn_at']);
                }
                $this->calls[$name][] = $context;
                if ($this->failures[$name] ?? false) { throw new \RuntimeException('simulated handler failure'); }
                if ($name === 'first') {
                    $this->db->update(self::DATA, ['email' => null], ['consent_id IN (?)' => $context->consentIds]);
                }
            });
            $handlers[$name] = $handler;
        }
        $this->pool = new WithdrawalPool(['cart_reminder' => $handlers]);
        $this->cleanup = new WithdrawalCleanup($this->tasks, $this->pool,
            $this->om->get(LockManagerInterface::class), new NullLogger());
        $this->choose(true, -120);
    }

    protected function tearDown(): void
    {
        while ($this->db->getTransactionLevel() > 0) { $this->db->rollBack(); }
        foreach ([ConsentStore::TABLE, ConsentStore::MARKS, WithdrawalCleanupStore::TABLE] as $name) {
            $this->db->query('DROP TEMPORARY TABLE IF EXISTS ' . $this->table($name));
        }
        $this->db->query('DROP TEMPORARY TABLE IF EXISTS ' . self::DATA);
    }

    private function choose(bool $on, int $offset): void
    {
        $this->choice = new SourceChoice('fixture-choice', gmdate('Y-m-d H:i:s', time() + $offset),
            '1', $on ? ['cart_reminder', 'analytics'] : []);
    }

    private function service(
        ?WithdrawalCleanupStore $tasks = null,
        ?ManagerInterface $events = null,
        ?WithdrawalPool $pool = null
    ): ConsentService
    {
        $source = $this->createStub(SourceInterface::class);
        $source->method('getCode')->willReturn('fixture');
        $source->method('getChoice')->willReturnCallback(fn () => $this->choice);
        $source->method('getCategories')->willReturn(['reminders' => ['purposes' => ['cart_reminder']],
            'statistics' => ['purposes' => ['analytics']]]);
        $configured = $this->createStub(ConfiguredSource::class);
        $configured->method('get')->willReturn($source);
        $browser = $this->createStub(BrowserIdentityInterface::class);
        $browser->method('hash')->willReturnCallback(fn () => $this->browser);
        $browser->method('ensure')->willReturnCallback(fn () => $this->browser);
        $config = $this->createStub(Config::class);
        $config->method('getRevision')->willReturn('1');
        $config->method('getCookieLifetimeDays')->willReturn(182);
        $config->method('isRecorded')->willReturn(true);
        $wording = $this->createStub(WordingSnapshot::class);
        $wording->method('currentHash')->willReturn('');
        return new ConsentService($this->consents, $configured,
            new PurposePool(['cart_reminder' => ['label' => 'Reminder'], 'analytics' => ['label' => 'Analytics']]),
            $browser, $config, $this->om->get(StoreManagerInterface::class),
            $events ?? $this->createStub(ManagerInterface::class), $wording, new Random(), $pool ?? $this->pool,
            $tasks ?? $this->tasks, $this->cleanup, $this->om->get(LockManagerInterface::class), new NullLogger());
    }

    private function capture(ConsentService $service): string
    {
        $id = $service->getCurrent('cart_reminder')->consentId;
        $this->db->insert(self::DATA, ['consent_id' => $id, 'email' => 'guest@example.test']);
        return $id;
    }

    private function email(string $id): ?string
    {
        $row = $this->db->fetchRow($this->db->select()->from(self::DATA)->where('consent_id = ?', $id));
        return $row['email'];
    }

    private function retryNow(): void
    {
        $this->db->update($this->table(WithdrawalCleanupStore::TABLE), ['next_attempt_at' => '2020-01-01 00:00:00']);
        (new RetryWithdrawals($this->tasks, $this->cleanup))->execute();
    }

    public function testBannerWithdrawalCommitsThenRunsAllHandlers(): void
    {
        $service = $this->service();
        $id = $this->capture($service);
        $this->choose(false, -60);
        $this->assertSame([$id], $service->withdrawCurrent('cart_reminder', 'banner'));
        $this->assertNull($this->email($id));
        $this->assertFalse($service->isValid($id));
        $this->assertCount(1, $this->calls['first']);
        $this->assertCount(1, $this->calls['second']);
        $this->assertSame([], $this->tasks->dueIds());
    }

    public function testSignedEmailLinkUsesTheSameWorkflowAndRepeatedLinkPreservesNewGrant(): void
    {
        $service = $this->service();
        $id = $this->capture($service);
        $link = new WithdrawalLink($service, $this->om->get(DeploymentConfig::class),
            $this->om->get(UrlInterface::class), $this->om->get(StoreManagerInterface::class),
            $this->om->get(EncryptorInterface::class));
        parse_str((string)parse_url($link->getUrl($id), PHP_URL_QUERY), $params);
        $this->assertNotNull($link->withdraw($id, $params['t']));
        $this->assertNull($this->email($id));
        $this->assertSame('email', $this->calls['first'][0]->via);
        $this->choose(true, -30);
        $new = $service->grantCurrent('cart_reminder')->consentId;
        $this->db->insert(self::DATA, ['consent_id' => $new, 'email' => 'new@example.test']);
        $link->withdraw($id, $params['t']);
        $this->assertTrue($service->isValid($new));
        $this->assertSame('new@example.test', $this->email($new));
        $this->assertCount(1, $this->calls['first']);
    }

    public function testPartialFailureKeepsPermissionRevokedAndRetriesOnlyFailedHandler(): void
    {
        $service = $this->service();
        $id = $this->capture($service);
        $this->failures['second'] = true;
        $service->withdraw($id, 'email');
        $this->assertFalse($service->isValid($id));
        $this->assertNull($this->email($id));
        $pending = $this->db->fetchAll('SELECT * FROM ' . $this->table(WithdrawalCleanupStore::TABLE));
        $this->assertCount(1, $pending);
        $this->assertSame('second', $pending[0]['handler']);
        $this->assertSame(1, (int)$pending[0]['attempts']);
        $this->assertSame([], $this->tasks->dueIds(), 'backoff prevents an immediate processing loop');
        $this->failures['second'] = false;
        $this->retryNow();
        $this->assertCount(1, $this->calls['first']);
        $this->assertCount(2, $this->calls['second']);
        $this->assertSame(0, (int)$this->db->fetchOne('SELECT COUNT(*) FROM ' . $this->table(WithdrawalCleanupStore::TABLE)));
    }

    public function testDelayedCleanupCannotDeleteALaterGrantOrAnotherBrowser(): void
    {
        $service = $this->service();
        $old = $this->capture($service);
        $analytics = $service->getCurrent('analytics')->consentId;
        $this->browser = 'browser-b';
        $other = $this->capture($service);
        $this->browser = 'browser-a';
        $this->failures['first'] = true;
        $service->withdraw($old, 'email');
        $this->choose(true, -30);
        $new = $service->grantCurrent('cart_reminder')->consentId;
        $this->db->insert(self::DATA, ['consent_id' => $new, 'email' => 'new@example.test']);
        $this->failures['first'] = false;
        $this->retryNow();
        $this->assertNull($this->email($old));
        $this->assertSame('new@example.test', $this->email($new));
        $this->assertSame('guest@example.test', $this->email($other));
        $this->assertTrue($service->isValid($new));
        $this->assertTrue($service->isValid($other));
        $this->assertTrue($service->isValid($analytics));
    }

    public function testFailureToPersistTasksRollsBackTheEntireCoreOperation(): void
    {
        $tasks = $this->createStub(WithdrawalCleanupStore::class);
        $tasks->method('schedule')->willThrowException(new \RuntimeException('database failure'));
        $service = $this->service($tasks);
        $id = $this->capture($service);
        try { $service->withdraw($id, 'email'); $this->fail('Expected persistence failure'); }
        catch (\RuntimeException $error) { $this->assertSame('database failure', $error->getMessage()); }
        $this->assertTrue($service->isValid($id));
        $this->assertSame('guest@example.test', $this->email($id));
        $this->assertSame(0, (int)$this->db->fetchOne('SELECT COUNT(*) FROM ' . $this->table(ConsentStore::MARKS)));
        $this->assertSame([], $this->calls);
    }

    public function testLegacyNotificationFailureCannotUndoWithdrawal(): void
    {
        $events = $this->createStub(ManagerInterface::class);
        $events->method('dispatch')->willThrowException(new \RuntimeException('observer failure'));
        $service = $this->service(null, $events);
        $id = $this->capture($service);
        $this->assertSame([$id], $service->withdraw($id, 'email'));
        $this->assertFalse($service->isValid($id));
        $this->assertNull($this->email($id));
    }

    public function testCallerTransactionCannotRunCleanupBeforeActualCommit(): void
    {
        $service = $this->service();
        $id = $this->capture($service);
        $this->db->beginTransaction();
        try { $service->withdraw($id, 'email'); $this->fail('Expected caller transaction rejection'); }
        catch (\LogicException $error) { $this->assertStringContainsString('caller-owned', $error->getMessage()); }
        $this->db->rollBack();
        $this->assertTrue($service->isValid($id));
        $this->assertSame([], $this->calls);
    }

    public function testCoreWithdrawalNeedsNoFeatureModule(): void
    {
        $service = $this->service(null, null, new WithdrawalPool());
        $id = $this->capture($service);
        $this->assertSame([$id], $service->withdraw($id, 'email'));
        $this->assertFalse($service->isValid($id));
        $this->assertSame([], $this->calls);
        $this->assertSame([], $this->tasks->dueIds());
    }

    public function testNewProcessRecoversTasksCommittedBeforeAnInterruption(): void
    {
        $service = $this->service();
        $id = $this->capture($service);
        $row = $this->consents->load($id);
        // Simulate interruption between the core commit and synchronous cleanup.
        $this->db->beginTransaction();
        $this->consents->markWithdrawn([$id], 'email');
        $this->tasks->schedule(new WithdrawalContext('cart_reminder', [$id], (int)$row['website_id'],
            (string)$row['browser_hash'], 'email'), $this->pool->getNames('cart_reminder'));
        $this->db->commit();
        $freshStore = new WithdrawalCleanupStore($this->resource);
        $freshRunner = new WithdrawalCleanup($freshStore, $this->pool, $this->om->get(LockManagerInterface::class), new NullLogger());
        (new RetryWithdrawals($freshStore, $freshRunner))->execute();
        $this->assertFalse($service->isValid($id));
        $this->assertNull($this->email($id));
        $this->assertCount(1, $this->calls['first']);
        $this->assertCount(1, $this->calls['second']);
        $this->assertSame([], $freshStore->dueIds());
    }

    public function testNativeLockPreventsAnotherConnectionExecutingTheSameTask(): void
    {
        $service = $this->service();
        $id = $this->capture($service);
        $this->failures['second'] = true;
        $service->withdraw($id, 'email');
        $this->db->update($this->table(WithdrawalCleanupStore::TABLE), ['next_attempt_at' => '2020-01-01 00:00:00']);
        $task = $this->tasks->dueIds()[0];
        $separateResource = $this->om->create(ResourceConnection::class);
        $blocker = new \Magento\Framework\Lock\Backend\Database($separateResource, $this->om->get(DeploymentConfig::class));
        $lock = 'ck_consent_cleanup_' . $task;
        $this->assertTrue($blocker->lock($lock, 0));
        try {
            $this->cleanup->run([$task]);
            $this->assertCount(1, $this->calls['second'], 'another connection holds the native task lock');
            $this->assertSame(1, (int)$this->tasks->loadDue($task)['attempts']);
        } finally {
            $blocker->unlock($lock);
            $separateResource->closeConnection();
        }
        $this->failures['second'] = false;
        $this->cleanup->run([$task]);
        $this->assertCount(2, $this->calls['second']);
        $this->assertSame([], $this->tasks->dueIds());
    }
}
