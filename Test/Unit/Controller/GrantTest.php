<?php
declare(strict_types=1);

namespace CommerceKings\Consent\Test\Unit\Controller;

use CommerceKings\Consent\Controller\Grant\Index;
use CommerceKings\Consent\Model\ConsentService;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\Controller\Result\Json;
use Magento\Framework\Controller\Result\JsonFactory;
use PHPUnit\Framework\TestCase;

class GrantTest extends TestCase
{
    public function testGrantsThroughTheServiceAndOnlyReportsTheOutcome(): void
    {
        $request = $this->createMock(RequestInterface::class);
        $request->method('getParam')->with('purpose')->willReturn('cart_reminder');
        $data = null;
        $json = $this->createMock(Json::class);
        $json->method('setData')->willReturnCallback(function ($d) use ($json, &$data) { $data = $d; return $json; });
        $factory = $this->createMock(JsonFactory::class);
        $factory->method('create')->willReturn($json);
        $service = $this->createMock(ConsentService::class);
        $service->expects($this->once())->method('grantCurrent')->with('cart_reminder')->willReturn(null);
        (new Index($request, $factory, $service))->execute();
        $this->assertSame(['granted' => false], $data, 'no consent id leaks to the page');
    }
}
