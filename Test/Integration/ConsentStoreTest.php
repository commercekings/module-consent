<?php
declare(strict_types=1);

namespace CommerceKings\Consent\Test\Integration;

use CommerceKings\Consent\Model\ConsentStore;
use Magento\Framework\App\Bootstrap;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Adapter\AdapterInterface;
use PHPUnit\Framework\TestCase;

/**
 * ConsentStore's own SQL on MySQL, against connection-local temporary copies of its tables (no shop data is changed):
 * the purposes of one banner choice withdrawn elsewhere (the banner sync after an email-link withdrawal) and the
 * wording snapshot saved once per hash.
 */
class ConsentStoreTest extends TestCase
{
    private AdapterInterface $db;
    private ConsentStore $store;

    /** One banner choice: browser, website, source, reference and time. */
    private const CHOICE = ['browser_hash' => 'browser-a', 'website_id' => 1, 'source' => 'vanilla',
        'reference' => 'choice-1', 'source_time' => '2026-10-01 12:00:00'];

    private function table(string $name): string
    {
        return 'ck_test_' . $name;
    }

    protected function setUp(): void
    {
        $root = getenv('MAGENTO_ROOT') ?: (defined('BP') ? BP : getcwd());
        require_once $root . '/app/bootstrap.php';
        $real = Bootstrap::create(BP, $_SERVER)->getObjectManager()->get(ResourceConnection::class);
        $this->db = $real->getConnection();
        foreach ([ConsentStore::TABLE, ConsentStore::MARKS, ConsentStore::SNAPSHOTS] as $name) {
            $this->db->query('CREATE TEMPORARY TABLE ' . $this->table($name) . ' LIKE '
                . $this->db->quoteIdentifier($real->getTableName($name)));
        }
        $resource = $this->createStub(ResourceConnection::class);
        $resource->method('getConnection')->willReturn($this->db);
        $resource->method('getTableName')->willReturnCallback(fn ($name) => $this->table($name));
        $this->store = new ConsentStore($resource);
    }

    protected function tearDown(): void
    {
        foreach ([ConsentStore::TABLE, ConsentStore::MARKS, ConsentStore::SNAPSHOTS] as $name) {
            $this->db->query('DROP TEMPORARY TABLE IF EXISTS ' . $this->table($name));
        }
    }

    /** Records a consent of the choice (overrides: another browser, time, ...) the way ConsentService does. */
    private function record(string $purpose, array $overrides = []): string
    {
        $id = bin2hex(random_bytes(16));
        $this->store->record(['consent_id' => $id, 'purpose' => $purpose, 'banner_revision' => '1',
            'terms_revision' => '1', 'expires_at' => '2027-10-01 12:00:00', 'snapshot_hash' => null]
            + $overrides + self::CHOICE);
        $this->assertNotNull($this->store->load($id), 'fixture recorded');
        return $id;
    }

    /** The withdrawn purposes of self::CHOICE, asked for $purposes. */
    private function withdrawn(array $purposes, array $overrides = []): array
    {
        $choice = $overrides + self::CHOICE;
        $found = $this->store->findWithdrawnPurposes($purposes, $choice['browser_hash'], $choice['website_id'],
            $choice['source'], $choice['reference'], $choice['source_time']);
        sort($found);
        return $found;
    }

    public function testOnlyTheWithdrawnPurposesOfTheChoiceAreFound(): void
    {
        $this->record('analytics');
        $reminder = $this->record('cart_reminder');
        $marketing = $this->record('marketing');
        $this->assertSame([], $this->withdrawn(['analytics', 'cart_reminder', 'marketing']), 'nothing withdrawn yet');
        // an email link opened in another browser withdraws two of the purposes
        $this->assertSame(2, $this->store->markWithdrawn([$reminder, $marketing], 'email'));
        $this->assertSame(['cart_reminder', 'marketing'],
            $this->withdrawn(['analytics', 'cart_reminder', 'marketing']));
        $this->assertSame(['cart_reminder'], $this->withdrawn(['analytics', 'cart_reminder']),
            'only the purposes the banner still accepts are asked');
        $this->assertSame([], $this->withdrawn(['analytics']));
    }

    public function testAWithdrawalOfAnotherChoiceIsNotThisChoices(): void
    {
        $others = [['browser_hash' => 'browser-b'], ['website_id' => 2], ['source' => 'other'],
            ['reference' => 'choice-2'], ['source_time' => '2026-10-01 12:00:01']];
        $ids = array_map(fn (array $other) => $this->record('cart_reminder', $other), $others);
        $this->store->markWithdrawn($ids, 'email');
        $this->assertSame([], $this->withdrawn(['cart_reminder']), 'every part of the choice must match');
        foreach ($others as $other) {
            $this->assertSame(['cart_reminder'], $this->withdrawn(['cart_reminder'], $other), json_encode($other));
        }
    }

    public function testNoPurposesAskedNoneFound(): void
    {
        $this->store->markWithdrawn([$this->record('cart_reminder')], 'email');
        $this->assertSame([], $this->withdrawn([]));
    }

    public function testASnapshotIsSavedOnceAndADuplicateLeavesItUnchanged(): void
    {
        $hash = hash('sha256', 'wording one');
        $this->store->saveSnapshot($hash, 1, 1, '3', '{"banner":"wording one"}');
        $first = $this->store->loadSnapshot($hash);
        $this->assertSame([$hash, '1', '1', '3', '{"banner":"wording one"}'], [$first['snapshot_hash'],
            (string)$first['website_id'], (string)$first['store_id'], $first['banner_revision'], $first['content']]);
        // the same hash again (another request, another store view of the same wording): ignored, no error
        $this->db->query('UPDATE ' . $this->table(ConsentStore::SNAPSHOTS)
            . ' SET created_at = created_at - INTERVAL 1 DAY WHERE snapshot_hash = ?', [$hash]);
        $created = $this->store->loadSnapshot($hash)['created_at'];
        $this->store->saveSnapshot($hash, 1, 2, '4', '{"banner":"changed"}');
        $again = $this->store->loadSnapshot($hash);
        $this->assertSame(['1', '3', '{"banner":"wording one"}', $created],
            [(string)$again['store_id'], $again['banner_revision'], $again['content'], $again['created_at']],
            'the first evidence stays as it was');
        $this->assertSame(1, (int)$this->db->fetchOne('SELECT COUNT(*) FROM ' . $this->table(ConsentStore::SNAPSHOTS)));
    }

    public function testAnotherWordingIsAnotherSnapshot(): void
    {
        $this->store->saveSnapshot(hash('sha256', 'one'), 1, 1, '3', '{"banner":"one"}');
        $this->store->saveSnapshot(hash('sha256', 'two'), 1, 1, '3', '{"banner":"two"}');
        $this->assertSame('{"banner":"two"}', $this->store->loadSnapshot(hash('sha256', 'two'))['content']);
        $this->assertSame(2, (int)$this->db->fetchOne('SELECT COUNT(*) FROM ' . $this->table(ConsentStore::SNAPSHOTS)));
    }

    public function testALongWordingIsStoredWhole(): void
    {
        $content = json_encode(['banner' => str_repeat('Wir verwenden Cookies. ', 20000)]);
        $hash = hash('sha256', $content);
        $this->store->saveSnapshot($hash, 1, 1, '3', $content);
        $this->assertSame($content, $this->store->loadSnapshot($hash)['content']);
    }
}
