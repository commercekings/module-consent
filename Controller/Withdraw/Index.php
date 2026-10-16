<?php
declare(strict_types=1);

namespace CommerceKings\Consent\Controller\Withdraw;

use CommerceKings\Consent\Api\ConsentInterface;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\App\CsrfAwareActionInterface;
use CommerceKings\Consent\Controller\RejectsMalformedFormKeys;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\Controller\Result\Json;
use Magento\Framework\Controller\Result\JsonFactory;
use Magento\Framework\Data\Form\FormKey\Validator;

/**
 * POST /ckconsent/withdraw?purpose=<code> (valid form key required, including AJAX) - sent by
 * js/consent/provider.js when a purpose is switched off in the source (incl. "reject all", which may
 * reload the page). If the source really says "no" now, this browser's consents for the purpose are
 * withdrawn and their registered module cleanup actions run after commit (with retry on failure).
 * commercekings_consent_withdrawn remains a compatibility notification.
 */
class Index implements HttpPostActionInterface, CsrfAwareActionInterface
{
    use RejectsMalformedFormKeys;
    public function __construct(
        private readonly RequestInterface $request,
        private readonly JsonFactory $jsonFactory,
        private readonly ConsentInterface $consents,
        private readonly Validator $formKeyValidator
    ) {
    }

    public function execute(): Json
    {
        $key = $this->request->getParam('form_key');
        if (!is_string($key) || !$this->formKeyValidator->validate($this->request)) {
            return $this->jsonFactory->create()->setHttpResponseCode(403)->setData(['withdrawn' => 0]);
        }
        $purpose = $this->request->getParam('purpose');
        if (!is_string($purpose) || $purpose === '' || strlen($purpose) > 64) {
            return $this->jsonFactory->create()->setHttpResponseCode(400)->setData(['withdrawn' => 0]);
        }
        $ids = $this->consents->withdrawCurrent($purpose, 'banner');
        return $this->jsonFactory->create()->setData(['withdrawn' => count($ids)]);
    }
}
