<?php
declare(strict_types=1);

namespace CommerceKings\Consent\Model;

use CommerceKings\Consent\Api\BrowserIdentityInterface;
use CommerceKings\Consent\Api\SourceConfigInterface;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\Stdlib\Cookie\CookieMetadataFactory;
use Magento\Framework\Stdlib\CookieManagerInterface;

/**
 * Random id per browser in cookie ck_consent_browser (HttpOnly, source lifetime), set when a consent is
 * recorded. Only its sha256 is ever stored.
 */
class BrowserIdentity implements BrowserIdentityInterface
{
    public const COOKIE = 'ck_consent_browser';
    private ?string $created = null;

    public function __construct(
        private readonly CookieManagerInterface $cookieManager,
        private readonly CookieMetadataFactory $cookieMetadataFactory,
        private readonly SourceConfigInterface $config,
        private readonly RequestInterface $request
    ) {
    }

    public function hash(): string
    {
        $id = $this->created ?? (string)$this->cookieManager->getCookie(self::COOKIE);
        return preg_match('/^[0-9a-f]{32}$/', $id) ? hash('sha256', $id) : '';
    }

    public function ensure(): string
    {
        if ($this->created === null) {
            // Reissue existing identities too: upgrades apply Secure and the current lifetime
            // without breaking links to previously recorded consents.
            $this->created = $this->hash() === ''
                ? bin2hex(random_bytes(16))
                : (string)$this->cookieManager->getCookie(self::COOKIE);
            $this->cookieManager->setPublicCookie(
                self::COOKIE,
                $this->created,
                $this->cookieMetadataFactory->createPublicCookieMetadata()
                    ->setPath('/')
                    ->setDuration($this->config->getCookieLifetimeDays() * 86400)
                    ->setSecure($this->request->isSecure())
                    ->setHttpOnly(true)
                    ->setSameSite('Lax')
            );
        }
        return $this->hash();
    }
}
