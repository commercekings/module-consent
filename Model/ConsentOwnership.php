<?php
declare(strict_types=1);

namespace CommerceKings\Consent\Model;

use CommerceKings\Consent\Api\BrowserIdentityInterface;
use CommerceKings\Consent\Api\ConsentOwnershipInterface;

/** Compares the browser stored with the consent to the current browser id; never creates a browser id. */
class ConsentOwnership implements ConsentOwnershipInterface
{
    public function __construct(
        private readonly ConsentStore $store,
        private readonly BrowserIdentityInterface $browser
    ) {
    }

    public function isCurrentVisitor(string $consentId): bool
    {
        $consentBrowser = $consentId === '' ? '' : (string)($this->store->load($consentId)['browser_hash'] ?? '');
        $browser = $this->browser->hash();
        return $consentBrowser !== '' && $browser !== '' && hash_equals($consentBrowser, $browser);
    }
}
