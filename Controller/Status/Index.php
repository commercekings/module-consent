<?php
declare(strict_types=1);

namespace CommerceKings\Consent\Controller\Status;

use CommerceKings\Consent\Model\WithdrawnPurposes;
use Magento\Framework\App\Action\Action;
use Magento\Framework\App\Action\Context;
use Magento\Framework\App\Action\HttpGetActionInterface;
use Magento\Framework\Controller\Result\Json;
use Magento\Framework\Controller\Result\JsonFactory;

/**
 * GET /ckconsent/status - asked by the provider when the visitor opens the banner's preferences: which purposes of
 * this browser's current choice were withdrawn elsewhere (the email link opened on another device), so the banner
 * switches them off. Read-only, answered from the browser's own cookies; never cached.
 * Extends Action so Magento sets the store context (the website of the consent).
 */
class Index extends Action implements HttpGetActionInterface
{
    public function __construct(
        Context $context,
        private readonly JsonFactory $jsonFactory,
        private readonly WithdrawnPurposes $withdrawn
    ) {
        parent::__construct($context);
    }

    public function execute(): Json
    {
        return $this->jsonFactory->create()
            ->setHeader('Cache-Control', 'no-store, private', true)
            ->setData(['withdrawn' => $this->withdrawn->forCurrentChoice()]);
    }
}
