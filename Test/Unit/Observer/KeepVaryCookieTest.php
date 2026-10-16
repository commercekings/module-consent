<?php
declare(strict_types=1);

namespace CommerceKings\Consent\Test\Unit\Observer;

use CommerceKings\Consent\Observer\KeepVaryCookie;
use Magento\Framework\App\Request\Http as Request;
use Magento\Framework\App\Response\Http as Response;
use Magento\Framework\Event\Observer;
use PHPUnit\Framework\TestCase;

class KeepVaryCookieTest extends TestCase
{
    /** @return array<string, array{?string, bool}> */
    public static function routes(): array
    {
        return [
            'consent endpoint' => ['ckconsent', true],
            'unrelated page' => ['cms', false],
            'unrelated endpoint' => ['customer', false],
            'missing request' => [null, false],
        ];
    }

    /** @dataProvider routes */
    #[\PHPUnit\Framework\Attributes\DataProvider('routes')]
    public function testOnlyConsentResponsesSkipTheVaryCookie(?string $route, bool $skipped): void
    {
        $request = $route === null ? null : $this->createStub(Request::class);
        $request?->method('getRouteName')->willReturn($route);
        $response = $this->createMock(Response::class);
        $response->expects($skipped ? $this->once() : $this->never())
            ->method('setMetadata')->with('NotCacheable', true);

        (new KeepVaryCookie())->execute(new Observer(['request' => $request, 'response' => $response]));
    }
}
