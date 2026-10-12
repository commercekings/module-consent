<?php
declare(strict_types=1);

namespace CommerceKings\Consent\Test\Unit;

use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Adapter\AdapterInterface;
use Magento\Framework\DB\Select;

/** Recording fake DB adapter + a mock builder for real and magic methods (this package's own copy). */
trait TestHelperTrait
{
    private array $inserts = [];
    private array $deletes = [];
    private array $selectWhere = [];
    private mixed $fetchOneResult = false;

    private function resource(): ResourceConnection
    {
        $select = $this->createStub(Select::class);
        $select->method('from')->willReturnSelf();
        $select->method('where')->willReturnCallback(function ($cond, $value = null) use ($select) {
            $this->selectWhere[] = [$cond, $value];
            return $select;
        });
        $db = $this->createStub(AdapterInterface::class);
        $db->method('select')->willReturn($select);
        $db->method('insertOnDuplicate')->willReturnCallback(function ($table, array $data) {
            $this->inserts[] = ['table' => $table, 'data' => $data];
            return 1;
        });
        $db->method('delete')->willReturnCallback(function ($table, $where = '') {
            $this->deletes[] = ['table' => $table, 'where' => (array)$where];
            return 2;
        });
        $db->method('fetchOne')->willReturnCallback(fn () => $this->fetchOneResult);
        $resource = $this->createStub(ResourceConnection::class);
        $resource->method('getConnection')->willReturn($db);
        $resource->method('getTableName')->willReturnArgument(0);
        return $resource;
    }

    private function mockWith(string $class, array $returns): object
    {
        $mock = $this->createStub($class);
        foreach ($returns as $method => $value) {
            if ($value === '__self__') {
                $mock->method($method)->willReturnSelf();
            } elseif ($value instanceof \Closure) {
                $mock->method($method)->willReturnCallback($value);
            } else {
                $mock->method($method)->willReturn($value);
            }
        }
        return $mock;
    }
}
