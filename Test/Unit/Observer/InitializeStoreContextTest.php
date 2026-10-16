<?php
declare(strict_types=1);

namespace CommerceKings\Consent\Test\Unit\Observer;

use CommerceKings\Consent\Observer\InitializeStoreContext;
use Magento\Framework\App\Http\Context;
use Magento\Framework\App\Request\Http;
use Magento\Framework\Event\Observer;
use Magento\Framework\Serialize\Serializer\Json;
use Magento\Framework\Session\Generic;
use Magento\Store\Model\Store;
use Magento\Store\Model\StoreManager;
use Magento\Store\Model\StoreManagerInterface;
use PHPUnit\Framework\TestCase;

class InitializeStoreContextTest extends TestCase
{
    /** @return array<string, array{bool, ?string, ?string, string, string, string}> */
    public static function scopes(): array
    {
        return [
            'secondary view' => [false, null, null, 'luma', 'default', 'EUR'],
            'secondary view with chosen currency' => [false, null, 'USD', 'luma', 'default', 'USD'],
            'default view' => [false, null, null, 'default', 'default', 'EUR'],
            'store code in URL' => [true, null, null, 'luma', 'luma', 'EUR'],
            'store URL with chosen currency' => [true, null, 'USD', 'luma', 'luma', 'USD'],
            'dedicated store entry point' => [false, 'store', null, 'luma', 'luma', 'EUR'],
            'dedicated website entry point' => [false, 'website', null, 'luma', 'default', 'EUR'],
        ];
    }

    /** @dataProvider scopes */
    #[\PHPUnit\Framework\Attributes\DataProvider('scopes')]
    public function testStoreAndCurrencyFollowMagentoContextDefaults(
        bool $storeInUrl,
        ?string $runType,
        ?string $chosenCurrency,
        string $storeCode,
        string $defaultCode,
        string $currency
    ): void {
        $context = new Context([], [], new Json());
        $context->setValue('customer_group', '2', '0');
        $context->setValue('custom_segment', 'trade', 'retail');
        $store = $this->createStub(Store::class);
        $store->method('getCode')->willReturn($storeCode);
        $store->method('isUseStoreInUrl')->willReturn($storeInUrl);
        $store->method('getDefaultCurrencyCode')->willReturn('EUR');
        $default = $this->createStub(Store::class);
        $default->method('getCode')->willReturn('default');
        $default->method('getDefaultCurrencyCode')->willReturn('EUR');
        $stores = $this->createMock(StoreManagerInterface::class);
        $stores->method('getStore')->willReturnCallback(function ($code = null) use ($store) {
            $this->assertTrue($code === null || $code === 'luma');
            return $store;
        });
        $stores->expects(!$storeInUrl && $runType !== 'store' ? $this->once() : $this->never())
            ->method('getDefaultStoreView')->willReturn($default);
        $session = $this->createMock(Generic::class);
        $session->expects($this->once())->method('getData')->with('currency_code')->willReturn($chosenCurrency);
        $request = $this->createStub(Http::class);
        $request->method('getRouteName')->willReturn('ckconsent');
        $request->method('getServerValue')->willReturnCallback(fn ($name) => [
            StoreManager::PARAM_RUN_TYPE => $runType,
            StoreManager::PARAM_RUN_CODE => 'luma',
        ][$name]);

        (new InitializeStoreContext($context, $stores, $session))->execute(new Observer(['request' => $request]));

        $this->assertSame([
            'data' => [
                'customer_group' => '2', 'custom_segment' => 'trade',
                StoreManagerInterface::CONTEXT_STORE => $storeCode, Context::CONTEXT_CURRENCY => $currency,
            ],
            'default' => [
                'customer_group' => '0', 'custom_segment' => 'retail',
                StoreManagerInterface::CONTEXT_STORE => $defaultCode, Context::CONTEXT_CURRENCY => 'EUR',
            ],
        ], $context->toArray());
    }

    /** @return array<string, array{?string, bool}> */
    public static function untouchedContexts(): array
    {
        return [
            'unrelated page' => ['cms', false],
            'unrelated endpoint' => ['customer', false],
            'missing request' => [null, false],
            'already initialized' => ['ckconsent', true],
        ];
    }

    /** @dataProvider untouchedContexts */
    #[\PHPUnit\Framework\Attributes\DataProvider('untouchedContexts')]
    public function testUnrelatedOrInitializedContextsDoNotLoadStoresOrSession(?string $route, bool $initialized): void
    {
        $context = new Context([], [], new Json());
        if ($initialized) {
            $context->setValue(StoreManagerInterface::CONTEXT_STORE, 'luma', 'default');
            $context->setValue(Context::CONTEXT_CURRENCY, 'USD', 'EUR');
        }
        $before = $context->toArray();
        $stores = $this->createMock(StoreManagerInterface::class);
        $stores->expects($this->never())->method('getStore');
        $stores->expects($this->never())->method('getDefaultStoreView');
        $session = $this->createMock(Generic::class);
        $session->expects($this->never())->method('getData');
        $request = $route === null ? null : $this->createStub(Http::class);
        $request?->method('getRouteName')->willReturn($route);

        (new InitializeStoreContext($context, $stores, $session))->execute(new Observer(['request' => $request]));

        $this->assertSame($before, $context->toArray());
    }
}
