<?php
declare(strict_types=1);

namespace CommerceKings\Consent\Test\Unit;

use CommerceKings\Consent\Model\ConsentStore;

/** ConsentStore with the same rules as its SQL (unique choice, withdrawal marks), in memory. */
class InMemoryConsentStore extends ConsentStore
{
    /** @var array<string, array> consent_id => row */
    public array $rows = [];
    /** @var array<string, string> mark key => source_time */
    public array $marks = [];
    public string $now = '2026-10-10 12:00:00';

    public function __construct()
    {
    }

    public function load(string $consentId): ?array
    {
        return $this->rows[$consentId] ?? null;
    }

    public function find(array $key): ?array
    {
        foreach ($this->rows as $row) {
            if (array_intersect_key($row, $key) == $key) {
                return $row;
            }
        }
        return null;
    }

    public function record(array $data, bool $explicit = false): void
    {
        $mark = $this->marks[$this->markKey($data['purpose'], $data['browser_hash'], (int)$data['website_id'], $data['source'], $data['reference'])] ?? null;
        $key = array_intersect_key($data, array_flip(['purpose', 'browser_hash', 'website_id', 'source', 'reference', 'source_time']));
        if (($mark !== null && (!$explicit || $mark >= $data['source_time'])) || $this->find($key)) {
            return;
        }
        $this->rows[$data['consent_id']] = $data + ['granted_at' => $this->now, 'withdrawn_at' => null, 'withdrawn_via' => null];
    }

    public function findActive(string $purpose, string $browserHash, int $websiteId): array
    {
        return array_values(array_filter($this->rows, fn ($row) => $row['purpose'] === $purpose
            && $row['browser_hash'] === $browserHash && (int)$row['website_id'] === $websiteId && $row['withdrawn_at'] === null));
    }

    public function mark(string $purpose, string $browserHash, int $websiteId, string $source, string $reference, string $sourceTime): void
    {
        $key = $this->markKey($purpose, $browserHash, $websiteId, $source, $reference);
        $this->marks[$key] = max($this->marks[$key] ?? '', $sourceTime);
    }

    public function markWithdrawn(array $consentIds, string $via): int
    {
        $count = 0;
        foreach ($consentIds as $id) {
            if (isset($this->rows[$id]) && $this->rows[$id]['withdrawn_at'] === null) {
                $this->rows[$id]['withdrawn_at'] = $this->now;
                $this->rows[$id]['withdrawn_via'] = $via;
                $count++;
            }
        }
        return $count;
    }

    private function markKey(string $purpose, string $browserHash, int $websiteId, string $source, string $reference): string
    {
        return implode('|', [$purpose, $browserHash, $websiteId, $source, $reference]);
    }
}
