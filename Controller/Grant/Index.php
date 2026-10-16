<?php
declare(strict_types=1);

namespace CommerceKings\Consent\Controller\Grant;

use CommerceKings\Consent\Api\ConsentInterface;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\App\CsrfAwareActionInterface;
use CommerceKings\Consent\Controller\RejectsMalformedFormKeys;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\Controller\Result\Json;
use Magento\Framework\Controller\Result\JsonFactory;
use Magento\Framework\Data\Form\FormKey\Validator;

/**
 * POST /ckconsent/grant?purpose=<code> (valid form key required, including AJAX) - sent by the provider when
 * the visitor switches a purpose ON in the source. Records the choice only if the source really says yes
 * now; it counts even when the same browser withdrew earlier (an explicit new choice).
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
            return $this->jsonFactory->create()->setHttpResponseCode(403)->setData(['granted' => false]);
        }
        $purpose = $this->request->getParam('purpose');
        if (!is_string($purpose) || $purpose === '' || strlen($purpose) > 64) {
            return $this->jsonFactory->create()->setHttpResponseCode(400)->setData(['granted' => false]);
        }
        $consent = $this->consents->grantCurrent($purpose);
        return $this->jsonFactory->create()->setData(['granted' => $consent !== null]);
    }
}
