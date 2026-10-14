<?php
declare(strict_types=1);

namespace CommerceKings\Consent\Test\Unit\Model;

use CommerceKings\Consent\Model\BrowserIdentity;
use CommerceKings\Consent\Api\SourceConfigInterface;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\Math\Random;
use Magento\Framework\Session\Config\ConfigInterface as SessionConfigInterface;
use CommerceKings\Consent\Test\Unit\TestHelperTrait;
use Magento\Framework\Stdlib\Cookie\CookieMetadataFactory;
use Magento\Framework\Stdlib\Cookie\PublicCookieMetadata;
use Magento\Framework\Stdlib\CookieManagerInterface;
use PHPUnit\Framework\TestCase;

class BrowserIdentityTest extends TestCase
{
    use TestHelperTrait;

    private array $set = [];
    private array $meta = [];

    private function identity(mixed $cookie, bool $secure = true, int $days = 90, string $path = '/', string $domain = ''): BrowserIdentity
    {
        $manager = $this->createMock(CookieManagerInterface::class);
        $manager->method('getCookie')->with('ck_consent_browser')->willReturn($cookie);
        $manager->method('setPublicCookie')->willReturnCallback(function ($name, $value) { $this->set[] = [$name, $value]; });
        $metadata = $this->mockWith(PublicCookieMetadata::class, [
            'setPath' => function ($v) use (&$metadata) { $this->meta['path'] = $v; return $metadata; },
            'setDomain' => function ($v) use (&$metadata) { $this->meta['domain'] = $v; return $metadata; },
            'setDuration' => function ($v) use (&$metadata) { $this->meta['duration'] = $v; return $metadata; },
            'setSecure' => function ($v) use (&$metadata) { $this->meta['secure'] = $v; return $metadata; },
            'setHttpOnly' => function ($v) use (&$metadata) { $this->meta['httpOnly'] = $v; return $metadata; },
            'setSameSite' => function ($v) use (&$metadata) { $this->meta['sameSite'] = $v; return $metadata; },
        ]);
        $factory = $this->createStub(CookieMetadataFactory::class);
        $factory->method('createPublicCookieMetadata')->willReturn($metadata);
        $config = $this->createStub(SourceConfigInterface::class);
        $config->method('getCookieLifetimeDays')->willReturn($days);
        $request = $this->createStub(RequestInterface::class);
        $request->method('isSecure')->willReturn($secure);
        $sessionConfig = $this->createStub(SessionConfigInterface::class);
        $sessionConfig->method('getCookiePath')->willReturn($path);
        $sessionConfig->method('getCookieDomain')->willReturn($domain);
        return new BrowserIdentity($manager, $factory, $config, $request, new Random(), $sessionConfig);
    }

    public function testNoOrInvalidCookieMeansNoId(): void
    {
        $this->assertSame('', $this->identity(null)->hash());
        $this->assertSame('', $this->identity('not-hex')->hash());
        $this->assertSame('', $this->identity(['bad'])->hash());
        $this->assertNotSame('', $this->identity(['bad'])->ensure());
    }

    public function testExistingCookieIsHashedAndKept(): void
    {
        $id = str_repeat('ab', 16);
        $identity = $this->identity($id);
        $this->assertSame(hash('sha256', $id), $identity->ensure());
        $this->assertSame([['ck_consent_browser', $id]], $this->set, 'existing identities get the current security attributes without changing id');
        $this->assertTrue($this->meta['secure']);
        $identity->ensure();
        $this->assertCount(1, $this->set);
    }

    public function testHttpDevelopmentUsesConfiguredLifetimeWithoutSecure(): void
    {
        $this->identity(null, false, 30)->ensure();
        $this->assertFalse($this->meta['secure']);
        $this->assertSame(30 * 86400, $this->meta['duration']);
    }

    public function testEnsureCreatesOneHttpOnlyCookie(): void
    {
        $identity = $this->identity(null);
        $hash = $identity->ensure();
        [$name, $value] = $this->set[0];
        $this->assertMatchesRegularExpression('/^[a-f0-9]{32}$/D', $value);
        $this->assertSame('ck_consent_browser', $name);
        $this->assertSame(hash('sha256', $value), $hash);
        $this->assertSame(['path' => '/', 'domain' => '', 'duration' => 90 * 86400, 'secure' => true, 'httpOnly' => true, 'sameSite' => 'Lax'], $this->meta);
        $this->assertSame($hash, $identity->ensure());
        $this->assertCount(1, $this->set);
    }

    public function testReissuedIdentityUsesConfiguredScopeAndConsentLifetime(): void
    {
        $id = str_repeat('ab', 16);
        $this->identity($id, true, 45, '/shop/', '.example.test')->ensure();
        $this->assertSame([['ck_consent_browser', $id]], $this->set);
        $this->assertSame('/shop/', $this->meta['path']);
        $this->assertSame('.example.test', $this->meta['domain']);
        $this->assertSame(45 * 86400, $this->meta['duration']);
    }
}
