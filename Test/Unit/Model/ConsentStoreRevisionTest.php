<?php
declare(strict_types=1);

namespace CommerceKings\Consent\Test\Unit\Model;

use CommerceKings\Consent\Model\ConsentStore;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Adapter\AdapterInterface;
use PHPUnit\Framework\TestCase;

/**
 * The revision columns hold 32 characters and Magento's database session cuts longer values without an error: the
 * store refuses them instead, before any SQL, so no evidence is kept with a cut revision.
 */
class ConsentStoreRevisionTest extends TestCase
{
    private array $queries = [];

    private function store(): ConsentStore
    {
        $db = $this->createStub(AdapterInterface::class);
        $db->method('quoteIdentifier')->willReturnArgument(0);
        $db->method('query')->willReturnCallback(function ($sql) {
            $this->queries[] = $sql;
            return $this->createStub(\Zend_Db_Statement_Interface::class);
        });
        $resource = $this->createStub(ResourceConnection::class);
        $resource->method('getConnection')->willReturn($db);
        $resource->method('getTableName')->willReturnArgument(0);
        return new ConsentStore($resource);
    }

    private function consent(string $bannerRevision, string $termsRevision = '1'): array
    {
        return ['consent_id' => str_repeat('a', 32), 'purpose' => 'cart_reminder', 'browser_hash' => 'bh',
            'website_id' => 1, 'source' => 'vanilla', 'reference' => 'cid-1', 'source_time' => '2026-10-01 12:00:00',
            'banner_revision' => $bannerRevision, 'terms_revision' => $termsRevision,
            'expires_at' => '2027-10-01 12:00:00', 'snapshot_hash' => null];
    }

    public function testAConsentWithALongerRevisionIsRefused(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('banner_revision is longer than 32 characters');
        try {
            $this->store()->record($this->consent(str_repeat('r', 33)));
        } finally {
            $this->assertSame([], $this->queries, 'no SQL');
        }
    }

    public function testASnapshotWithALongerRevisionIsRefused(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('banner_revision is longer than 32 characters');
        try {
            $this->store()->saveSnapshot(str_repeat('h', 64), 1, 1, str_repeat('r', 33), '{}');
        } finally {
            $this->assertSame([], $this->queries);
        }
    }

    public function testThirtyTwoCharactersAreStored(): void
    {
        $store = $this->store();
        $store->record($this->consent(str_repeat('r', 32)));
        $store->saveSnapshot(str_repeat('h', 64), 1, 1, str_repeat('r', 32), '{}');
        $this->assertCount(2, $this->queries);
    }

    public function testCharactersNotBytesAreCounted(): void
    {
        $this->store()->record($this->consent(str_repeat('ä', 32)));
        $this->assertCount(1, $this->queries, 'the column counts characters');
    }
}
