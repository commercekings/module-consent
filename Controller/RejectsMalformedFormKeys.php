<?php
declare(strict_types=1);

namespace CommerceKings\Consent\Controller;

use Magento\Framework\App\Request\InvalidRequestException;
use Magento\Framework\App\RequestInterface;

/** Reject non-string keys before Magento's middleware passes them to its string comparison. */
trait RejectsMalformedFormKeys
{
    public function validateForCsrf(RequestInterface $request): ?bool
    {
        $key = $request->getParam('form_key');
        return $key !== null && !is_string($key) ? false : null;
    }

    public function createCsrfValidationException(RequestInterface $request): ?InvalidRequestException
    {
        return null;
    }
}
