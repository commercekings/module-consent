<?php
declare(strict_types=1);

namespace CommerceKings\Consent\Test\Unit\Model;

use CommerceKings\Consent\Model\ConsentStore;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Adapter\AdapterInterface;
use Magento\Framework\DB\Select;
use PHPUnit\Framework\TestCase;

/** ConsentStore::cleanUp() deletes in batches by primary key, repeating each condition in the DELETE. */
class ConsentStoreCleanUpTest extends TestCase
{
    /** @var array<int, array{string, array}> table and conditions of every select fetched */
    private array $selects = [];
    /** @var array<int, array{string, array}> table and where of every delete */
    private array $deletes = [];
    /** @var array<string, array<int, array>> batches the selects return, per table */
    private array $batches = [];
    /** @var array<int, int> what each delete reports */
    private array $deleted = [];
    private \SplObjectStorage $state;

    private function store(int $batch): ConsentStore
    {
        $this->state = new \SplObjectStorage();
        $db = $this->createStub(AdapterInterface::class);
        $db->method('quoteInto')->willReturnCallback(fn ($text, $value) => str_replace('?', "'" . $value . "'", $text));
        $db->method('quoteIdentifier')->willReturnCallback(fn ($name) => '`' . $name . '`');
        $db->method('fetchOne')->willReturnCallback(fn ($sql, $bind) => match ($bind) {
            [30] => '2026-09-19 03:00:00',
            [366] => '2025-10-18 03:00:00',
        });
        $db->method('select')->willReturnCallback(function () {
            $select = $this->createStub(Select::class);
            $state = new \ArrayObject(['table' => '', 'where' => []]);
            $select->method('from')->willReturnCallback(function ($table) use ($select, $state) {
                $state['table'] = $table;
                return $select;
            });
            $select->method('where')->willReturnCallback(function ($condition) use ($select, $state) {
                $state['where'] = [...$state['where'], $condition];
                return $select;
            });
            $select->method('limit')->willReturnSelf();
            $this->state[$select] = $state;
            return $select;
        });
        $fetch = function ($select) {
            $state = $this->state[$select];
            $this->selects[] = [$state['table'], $state['where']];
            $this->batches[$state['table']] ??= [];
            return array_shift($this->batches[$state['table']]) ?? [];
        };
        $db->method('fetchCol')->willReturnCallback($fetch);
        $db->method('fetchAll')->willReturnCallback($fetch);
        $db->method('delete')->willReturnCallback(function ($table, $where) {
            $this->deletes[] = [$table, $where];
            return array_shift($this->deleted) ?? 0;
        });
        $resource = $this->createStub(ResourceConnection::class);
        $resource->method('getConnection')->willReturn($db);
        $resource->method('getTableName')->willReturnArgument(0);
        return new ConsentStore($resource, $batch);
    }

    public function testEachTableIsDeletedInBatchesUntilABatchComesBackShort(): void
    {
        $mark = ['purpose' => 'marketing', 'browser_hash' => 'h', 'website_id' => '1', 'source' => 'banner',
            'reference' => 'r'];
        $this->batches = [
            'ck_consent' => [['a', 'b'], ['c'], ['d']], // withdrawn: a full batch, a short one; expired: one
            'ck_consent_snapshot' => [['s1', 's2'], []],
            'ck_consent_withdrawal' => [[$mark]],
        ];
        $this->deleted = [2, 1, 1, 2, 1];
        $this->assertSame(7, $this->store(2)->cleanUp(30, 366));

        $withdrawn = "withdrawn_at < '2026-09-19 03:00:00'";
        $expired = "expires_at < '2026-09-19 03:00:00'";
        $unused = 'NOT EXISTS (SELECT 1 FROM `ck_consent` c WHERE c.snapshot_hash = `ck_consent_snapshot`.snapshot_hash)';
        $old = "created_at < '2026-09-19 03:00:00'";
        $stale = "updated_at < '2025-10-18 03:00:00'";
        $this->assertSame([
            ['ck_consent', [$withdrawn]],
            ['ck_consent', [$withdrawn]],
            ['ck_consent', [$expired]],
            ['ck_consent_snapshot', [$old, $unused]],
            ['ck_consent_snapshot', [$old, $unused]],
            ['ck_consent_withdrawal', [$stale]],
        ], $this->selects);
        $this->assertSame([
            ['ck_consent', ['consent_id IN (?)' => ['a', 'b'], $withdrawn]],
            ['ck_consent', ['consent_id IN (?)' => ['c'], $withdrawn]],
            ['ck_consent', ['consent_id IN (?)' => ['d'], $expired]],
            ['ck_consent_snapshot', ['snapshot_hash IN (?)' => ['s1', 's2'], $old, $unused]],
            ['ck_consent_withdrawal', ["((`purpose` = 'marketing' AND `browser_hash` = 'h' AND `website_id` = '1'"
                . " AND `source` = 'banner' AND `reference` = 'r'))", $stale]],
        ], $this->deletes);
    }

    /** Rows the DELETE's own condition keeps (e.g. a mark renewed meanwhile) end the loop instead of repeating it. */
    public function testABatchThatDeletesNothingEndsTheLoop(): void
    {
        $this->batches = ['ck_consent' => [['a', 'b'], ['a', 'b']]];
        $this->deleted = [0];
        $this->assertSame(0, $this->store(2)->cleanUp(30, 366));
        $this->assertSame(['ck_consent', ['consent_id IN (?)' => ['a', 'b'], "withdrawn_at < '2026-09-19 03:00:00'"]],
            $this->deletes[0]);
        $this->assertStringStartsWith('expires_at', $this->selects[1][1][0], 'the withdrawn pass stopped after one batch');
    }

    public function testWithoutASeparateMinimumMarksUseTheRecordDays(): void
    {
        $store = $this->store(1000);
        $this->batches = ['ck_consent_withdrawal' => [[]]];
        $store->cleanUp(366);
        $this->assertSame("updated_at < '2025-10-18 03:00:00'", end($this->selects)[1][0]);
    }
}
