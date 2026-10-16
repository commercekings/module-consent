<?php
declare(strict_types=1);

namespace CommerceKings\Consent\Observer;

use Magento\Framework\App\Request\Http as Request;
use Magento\Framework\App\Response\Http as Response;
use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;

/**
 * Keep X-Magento-Vary as the last page set it on our endpoints. Magento sets the store and currency context only
 * for controllers extending the deprecated AbstractAction, so ours would rewrite the cookie without them;
 * PageCache\Model\App\Response\HttpPlugin skips responses marked NotCacheable.
 */
class KeepVaryCookie implements ObserverInterface
{
    public function execute(Observer $observer): void
    {
        $request = $observer->getData('request');
        $response = $observer->getData('response');
        if ($request instanceof Request && $request->getRouteName() === 'ckconsent' && $response instanceof Response) {
            $response->setMetadata('NotCacheable', true);
        }
    }
}
