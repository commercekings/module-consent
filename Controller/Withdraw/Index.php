<?php
declare(strict_types=1);

namespace CommerceKings\Consent\Controller\Withdraw;

use CommerceKings\Consent\Model\ConsentService;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\Controller\Result\Json;
use Magento\Framework\Controller\Result\JsonFactory;

/**
 * POST /ckconsent/withdraw?purpose=<code> (form key checked by Magento) - sent by
 * js/consent/provider.js when a purpose is switched off in the source (incl. "reject all", which may
 * reload the page). If the source really says "no" now, this browser's consents for the purpose are
 * withdrawn and commercekings_consent_withdrawn fires (modules using the purpose clean up their data).
 */
class Index implements HttpPostActionInterface
{
    public function __construct(
        private readonly RequestInterface $request,
        private readonly JsonFactory $jsonFactory,
        private readonly ConsentService $consents
    ) {
    }

    public function execute(): Json
    {
        $ids = $this->consents->withdrawCurrent((string)$this->request->getParam('purpose'), 'banner');
        return $this->jsonFactory->create()->setData(['withdrawn' => count($ids)]);
    }
}
