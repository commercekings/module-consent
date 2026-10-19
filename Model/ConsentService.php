<?php
declare(strict_types=1);

namespace CommerceKings\Consent\Model;

use CommerceKings\Consent\Exception\WithdrawalBusyException;
use CommerceKings\Consent\Api\BrowserIdentityInterface;
use CommerceKings\Consent\Api\ConsentGuardInterface;
use CommerceKings\Consent\Api\ConsentInterface;
use CommerceKings\Consent\Api\Data\Consent;
use CommerceKings\Consent\Api\Data\SourceChoice;
use CommerceKings\Consent\Api\Data\WithdrawalContext;
use CommerceKings\Consent\Api\SourceInterface;
use Magento\Framework\Event\ManagerInterface;
use Magento\Framework\Math\Random;
use Magento\Framework\Lock\LockManagerInterface;
use Magento\Store\Model\StoreManagerInterface;
use Psr\Log\LoggerInterface;

/**
 * ConsentInterface + ConsentGuardInterface. Everything fails closed: no source chosen, an unknown
 * source, an undeclared or unmapped purpose, a choice without identity or for another revision -> no consent.
 */
class ConsentService implements ConsentInterface, ConsentGuardInterface
{
    /** The last second a MySQL/MariaDB TIMESTAMP column can hold (UTC). */
    private const LAST_TIMESTAMP = '2038-01-19 03:14:07';

    public const EVENT_WITHDRAWN = 'commercekings_consent_withdrawn';

    /** A choice dated further ahead than this (seconds) is a wrong clock or an edited cookie - no consent. */
    public const FUTURE_TOLERANCE = 86400;

    public function __construct(
        private readonly ConsentStore $store,
        private readonly ConfiguredSource $source,
        private readonly PurposePool $purposes,
        private readonly BrowserIdentityInterface $browser,
        private readonly Config $config,
        private readonly StoreManagerInterface $storeManager,
        private readonly ManagerInterface $eventManager,
        private readonly WordingSnapshot $wording,
        private readonly Random $random,
        private readonly WithdrawalPool $withdrawalPool,
        private readonly WithdrawalCleanupStore $cleanupStore,
        private readonly WithdrawalCleanup $cleanup,
        private readonly LockManagerInterface $locks,
        private readonly LoggerInterface $logger
    ) {
    }

    public function getCurrent(string $purpose): ?Consent
    {
        return $this->current($purpose, false);
    }

    /**
     * The visitor just switched the purpose on in the source (js/consent/provider.js -> /ckconsent/grant):
     * like getCurrent(), but a choice after an earlier withdrawal of the same series counts again.
     */
    public function grantCurrent(string $purpose): ?Consent
    {
        return $this->current($purpose, true);
    }

    private function current(string $purpose, bool $explicit): ?Consent
    {
        if (!$this->purposes->isDeclared($purpose)) {
            return null;
        }
        $websiteId = $this->websiteId();
        $source = $this->source->get($websiteId);
        if (!$source || !in_array($purpose, $this->mappedPurposes($source, $websiteId), true)
            // an optional purpose the shop does not record: the source's cookie alone decides, nothing is stored
            || !$this->config->isRecorded($purpose, $websiteId)
        ) {
            return null;
        }
        $revision = $this->config->getRevision($websiteId);
        if (mb_strlen($revision) > ConsentStore::REVISION_LENGTH) {
            // a replaced source configuration: stored, the revision would be cut - record nothing
            $this->logger->error(sprintf('CommerceKings_Consent: the source revision is longer than %d characters; no'
                . ' consent is recorded', ConsentStore::REVISION_LENGTH),
                ['website_id' => $websiteId, 'length' => mb_strlen($revision)]);
            return null;
        }
        $choice = $source->getChoice();
        if (!$choice || !$choice->accepts($purpose) || $choice->reference === '' || $choice->givenAt === ''
            || $this->isTooLong($choice, $websiteId)
            || $choice->revision !== $revision
            || (int)strtotime($choice->givenAt . ' UTC') > time() + self::FUTURE_TOLERANCE
        ) {
            return null;
        }
        $key = [
            'purpose' => $purpose,
            'browser_hash' => $this->browser->ensure(),
            'website_id' => $websiteId,
            'source' => $source->getCode(),
            'reference' => $choice->reference,
            'source_time' => $choice->givenAt,
        ];
        $row = $this->store->find($key);
        if (!$row) {
            // first time this choice is seen: record it (refused after a withdrawal - see ConsentStore::record())
            $this->store->record($key + [
                'consent_id' => $this->random->getRandomString(32, '0123456789abcdef'),
                'banner_revision' => $choice->revision,
                'terms_revision' => $this->purposes->getTermsRevision($purpose),
                'expires_at' => min(
                    $choice->expiresAt ?: '9999-12-31 23:59:59',
                    // counted from the server's time when the browser claims a later one
                    $this->maximumExpiry(min($choice->givenAt, gmdate('Y-m-d H:i:s')), $websiteId)
                ),
                // the wording the visitor saw (evidence, Art. 7(1))
                'snapshot_hash' => $this->wording->currentHash(),
            ], $explicit);
            $row = $this->store->find($key);
        }
        return $row && $this->isValidRow($row) ? $this->toConsent($row) : null;
    }

    public function get(string $consentId): ?Consent
    {
        $row = $consentId === '' ? null : $this->store->load($consentId);
        return $row ? $this->toConsent($row) : null;
    }

    public function isValid(string $consentId): bool
    {
        $row = $consentId === '' ? null : $this->store->load($consentId);
        return $row !== null && $this->isValidRow($row);
    }

    public function withdraw(string $consentId, string $via): array
    {
        $row = $consentId === '' ? null : $this->store->load($consentId);
        if (!$row) {
            return [];
        }
        return $this->withdrawAll(
            (string)$row['purpose'],
            (string)$row['browser_hash'],
            (int)$row['website_id'],
            [[(string)$row['source'], (string)$row['reference'], (string)$row['source_time']]],
            $via,
            $consentId
        );
    }

    /**
     * The current visitor switched the purpose off in the source (js/consent/provider.js -> /ckconsent/withdraw).
     * Only a current choice that says "no" withdraws: nothing happens while the source still says yes, or
     * when there is no current choice (no cookie, or one for an older revision - those consents are
     * already invalid, nobody withdrew them).
     *
     * @return string[] consent ids withdrawn now
     */
    public function withdrawCurrent(string $purpose, string $via): array
    {
        $browserHash = $this->browser->hash();
        if (!$this->purposes->isDeclared($purpose) || $browserHash === '') {
            return [];
        }
        $websiteId = $this->websiteId();
        $source = $this->source->get($websiteId);
        $choice = $source?->getChoice();
        if (!$source || !$choice || $choice->accepts($purpose) || $choice->reference === '' || $choice->givenAt === ''
            || $this->isTooLong($choice, $websiteId)) {
            return [];
        }
        // the "no" itself: anything of this series saved before it (a request still on its way) is refused
        return $this->withdrawAll($purpose, $browserHash, $websiteId, [[$source->getCode(), $choice->reference, $choice->givenAt]], $via);
    }

    /** A third-party source's reference that the database would cut: logged, and neither recorded nor withdrawn. */
    private function isTooLong(SourceChoice $choice, int $websiteId): bool
    {
        if (mb_strlen($choice->reference) <= SourceChoice::REFERENCE_LENGTH) {
            return false;
        }
        $this->logger->error(sprintf('CommerceKings_Consent: the source\'s choice reference is longer than %d'
            . ' characters; it is not recorded', SourceChoice::REFERENCE_LENGTH),
            ['website_id' => $websiteId, 'length' => mb_strlen($choice->reference)]);
        return true;
    }

    public function validCondition(string $consentIdColumn): string
    {
        $db = $this->store->connection();
        $sources = [];
        foreach ($this->storeManager->getWebsites() as $website) {
            $websiteId = (int)$website->getId();
            $source = $this->source->get($websiteId);
            if ($source) {
                $terms = [];
                foreach ($this->mappedPurposes($source, $websiteId) as $purpose) {
                    if (!$this->config->isRecorded($purpose, $websiteId)) {
                        continue;
                    }
                    $terms[] = $db->quoteInto('(ckc.purpose = ?', $purpose)
                        . $db->quoteInto(' AND ckc.terms_revision = ?)', $this->purposes->getTermsRevision($purpose));
                }
                if ($terms) {
                    // Keep purpose mappings in the same website/source branch, never across websites.
                    $sources[] = sprintf('(ckc.website_id = %d', $websiteId)
                        . $db->quoteInto(' AND ckc.source = ?', $source->getCode())
                        . $db->quoteInto(' AND ckc.banner_revision = ?', $this->config->getRevision($websiteId))
                        // the lifetime runs from the earlier of the banner's choice time and the server's record time
                        . sprintf(' AND LEAST(ckc.source_time, ckc.granted_at) > NOW() - INTERVAL %d DAY', $this->config->getCookieLifetimeDays($websiteId))
                        . ' AND (' . implode(' OR ', $terms) . '))';
                }
            }
        }
        if (!$sources) {
            return '0 = 1';
        }
        return sprintf(
            'EXISTS (SELECT 1 FROM %s ckc WHERE ckc.consent_id = %s AND ckc.withdrawn_at IS NULL'
            . ' AND ckc.expires_at > NOW() AND (%s))',
            $db->quoteIdentifier($this->store->tableName()),
            $consentIdColumn,
            implode(' OR ', $sources)
        );
    }

    /**
     * Marks first (so no request still on its way can record the same choices again), then the consents,
     * and durable cleanup tasks in one transaction. Module actions and the legacy event run after commit.
     *
     * @param array<int, array{0: string, 1: string, 2: string}> $marks [source, reference, source time]
     * @return string[]
     */
    private function withdrawAll(
        string $purpose,
        string $browserHash,
        int $websiteId,
        array $marks,
        string $via,
        ?string $anchorId = null
    ): array
    {
        $db = $this->store->connection();
        if ($db->getTransactionLevel() !== 0) {
            throw new \LogicException('Consent withdrawal must run outside a caller-owned database transaction.');
        }
        // Serialize the whole browser/purpose/website scope, including different signed links for it.
        $lock = 'ck_consent_withdraw_' . hash('sha256', $purpose . '|' . $browserHash . '|' . $websiteId);
        if (!$this->locks->lock($lock, 5)) {
            throw new WithdrawalBusyException('Consent withdrawal is busy; retry the request.');
        }
        $ids = [];
        $tasks = [];
        try {
            $db->beginTransaction();
            try {
                // Re-check after the scope lock: an old signed link must not withdraw a later grant.
                $anchor = $anchorId !== null ? $this->store->load($anchorId) : null;
                if ($anchorId !== null && (!$anchor || $anchor['withdrawn_at'] !== null)) {
                    $db->commit();
                    return [];
                }
                // Marks first: a grant for these choices still on its way now waits for this transaction and is
                // refused (record() checks the marks in its own statement). Then a locking read: it sees grants
                // committed in the meantime, so a "yes" recorded a moment ago is withdrawn too.
                foreach ($marks as [$source, $reference, $sourceTime]) {
                    $this->store->mark($purpose, $browserHash, $websiteId, $source, $reference, $sourceTime);
                }
                $active = $this->store->findActive($purpose, $browserHash, $websiteId, true);
                foreach ($active as $row) {
                    $this->store->mark($purpose, $browserHash, $websiteId, (string)$row['source'],
                        (string)$row['reference'], (string)$row['source_time']);
                }
                $ids = array_values(array_map(fn (array $row) => (string)$row['consent_id'], $active));
                $this->store->markWithdrawn($ids, $via);
                $tasks = $this->cleanupStore->schedule(
                    new WithdrawalContext($purpose, $ids, $websiteId, $browserHash, $via),
                    $this->withdrawalPool->getNames($purpose)
                );
                $db->commit();
            } catch (\Throwable $error) {
                $db->rollBack();
                throw $error;
            }
        } finally {
            $this->locks->unlock($lock);
        }
        $this->cleanup->run($tasks);
        if ($ids) {
            try {
                $this->eventManager->dispatch(self::EVENT_WITHDRAWN, [
                    'purpose' => $purpose,
                    'browser_hash' => $browserHash,
                    'website_id' => $websiteId,
                    'consent_ids' => $ids,
                    'via' => $via,
                ]);
            } catch (\Throwable $error) {
                $this->logger->error('Consent withdrawal notification failed after commit.', [
                    'exception_class' => get_class($error),
                ]);
            }
        }
        return $ids;
    }

    private function isValidRow(array $row): bool
    {
        $purpose = (string)$row['purpose'];
        $websiteId = (int)$row['website_id'];
        $source = $this->source->get($websiteId);
        return $row['withdrawn_at'] === null
            && (string)$row['expires_at'] > gmdate('Y-m-d H:i:s')
            && $this->maximumExpiry(min((string)$row['source_time'], (string)$row['granted_at']), $websiteId) > gmdate('Y-m-d H:i:s')
            && $this->purposes->isDeclared($purpose, $websiteId)
            // an optional purpose the shop stopped recording: its old records no longer count either
            && $this->config->isRecorded($purpose, $websiteId)
            && (string)$row['terms_revision'] === $this->purposes->getTermsRevision($purpose)
            && $source !== null
            && (string)$row['source'] === $source->getCode()
            && (string)$row['banner_revision'] === $this->config->getRevision($websiteId)
            && in_array($purpose, $this->mappedPurposes($source, $websiteId), true);
    }

    /** @return string[] Declared purposes granted by at least one current source category. */
    private function mappedPurposes(SourceInterface $source, int $websiteId): array
    {
        $mapped = [];
        foreach ($source->getCategories() as $category) {
            foreach ($category['purposes'] as $purpose) {
                if ($this->purposes->isDeclared($purpose, $websiteId)) {
                    $mapped[$purpose] = $purpose;
                }
            }
        }
        return array_values($mapped);
    }

    private function toConsent(array $row): Consent
    {
        return new Consent(
            (string)$row['consent_id'],
            (string)$row['purpose'],
            (int)$row['website_id'],
            (string)$row['source'],
            (string)$row['reference'],
            (string)$row['source_time'],
            (string)$row['banner_revision'],
            (string)$row['terms_revision'],
            (string)$row['granted_at'],
            (string)$row['expires_at'],
            $row['withdrawn_at'] !== null ? (string)$row['withdrawn_at'] : null,
            $row['withdrawn_via'] !== null ? (string)$row['withdrawn_via'] : null,
            (string)($row['snapshot_hash'] ?? '')
        );
    }

    private function maximumExpiry(string $givenAt, int $websiteId): string
    {
        // expires_at is a TIMESTAMP: past its last second the database would store an already expired 0000-00-00
        return min(self::LAST_TIMESTAMP, gmdate('Y-m-d H:i:s',
            (int)strtotime($givenAt . ' UTC') + $this->config->getCookieLifetimeDays($websiteId) * 86400));
    }

    private function websiteId(): int
    {
        return (int)$this->storeManager->getWebsite()->getId();
    }
}
