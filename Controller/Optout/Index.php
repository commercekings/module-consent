<?php
declare(strict_types=1);

namespace CommerceKings\Consent\Controller\Optout;

use CommerceKings\Consent\Exception\WithdrawalBusyException;
use CommerceKings\Consent\Model\WithdrawalLink;
use Magento\Framework\App\Action\Action;
use Magento\Framework\App\Action\Context;
use Magento\Framework\App\Action\HttpGetActionInterface;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\App\CsrfAwareActionInterface;
use CommerceKings\Consent\Controller\RejectsMalformedFormKeys;
use Magento\Framework\App\Request\Http;
use Magento\Framework\Controller\ResultInterface;
use Magento\Framework\Controller\Result\RedirectFactory;
use Magento\Framework\View\Result\PageFactory;
use Magento\Framework\Data\Form\FormKey\Validator;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\Controller\Result\RawFactory;

/**
 * GET confirms; browser POST requires a form key; RFC 8058 POST uses the signed URL without browser state.
 * Extends Action so Magento sets the store and currency context (X-Magento-Vary).
 */
class Index extends Action implements HttpGetActionInterface, HttpPostActionInterface, CsrfAwareActionInterface
{
    use RejectsMalformedFormKeys {
        validateForCsrf as private validateBrowserCsrf;
    }

    public function __construct(
        Context $context,
        private readonly Http $request,
        private readonly WithdrawalLink $links,
        private readonly PageFactory $pageFactory,
        private readonly RedirectFactory $redirectFactory,
        private readonly Validator $formKeyValidator,
        private readonly RawFactory $rawFactory
    ) {
        parent::__construct($context);
    }

    public function execute(): ResultInterface
    {
        if ($this->isOneClickRequest($this->request)) {
            $id = $this->request->getQuery('c');
            $token = $this->request->getQuery('t');
            $type = strtolower(trim(explode(';', (string)$this->request->getHeader('Content-Type'))[0]));
            $valid = in_array($type, ['application/x-www-form-urlencoded', 'multipart/form-data'], true)
                && $this->request->getPostValue() === ['List-Unsubscribe' => 'One-Click']
                && is_string($id) && preg_match('/^[a-f0-9]{32}$/D', $id)
                && is_string($token);
            try {
                $consent = $valid ? $this->links->withdraw($id, $token) : null;
            } catch (WithdrawalBusyException) {
                return $this->rawFactory->create()->setHttpResponseCode(503)
                    ->setHeader('Content-Type', 'text/plain; charset=UTF-8', true)
                    ->setHeader('Retry-After', '5', true)
                    ->setContents('Busy, please retry.');
            }
            return $this->rawFactory->create()->setHttpResponseCode($consent ? 200 : 400)
                ->setHeader('Content-Type', 'text/plain; charset=UTF-8', true)
                ->setHeader('Cache-Control', 'no-store', true)
                ->setContents($consent ? 'OK' : 'Invalid unsubscribe request.');
        }
        $id = $this->request->getParam('c');
        $token = $this->request->getParam('t');
        $post = $this->request->getMethod() === 'POST';
        // Magento also routes HEAD to GET actions. Header inspection must never render interactive content.
        if (!in_array($this->request->getMethod(), ['GET', 'POST'], true)) {
            return $this->redirectFactory->create()->setPath('');
        }
        $key = $this->request->getParam('form_key');
        if ($post && (!is_string($key) || !$this->formKeyValidator->validate($this->request))) {
            return $this->redirectFactory->create()->setPath('');
        }
        $wellFormed = is_string($id) && is_string($token)
            && preg_match('/^[a-f0-9]{32}$/D', $id) && preg_match('/^[a-f0-9]{64}$/D', $token);
        try {
            $consent = !$wellFormed ? null
                : ($post ? $this->links->withdraw($id, $token) : $this->links->getConsent($id, $token));
        } catch (WithdrawalBusyException) {
            // a double click while the first confirmation is written: back to the confirmation, nothing changed
            $this->messageManager->addErrorMessage(__('We could not save this right now. Please try again.'));
            return $this->redirectFactory->create()->setPath('ckconsent/optout', ['_query' => ['c' => $id, 't' => $token]]);
        }
        // Any browser with the signed link sees the result; only the original browser sees the purpose and has its
        // banner synced (Withdrawal view model). A link that no longer verifies (an old encryption key, a consent
        // already cleaned up, a damaged URL) changes nothing and says so, with where to change preferences.
        $page = $this->pageFactory->create();
        $page->getConfig()->getTitle()->set((string)match (true) {
            $consent === null => __('Link expired'),
            $post || $consent->withdrawnAt !== null => __('Consent withdrawn'),
            default => __('Withdraw consent'),
        });
        $page->getConfig()->setRobots('NOINDEX,NOFOLLOW');
        $page->setHeader('Referrer-Policy', 'no-referrer', true);
        return $page;
    }

    public function validateForCsrf(RequestInterface $request): ?bool
    {
        // The signed URL authorizes machine withdrawal; browser confirmation still requires a form key.
        return $request instanceof Http && $this->isOneClickRequest($request)
            ? true : $this->validateBrowserCsrf($request);
    }

    private function isOneClickRequest(Http $request): bool
    {
        return $request->getMethod() === 'POST'
            && $request->getPost('List-Unsubscribe') !== null;
    }
}
