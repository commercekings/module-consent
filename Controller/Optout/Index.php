<?php
declare(strict_types=1);

namespace CommerceKings\Consent\Controller\Optout;

use CommerceKings\Consent\Model\WithdrawalLink;
use Magento\Framework\App\Action\HttpGetActionInterface;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\Controller\ResultInterface;
use Magento\Framework\Controller\Result\RedirectFactory;
use Magento\Framework\View\Result\PageFactory;

/** One-click withdrawal for a signed consent id. Repeated clicks confirm without withdrawing new grants. */
class Index implements HttpGetActionInterface
{
    public function __construct(
        private readonly RequestInterface $request,
        private readonly WithdrawalLink $links,
        private readonly PageFactory $pageFactory,
        private readonly RedirectFactory $redirectFactory
    ) {
    }

    public function execute(): ResultInterface
    {
        $consent = $this->links->withdraw(
            (string)$this->request->getParam('c'),
            (string)$this->request->getParam('t')
        );
        if (!$consent) {
            return $this->redirectFactory->create()->setPath('');
        }
        $page = $this->pageFactory->create();
        $page->getConfig()->getTitle()->set((string)__('Consent withdrawn'));
        $page->getConfig()->setRobots('NOINDEX,NOFOLLOW');
        return $page;
    }
}
