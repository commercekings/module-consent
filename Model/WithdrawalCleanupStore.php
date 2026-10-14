<?php
declare(strict_types=1);

namespace CommerceKings\Consent\Model;

use CommerceKings\Consent\Api\Data\WithdrawalContext;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Sql\Expression;

/** Durable actions, written on the same connection and transaction as consent withdrawal. */
class WithdrawalCleanupStore
{
    public const TABLE = 'ck_consent_cleanup';

    public function __construct(private readonly ResourceConnection $resource)
    {
    }

    /**
     * One task per withdrawn consent and named handler. Completed tasks are deleted.
     * @param string[] $handlers
     * @return int[]
     */
    public function schedule(WithdrawalContext $context, array $handlers): array
    {
        $db = $this->resource->getConnection();
        $table = $this->resource->getTableName(self::TABLE);
        $ids = [];
        foreach ($context->consentIds as $consentId) {
            foreach ($handlers as $handler) {
                $db->insert($table, [
                    'consent_id' => $consentId, 'handler' => $handler, 'purpose' => $context->purpose,
                    'website_id' => $context->websiteId, 'browser_hash' => $context->browserHash,
                    'via' => substr($context->via, 0, 16),
                ]);
                $ids[] = (int)$db->fetchOne($db->select()->from($table, 'task_id')
                    ->where('consent_id = ?', $consentId)->where('handler = ?', $handler));
            }
        }
        return $ids;
    }

    /** @return int[] */
    public function dueIds(int $limit = 100): array
    {
        $db = $this->resource->getConnection();
        return array_map('intval', $db->fetchCol($db->select()
            ->from($this->resource->getTableName(self::TABLE), 'task_id')
            ->where('next_attempt_at <= NOW()')->order(['next_attempt_at ASC', 'task_id ASC'])->limit($limit)));
    }

    /** Fresh read after acquiring the task lock. */
    public function loadDue(int $id): ?array
    {
        $db = $this->resource->getConnection();
        return $db->fetchRow($db->select()->from($this->resource->getTableName(self::TABLE))
            ->where('task_id = ?', $id)->where('next_attempt_at <= NOW()')) ?: null;
    }

    public function complete(int $id): void
    {
        $this->resource->getConnection()->delete($this->resource->getTableName(self::TABLE), ['task_id = ?' => $id]);
    }

    /** Back off from one minute to one hour; unfinished cleanup is never silently discarded. */
    public function retry(int $id, int $attempts): void
    {
        $delay = min(3600, 60 * (2 ** min($attempts, 6)));
        $this->resource->getConnection()->update($this->resource->getTableName(self::TABLE), [
            'attempts' => new Expression('attempts + 1'),
            'next_attempt_at' => new Expression(sprintf('DATE_ADD(NOW(), INTERVAL %d SECOND)', $delay)),
        ], ['task_id = ?' => $id]);
    }
}
