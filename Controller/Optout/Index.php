<?php
declare(strict_types=1);

namespace CommerceKings\Consent\Controller\Optout;

use CommerceKings\Consent\Api\BrowserIdentityInterface;
use CommerceKings\Consent\Model\ConsentStore;
use CommerceKings\Consent\Model\WithdrawalLink;
use Magento\Framework\App\Action\HttpGetActionInterface;
use Magento\Framework\App\Request\Http;
use Magento\Framework\Controller\ResultInterface;
use Magento\Framework\Controller\Result\RedirectFactory;
use Magento\Framework\View\Result\PageFactory;

/** Signed links withdraw from any browser; only the original browser sees confirmation and preference sync. */
class Index implements HttpGetActionInterface
{
    public function __construct(
        private readonly Http $request,
        private readonly WithdrawalLink $links,
        private readonly PageFactory $pageFactory,
        private readonly RedirectFactory $redirectFactory,
        private readonly BrowserIdentityInterface $browser,
        private readonly ConsentStore $store
    ) {
    }

    public function execute(): ResultInterface
    {
        $id = $this->request->getParam('c');
        $token = $this->request->getParam('t');
        // Magento also routes HEAD to GET actions. Header inspection must never withdraw or sync a banner.
        if ($this->request->getMethod() !== 'GET' || !is_string($id) || !is_string($token)
            || !preg_match('/^[a-f0-9]{32}$/D', $id) || !preg_match('/^[a-f0-9]{64}$/D', $token)
        ) {
            return $this->redirectFactory->create()->setPath('');
        }
        $consent = $this->links->withdraw($id, $token);
        if (!$consent) {
            return $this->redirectFactory->create()->setPath('');
        }
        $row = $this->store->load($id);
        $hash = $this->browser->hash();
        if (!$row || $hash === '' || !hash_equals((string)$row['browser_hash'], $hash)) {
            return $this->redirectFactory->create()->setPath('');
        }
        $page = $this->pageFactory->create();
        $page->getConfig()->getTitle()->set((string)__('Consent withdrawn'));
        $page->getConfig()->setRobots('NOINDEX,NOFOLLOW');
        return $page;
    }
}
