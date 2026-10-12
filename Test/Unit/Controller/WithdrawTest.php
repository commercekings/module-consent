<?php
declare(strict_types=1);

namespace CommerceKings\Consent\Test\Unit\Controller;

use CommerceKings\Consent\Controller\Withdraw\Index;
use CommerceKings\Consent\Model\ConsentService;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\Controller\Result\Json;
use Magento\Framework\Controller\Result\JsonFactory;
use Magento\Framework\Data\Form\FormKey\Validator;
use PHPUnit\Framework\TestCase;

class WithdrawTest extends TestCase
{
    public function testWithdrawsThroughTheServiceAsBanner(): void
    {
        $request = $this->createStub(RequestInterface::class);
        $request->method('getParam')->willReturnCallback(fn ($key) => $key === 'purpose' ? 'cart_reminder' : 'valid-key');
        $validator = $this->createStub(Validator::class);
        $validator->method('validate')->willReturn(true);
        $data = null;
        $json = $this->createStub(Json::class);
        $json->method('setData')->willReturnCallback(function ($d) use ($json, &$data) { $data = $d; return $json; });
        $factory = $this->createStub(JsonFactory::class);
        $factory->method('create')->willReturn($json);
        $service = $this->createMock(ConsentService::class);
        $service->expects($this->once())->method('withdrawCurrent')->with('cart_reminder', 'banner')->willReturn(['a', 'b']);
        (new Index($request, $factory, $service, $validator))->execute();
        $this->assertSame(['withdrawn' => 2], $data);
    }
}
