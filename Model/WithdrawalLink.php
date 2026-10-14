<?php
declare(strict_types=1);

namespace CommerceKings\Consent\Model;

use CommerceKings\Consent\Api\ConsentInterface;
use CommerceKings\Consent\Api\Data\Consent;
use CommerceKings\Consent\Api\WithdrawalLinkInterface;
use Magento\Framework\App\DeploymentConfig;
use Magento\Framework\Encryption\EncryptorInterface;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\UrlInterface;
use Magento\Store\Model\StoreManagerInterface;

/** A signed link authorizes withdrawal from any browser, on the recorded consent's website only. */
class WithdrawalLink implements WithdrawalLinkInterface
{
    public function __construct(
        private readonly ConsentInterface $consents,
        private readonly DeploymentConfig $deploymentConfig,
        private readonly UrlInterface $url,
        private readonly StoreManagerInterface $stores,
        private readonly ConsentStore $store,
        private readonly EncryptorInterface $encryptor
    ) {
    }

    public function getUrl(string $consentId): string
    {
        $consent = $this->consents->get($consentId);
        if (!$consent || $consent->websiteId !== (int)$this->stores->getStore()->getWebsiteId()) {
            throw new LocalizedException(__('This consent record is no longer available.'));
        }
        return $this->url->getUrl('ckconsent/optout', [
            '_scope' => (int)$this->stores->getStore()->getId(),
            '_query' => ['c' => $consentId, 't' => $this->sign($consentId, $consent->websiteId)],
            '_nosid' => true,
        ]);
    }

    /** Authenticate before reading or displaying a record; do not require its consent to remain valid. */
    public function getConsent(string $consentId, string $token): ?Consent
    {
        $websiteId = (int)$this->stores->getStore()->getWebsiteId();
        if (!$this->isSigned($consentId, $token)) {
            return null;
        }
        $consent = $this->consents->get($consentId);
        return $consent && $consent->websiteId === $websiteId ? $consent : null;
    }

    /** Serialize use of a link so a repeated request cannot revoke a later explicit re-grant. */
    public function withdraw(string $consentId, string $token): ?Consent
    {
        if (!$this->isSigned($consentId, $token)) {
            return null;
        }
        $db = $this->store->connection();
        $db->beginTransaction();
        try {
            $db->fetchAll($db->select()->from($this->store->tableName(), 'consent_id')
                ->where('consent_id = ?', $consentId)->forUpdate(true));
            $consent = $this->getConsent($consentId, $token);
            if ($consent && $consent->withdrawnAt === null) {
                $this->consents->withdraw($consentId, 'email');
            }
            $db->commit();
            return $consent;
        } catch (\Throwable $error) {
            $db->rollBack();
            throw $error;
        }
    }

    private function isSigned(string $consentId, string $token): bool
    {
        if ($consentId === '' || !preg_match('/^[a-f0-9]{64}$/D', $token)) {
            return false;
        }
        $websiteId = (int)$this->stores->getStore()->getWebsiteId();
        try {
            return hash_equals($this->sign($consentId, $websiteId), $token);
        } catch (\LogicException | \RuntimeException) {
            return false;
        }
    }

    private function sign(string $consentId, int $websiteId): string
    {
        $this->assertDeploymentKey();
        return $this->encryptor->hash($this->payload($consentId, $websiteId));
    }

    private function payload(string $consentId, int $websiteId): string
    {
        return 'commercekings_consent_withdrawal|' . $websiteId . '|' . $consentId;
    }

    private function assertDeploymentKey(): void
    {
        $key = $this->deploymentConfig->get('crypt/key');
        if (!is_string($key) || trim($key) === '') {
            throw new \LogicException('The deployment crypt key is required for consent withdrawal links.');
        }
    }
}
