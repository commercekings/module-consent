<?php
declare(strict_types=1);

namespace CommerceKings\Consent\Model;

use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Sql\Expression;

/**
 * Database side of consents:
 *  - ck_consent: one row per recorded consent action (pseudonymous browser hashes and ids, no email addresses);
 *  - ck_consent_withdrawal: per browser + purpose + website + source choice series, the latest source
 *    time that was withdrawn. A choice at or before it is never recorded again (an old "yes" cookie on
 *    another device, a request that was still on its way when the visitor withdrew).
 */
class ConsentStore
{
    public const TABLE = 'ck_consent';
    public const MARKS = 'ck_consent_withdrawal';
    public const SNAPSHOTS = 'ck_consent_snapshot';

    private const COLUMNS = [
        'consent_id', 'purpose', 'browser_hash', 'website_id', 'source', 'reference', 'source_time',
        'banner_revision', 'terms_revision', 'expires_at', 'snapshot_hash',
    ];

    public function __construct(
        private readonly ResourceConnection $resource
    ) {
    }

    public function load(string $consentId): ?array
    {
        $db = $this->resource->getConnection();
        $row = $db->fetchRow($db->select()->from($this->table())->where('consent_id = ?', $consentId));
        return $row ?: null;
    }

    /** The row of one source choice (purpose, browser_hash, website_id, source, reference, source_time). */
    public function find(array $key): ?array
    {
        $db = $this->resource->getConnection();
        $select = $db->select()->from($this->table());
        foreach (['purpose', 'browser_hash', 'website_id', 'source', 'reference', 'source_time'] as $column) {
            $select->where($column . ' = ?', $key[$column]);
        }
        $row = $db->fetchRow($select);
        return $row ?: null;
    }

    /**
     * Of $purposes, those whose record of this one source choice was withdrawn (e.g. by an email link opened
     * in another browser). One lookup on the unique choice index.
     *
     * @param string[] $purposes
     * @return string[]
     */
    public function findWithdrawnPurposes(
        array $purposes,
        string $browserHash,
        int $websiteId,
        string $source,
        string $reference,
        string $sourceTime
    ): array {
        if (!$purposes) {
            return [];
        }
        $db = $this->resource->getConnection();
        return array_values(array_map('strval', $db->fetchCol($db->select()->from($this->table(), ['purpose'])
            ->where('purpose IN (?)', array_values($purposes))
            ->where('browser_hash = ?', $browserHash)
            ->where('website_id = ?', $websiteId)
            ->where('source = ?', $source)
            ->where('reference = ?', $reference)
            ->where('source_time = ?', $sourceTime)
            ->where('withdrawn_at IS NOT NULL'))));
    }

    /**
     * Records the consent unless the same choice is already recorded or was withdrawn - in ONE statement,
     * so it can't slip past a withdrawal that is being written at the same moment. $explicit = the visitor
     * just switched the purpose on: only choices up to the withdrawn time are refused. Otherwise (a choice
     * seen for the first time) ANY withdrawal of this series refuses it - re-saving the banner for another
     * category is no new choice for this purpose.
     */
    public function record(array $data, bool $explicit = false): void
    {
        $db = $this->resource->getConnection();
        $values = array_map(fn ($column) => $data[$column] ?? null, self::COLUMNS);
        $sql = sprintf(
            'INSERT IGNORE INTO %s (%s) SELECT %s FROM DUAL WHERE NOT EXISTS ('
            . 'SELECT 1 FROM %s m WHERE m.purpose = ? AND m.browser_hash = ? AND m.website_id = ?'
            . ' AND m.source = ? AND m.reference = ?' . ($explicit ? ' AND m.source_time >= ?' : '') . ')',
            $db->quoteIdentifier($this->table()),
            implode(', ', array_map([$db, 'quoteIdentifier'], self::COLUMNS)),
            implode(', ', array_fill(0, count($values), '?')),
            $db->quoteIdentifier($this->resource->getTableName(self::MARKS))
        );
        $bind = [$data['purpose'], $data['browser_hash'], $data['website_id'], $data['source'], $data['reference']];
        if ($explicit) {
            $bind[] = $data['source_time'];
        }
        $db->query($sql, array_merge($values, $bind));
    }

    /**
     * Active (not withdrawn) consents of a browser for a purpose on a website. $forUpdate: a locking read inside the
     * caller's transaction - it sees grants committed meanwhile and holds back new ones for this scope until commit.
     */
    public function findActive(string $purpose, string $browserHash, int $websiteId, bool $forUpdate = false): array
    {
        $db = $this->resource->getConnection();
        $select = $db->select()->from($this->table(), ['consent_id', 'source', 'reference', 'source_time'])
            ->where('purpose = ?', $purpose)
            ->where('browser_hash = ?', $browserHash)
            ->where('website_id = ?', $websiteId)
            ->where('withdrawn_at IS NULL');
        return $db->fetchAll($forUpdate ? $select->forUpdate(true) : $select);
    }

    /** Withdrawal mark: choices of this series at or before $sourceTime are never recorded again. */
    public function mark(string $purpose, string $browserHash, int $websiteId, string $source, string $reference, string $sourceTime): void
    {
        $db = $this->resource->getConnection();
        $db->query(
            sprintf(
                'INSERT INTO %s (purpose, browser_hash, website_id, source, reference, source_time) VALUES (?, ?, ?, ?, ?, ?)'
                . ' ON DUPLICATE KEY UPDATE source_time = GREATEST(source_time, VALUES(source_time)), updated_at = NOW()',
                $db->quoteIdentifier($this->resource->getTableName(self::MARKS))
            ),
            [$purpose, $browserHash, $websiteId, $source, $reference, $sourceTime]
        );
    }

    /** @param string[] $consentIds */
    public function markWithdrawn(array $consentIds, string $via): int
    {
        if (!$consentIds) {
            return 0;
        }
        return $this->resource->getConnection()->update(
            $this->table(),
            ['withdrawn_at' => new Expression('NOW()'), 'withdrawn_via' => substr($via, 0, 16)],
            ['consent_id IN (?)' => $consentIds, 'withdrawn_at IS NULL']
        );
    }

    /** Deletes expired / withdrawn evidence after $days and marks after the separate safety minimum. */
    public function cleanUp(int $days, ?int $withdrawalDays = null): int
    {
        $db = $this->resource->getConnection();
        $deleted = $db->delete(
            $this->table(),
            sprintf('withdrawn_at < NOW() - INTERVAL %1$d DAY OR expires_at < NOW() - INTERVAL %1$d DAY', $days)
        );
        // wording no consent refers to any more
        $snapshots = $db->query(
            sprintf(
                'DELETE s FROM %s s WHERE s.created_at < NOW() - INTERVAL %d DAY'
                . ' AND NOT EXISTS (SELECT 1 FROM %s c WHERE c.snapshot_hash = s.snapshot_hash)',
                $db->quoteIdentifier($this->resource->getTableName(self::SNAPSHOTS)),
                $days,
                $db->quoteIdentifier($this->table())
            )
        )->rowCount();
        return $deleted + $snapshots + $db->delete(
            $this->resource->getTableName(self::MARKS),
            ['updated_at < NOW() - INTERVAL ? DAY' => max($days, $withdrawalDays ?? $days)]
        );
    }

    /** Saves a wording snapshot once (the hash is its identity). */
    public function saveSnapshot(string $hash, int $websiteId, int $storeId, string $revision, string $content): void
    {
        $db = $this->resource->getConnection();
        $db->query(
            sprintf(
                'INSERT IGNORE INTO %s (snapshot_hash, website_id, store_id, banner_revision, content) VALUES (?, ?, ?, ?, ?)',
                $db->quoteIdentifier($this->resource->getTableName(self::SNAPSHOTS))
            ),
            [$hash, $websiteId, $storeId, $revision, $content]
        );
    }

    public function loadSnapshot(string $hash): ?array
    {
        $db = $this->resource->getConnection();
        $row = $db->fetchRow(
            $db->select()->from($this->resource->getTableName(self::SNAPSHOTS))->where('snapshot_hash = ?', $hash)
        );
        return $row ?: null;
    }

    /** The first wording recorded for a revision in a store view (what the revision's first visitors saw). */
    public function firstSnapshot(int $storeId, string $revision): ?array
    {
        $db = $this->resource->getConnection();
        $row = $db->fetchRow(
            $db->select()->from($this->resource->getTableName(self::SNAPSHOTS), ['snapshot_hash', 'created_at'])
                ->where('store_id = ?', $storeId)
                ->where('banner_revision = ?', $revision)
                ->order('created_at ASC')
                ->limit(1)
        );
        return $row ?: null;
    }

    public function tableName(): string
    {
        return $this->resource->getTableName(self::TABLE);
    }

    public function connection(): \Magento\Framework\DB\Adapter\AdapterInterface
    {
        return $this->resource->getConnection();
    }

    private function table(): string
    {
        return $this->tableName();
    }
}
