<?php
declare(strict_types=1);

namespace CommerceKings\Consent\Test\Unit\Controller;

use CommerceKings\Consent\Controller\Grant\Index as Grant;
use CommerceKings\Consent\Controller\Withdraw\Index as Withdraw;
use CommerceKings\Consent\Api\ConsentInterface;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\Controller\Result\Json;
use Magento\Framework\Controller\Result\JsonFactory;
use Magento\Framework\Data\Form\FormKey\Validator;
use Magento\Framework\App\Action\Context;
use PHPUnit\Framework\TestCase;

class PurposeInputTest extends TestCase
{
    /** @dataProvider refusedRequests */
    #[\PHPUnit\Framework\Attributes\DataProvider('refusedRequests')]
    public function testRefusedInputCannotMutateConsent(string $action, mixed $purpose, mixed $key,
        bool $keyValid, int $expectedStatus): void
    {
        $request = $this->createStub(RequestInterface::class);
        $request->method('getParam')->willReturnCallback(fn ($name) => $name === 'purpose' ? $purpose : $key);
        $validator = $this->createStub(Validator::class);
        $validator->method('validate')->willReturn($keyValid);
        $service = $this->createMock(ConsentInterface::class);
        $service->expects($this->never())->method('grantCurrent');
        $service->expects($this->never())->method('withdrawCurrent');
        $json = $this->createMock(Json::class);
        $json->expects($this->once())->method('setHttpResponseCode')->with($expectedStatus)->willReturnSelf();
        $json->expects($this->once())->method('setData')
            ->with($action === Grant::class ? ['granted' => false] : ['withdrawn' => 0])->willReturnSelf();
        $factory = $this->createStub(JsonFactory::class);
        $factory->method('create')->willReturn($json);
        $this->assertSame($json, (new $action($this->createStub(Context::class), $request, $factory, $service, $validator))->execute());
    }

    public static function refusedRequests(): array
    {
        $cases = [];
        foreach ([Grant::class, Withdraw::class] as $action) {
            foreach (['array' => ['cart_reminder'], 'object' => (object)['purpose' => 'cart_reminder'],
                'null' => null, 'integer' => 42, 'empty' => '', 'oversized' => str_repeat('a', 65)] as $label => $purpose) {
                $cases[$action . '-' . $label] = [$action, $purpose, 'valid', true, 400];
            }
            foreach (['missing' => [null, true], 'array' => [['key'], true], 'invalid' => ['bad', false]] as $label => [$key, $valid]) {
                $cases[$action . '-key-' . $label] = [$action, 'cart_reminder', $key, $valid, 403];
            }
        }
        return $cases;
    }
}
