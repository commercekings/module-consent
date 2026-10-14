<?php
declare(strict_types=1);

namespace CommerceKings\Consent\Controller\Optout;

use CommerceKings\Consent\Api\BrowserIdentityInterface;
use CommerceKings\Consent\Model\ConsentStore;
use CommerceKings\Consent\Model\WithdrawalLink;
use Magento\Framework\App\Action\HttpGetActionInterface;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\App\CsrfAwareActionInterface;
use CommerceKings\Consent\Controller\RejectsMalformedFormKeys;
use Magento\Framework\App\Request\Http;
use Magento\Framework\Controller\ResultInterface;
use Magento\Framework\Controller\Result\RedirectFactory;
use Magento\Framework\View\Result\PageFactory;
use Magento\Framework\Data\Form\FormKey\Validator;

/** GET is read-only; an explicit, form-key POST withdraws. Other browsers return home after confirming. */
class Index implements HttpGetActionInterface, HttpPostActionInterface, CsrfAwareActionInterface
{
    use RejectsMalformedFormKeys;

    public function __construct(
        private readonly Http $request,
        private readonly WithdrawalLink $links,
        private readonly PageFactory $pageFactory,
        private readonly RedirectFactory $redirectFactory,
        private readonly BrowserIdentityInterface $browser,
        private readonly ConsentStore $store,
        private readonly Validator $formKeyValidator
    ) {
    }

    public function execute(): ResultInterface
    {
        $id = $this->request->getParam('c');
        $token = $this->request->getParam('t');
        $post = $this->request->getMethod() === 'POST';
        // Magento also routes HEAD to GET actions. Header inspection must never render interactive content.
        if (!in_array($this->request->getMethod(), ['GET', 'POST'], true) || !is_string($id) || !is_string($token)
            || !preg_match('/^[a-f0-9]{32}$/D', $id) || !preg_match('/^[a-f0-9]{64}$/D', $token)
        ) {
            return $this->redirectFactory->create()->setPath('');
        }
        $key = $this->request->getParam('form_key');
        if ($post && (!is_string($key) || !$this->formKeyValidator->validate($this->request))) {
            return $this->redirectFactory->create()->setPath('');
        }
        $consent = $post ? $this->links->withdraw($id, $token) : $this->links->getConsent($id, $token);
        if (!$consent) {
            return $this->redirectFactory->create()->setPath('');
        }
        $row = $this->store->load($id);
        $hash = $this->browser->hash();
        $owner = $row && $hash !== '' && hash_equals((string)$row['browser_hash'], $hash);
        if (!$owner && ($post || $consent->withdrawnAt !== null)) {
            return $this->redirectFactory->create()->setPath('');
        }
        $page = $this->pageFactory->create();
        $page->getConfig()->getTitle()->set((string)($post || $consent->withdrawnAt !== null
            ? __('Consent withdrawn') : __('Withdraw consent')));
        $page->getConfig()->setRobots('NOINDEX,NOFOLLOW');
        $page->setHeader('Referrer-Policy', 'no-referrer', true);
        return $page;
    }
}
