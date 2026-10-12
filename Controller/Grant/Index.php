<?php
declare(strict_types=1);

namespace CommerceKings\Consent\Controller\Grant;

use CommerceKings\Consent\Model\ConsentService;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\Controller\Result\Json;
use Magento\Framework\Controller\Result\JsonFactory;

/**
 * POST /ckconsent/grant?purpose=<code> (form key checked by Magento) - sent by js/consent/provider.js when
 * the visitor switches a purpose ON in the source. Records the choice only if the source really says yes
 * now; it counts even when the same browser withdrew earlier (an explicit new choice).
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
        $consent = $this->consents->grantCurrent((string)$this->request->getParam('purpose'));
        return $this->jsonFactory->create()->setData(['granted' => $consent !== null]);
    }
}
