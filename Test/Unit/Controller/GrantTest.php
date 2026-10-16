<?php
declare(strict_types=1);

namespace CommerceKings\Consent\Test\Unit\Controller;

use CommerceKings\Consent\Controller\Grant\Index;
use CommerceKings\Consent\Api\ConsentInterface;
use CommerceKings\Consent\Api\Data\Consent;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\Controller\Result\Json;
use Magento\Framework\Controller\Result\JsonFactory;
use Magento\Framework\Data\Form\FormKey\Validator;
use PHPUnit\Framework\TestCase;

class GrantTest extends TestCase
{
    #[\PHPUnit\Framework\Attributes\DataProvider('grantOutcomes')]
    public function testGrantsThroughTheServiceAndOnlyReportsTheOutcome(bool $granted): void
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
        $service = $this->createMock(ConsentInterface::class);
        $consent = $granted ? new Consent('private-id', 'cart_reminder', 1, 'source', 'reference', 'source-time',
            '1', '1', 'granted-at', 'expires-at') : null;
        $service->expects($this->once())->method('grantCurrent')->with('cart_reminder')->willReturn($consent);
        (new Index($request, $factory, $service, $validator))->execute();
        $this->assertSame(['granted' => $granted], $data, 'no consent id leaks to the page');
    }

    public static function grantOutcomes(): array
    {
        return ['accepted' => [true], 'refused' => [false]];
    }
}
