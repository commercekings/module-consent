<?php
declare(strict_types=1);

namespace CommerceKings\Consent\Test\Unit\Controller;

use CommerceKings\Consent\Controller\RejectsMalformedFormKeys;
use Magento\Framework\App\CsrfAwareActionInterface;
use Magento\Framework\App\RequestInterface;
use PHPUnit\Framework\TestCase;

class FormKeyShapeTest extends TestCase
{
    /** @dataProvider keys */
    #[\PHPUnit\Framework\Attributes\DataProvider('keys')]
    public function testMalformedKeysAreRejectedBeforeMiddlewareStringComparison(mixed $key, ?bool $expected): void
    {
        $action = new class implements CsrfAwareActionInterface {
            use RejectsMalformedFormKeys;
            public function execute(): void {}
        };
        $request = $this->createStub(RequestInterface::class);
        $request->method('getParam')->willReturn($key);
        $this->assertSame($expected, $action->validateForCsrf($request));
        $this->assertNull($action->createCsrfValidationException($request));
    }

    public static function keys(): array
    {
        return ['array' => [['key'], false], 'object' => [(object)['key' => 'x'], false],
            'integer' => [123, false], 'boolean' => [true, false],
            'missing' => [null, null], 'string' => ['key', null]];
    }
}
