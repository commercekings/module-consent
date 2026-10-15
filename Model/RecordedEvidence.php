<?php
declare(strict_types=1);

namespace CommerceKings\Consent\Model;

/** Shared historical evidence for CLI and native exports. Never substitutes current wording. */
class RecordedEvidence
{
    public function __construct(private readonly ConsentStore $store)
    {
    }

    public function get(string $consentId): ?array
    {
        $row = $this->store->load($consentId);
        if (!$row) {
            return null;
        }
        $hash = (string)($row['snapshot_hash'] ?? '');
        return $this->format($row, $hash !== '' ? $this->store->loadSnapshot($hash) : null);
    }

    public function format(array $consent, ?array $snapshot): array
    {
        return [
            'consent' => $consent,
            'wording' => $snapshot ? [
                'snapshot_hash' => $snapshot['snapshot_hash'],
                'first_seen' => $snapshot['created_at'],
                'hash_matches' => hash('sha256', (string)$snapshot['content']) === $snapshot['snapshot_hash'],
                'content' => json_decode((string)$snapshot['content'], true, 512, JSON_THROW_ON_ERROR),
            ] : null,
        ];
    }
}
