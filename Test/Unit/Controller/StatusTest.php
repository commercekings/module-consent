<?php
declare(strict_types=1);

namespace CommerceKings\Consent\Test\Unit\Controller;

use CommerceKings\Consent\Controller\Status\Index;
use CommerceKings\Consent\Model\WithdrawnPurposes;
use Magento\Framework\App\Action\Context;
use Magento\Framework\Controller\Result\Json;
use Magento\Framework\Controller\Result\JsonFactory;
use PHPUnit\Framework\TestCase;

class StatusTest extends TestCase
{
    public function testAnswersTheWithdrawnPurposesAndIsNeverCached(): void
    {
        $data = null;
        $headers = [];
        $json = $this->createStub(Json::class);
        $json->method('setData')->willReturnCallback(function ($d) use ($json, &$data) {
            $data = $d;
            return $json;
        });
        $json->method('setHeader')->willReturnCallback(function ($name, $value) use ($json, &$headers) {
            $headers[$name] = $value;
            return $json;
        });
        $factory = $this->createStub(JsonFactory::class);
        $factory->method('create')->willReturn($json);
        $withdrawn = $this->createStub(WithdrawnPurposes::class);
        $withdrawn->method('forCurrentChoice')->willReturn(['cart_reminder']);

        (new Index($this->createStub(Context::class), $factory, $withdrawn))->execute();

        $this->assertSame(['withdrawn' => ['cart_reminder']], $data);
        $this->assertSame('no-store, private', $headers['Cache-Control']);
    }
}
