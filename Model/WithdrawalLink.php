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

/**
 * A signed link authorizes withdrawal from any browser, on the recorded consent's website only. New links are signed
 * with the newest key; links signed with an older key still listed in env.php keep working after a key change.
 */
class WithdrawalLink implements WithdrawalLinkInterface
{
    public function __construct(
        private readonly ConsentInterface $consents,
        private readonly DeploymentConfig $deploymentConfig,
        private readonly UrlInterface $url,
        private readonly StoreManagerInterface $stores,
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

    /** Consent's central checkpoint serializes withdrawal; cleanup cannot roll it back. */
    public function withdraw(string $consentId, string $token): ?Consent
    {
        if (!$this->isSigned($consentId, $token)) {
            return null;
        }
        $consent = $this->getConsent($consentId, $token);
        if ($consent && $consent->withdrawnAt === null) {
            $this->consents->withdraw($consentId, 'email');
        }
        return $consent;
    }

    private function isSigned(string $consentId, string $token): bool
    {
        if ($consentId === '' || !preg_match('/^[a-f0-9]{64}$/D', $token)) {
            return false;
        }
        $websiteId = (int)$this->stores->getStore()->getWebsiteId();
        try {
            $current = $this->sign($consentId, $websiteId);
        } catch (\LogicException | \RuntimeException) {
            return false;
        }
        if (hash_equals($current, $token)) {
            return true;
        }
        // Links already in inboxes keep working after encryption:key:change: older keys still listed in env.php
        // verify them too. A link can only withdraw, so an old key can never turn anything on.
        foreach ($this->earlierSignatures($this->payload($consentId, $websiteId), $current) as $signature) {
            if (hash_equals($signature, $token)) {
                return true;
            }
        }
        return false;
    }

    /**
     * Signatures under the older keys in crypt/key (newest last), computed like Encryptor::hash(). None when that
     * computation does not reproduce the encryptor's own signature for the newest key.
     *
     * @return string[]
     */
    private function earlierSignatures(string $payload, string $current): array
    {
        $keys = preg_split('/\s+/', trim((string)$this->deploymentConfig->get('crypt/key'))) ?: [];
        $signatures = array_map(fn (string $key) => hash_hmac('sha256', $payload, $this->decode($key)),
            array_values(array_filter($keys, fn (string $key): bool => $key !== '')));
        if (array_pop($signatures) !== $current) {
            return [];
        }
        return array_reverse($signatures);
    }

    /** Like Encryptor::decodeKey(): keys made by Magento 2.4.7+ are stored as "base64" + the encoded key. */
    private function decode(string $key): string
    {
        return str_starts_with($key, 'base64') ? (string)base64_decode(substr($key, 6)) : $key;
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
