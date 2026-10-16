<?php
declare(strict_types=1);

namespace CommerceKings\Consent\Observer;

use Magento\Framework\App\Http\Context;
use Magento\Framework\App\Request\Http;
use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use Magento\Framework\Session\Generic;
use Magento\Store\Model\ScopeInterface;
use Magento\Store\Model\StoreManager;
use Magento\Store\Model\StoreManagerInterface;

/** Initialize the context omitted by Magento's AbstractAction::dispatch plugin for interface-only actions. */
class InitializeStoreContext implements ObserverInterface
{
    public function __construct(
        private readonly Context $context,
        private readonly StoreManagerInterface $stores,
        private readonly Generic $session
    ) {
    }

    public function execute(Observer $observer): void
    {
        $request = $observer->getData('request');
        if (!$request instanceof Http || $request->getRouteName() !== 'ckconsent'
            || $this->context->getValue(StoreManagerInterface::CONTEXT_STORE) !== null
        ) {
            return;
        }

        /** @var \Magento\Store\Model\Store $store */
        $store = $this->stores->getStore();
        // Match Magento\Store\App\Action\Plugin\Context, including store URLs and dedicated store entry points.
        if ($store->isUseStoreInUrl()) {
            $default = $store;
        } elseif ($request->getServerValue(StoreManager::PARAM_RUN_TYPE) === ScopeInterface::SCOPE_STORE) {
            $default = $this->stores->getStore($request->getServerValue(StoreManager::PARAM_RUN_CODE));
        } else {
            $default = $this->stores->getDefaultStoreView();
        }
        /** @var \Magento\Store\Model\Store $default */
        $this->context->setValue(StoreManagerInterface::CONTEXT_STORE, $store->getCode(), $default->getCode());
        $this->context->setValue(
            Context::CONTEXT_CURRENCY,
            $this->session->getData('currency_code') ?: $store->getDefaultCurrencyCode(),
            $default->getDefaultCurrencyCode()
        );
    }
}
