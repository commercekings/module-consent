<?php
declare(strict_types=1);

namespace CommerceKings\Consent\Test\Unit\Model;

use CommerceKings\Consent\Api\SourceInterface;
use CommerceKings\Consent\Model\Config;
use CommerceKings\Consent\Model\Config\Source\Sources;
use CommerceKings\Consent\Model\ConfiguredSource;
use CommerceKings\Consent\Model\PurposePool;
use CommerceKings\Consent\Model\SourcePool;
use PHPUnit\Framework\TestCase;

class SourcePoolTest extends TestCase
{
    private function source(string $code): SourceInterface
    {
        $source = $this->createStub(SourceInterface::class);
        $source->method('getCode')->willReturn($code);
        $source->method('getClientConfig')->willReturn(['cookie' => $code . '-cookie']);
        return $source;
    }

    private function pool(): SourcePool
    {
        // what di.xml builds from the source modules
        return new SourcePool([
            'vanilla-cookieconsent' => ['label' => 'Cookie banner', 'provider' => $this->source('vanilla-cookieconsent'), 'adapter' => 'A_B/js/vanilla'],
            'other' => ['label' => 'Other banner', 'provider' => $this->source('other'), 'adapter' => 'A_C/js/other'],
        ]);
    }

    private function configured(string $chosen, ?SourcePool $pool = null): ConfiguredSource
    {
        $config = $this->createStub(Config::class);
        $config->method('getSourceCode')->willReturn($chosen);
        $config->method('getCookieLifetimeDays')->willReturn(90);
        $config->method('getRecordedPurposes')->willReturn(['cart_reminder']);
        return new ConfiguredSource($pool ?? $this->pool(), new PurposePool(['cart_reminder' => ['label' => 'Cart reminder'], 'newsletter' => []]), $config);
    }

    public function testDropdownOffersNoneAndEveryRegisteredSource(): void
    {
        $this->assertSame(['', 'vanilla-cookieconsent', 'other'], array_column((new Sources($this->pool()))->toOptionArray(), 'value'));
        $this->assertSame([''], array_column((new Sources(new SourcePool([])))->toOptionArray(), 'value'));
    }

    public function testChosenSourceAndItsClientConfig(): void
    {
        $this->assertSame('other', $this->configured('other')->get()->getCode());
        $this->assertSame(
            ['source' => 'other', 'adapter' => 'A_C/js/other', 'purposes' => ['cart_reminder', 'newsletter'], 'recorded' => ['cart_reminder'], 'cookieLifetimeDays' => 90, 'cookie' => 'other-cookie'],
            $this->configured('other')->getClientConfig()
        );
    }

    /** @dataProvider noSource */
    #[\PHPUnit\Framework\Attributes\DataProvider('noSource')]
    public function testUnknownOrNoChoiceFailsClosed(string $chosen): void
    {
        $this->assertNull($this->configured($chosen)->get());
        $this->assertSame(['source' => '', 'adapter' => '', 'purposes' => []], $this->configured($chosen)->getClientConfig());
    }

    public static function noSource(): array
    {
        return ['none chosen' => [''], 'chosen module removed' => ['removed-cmp']];
    }

    public function testPurposeRegistry(): void
    {
        $purposes = new PurposePool(['cart_reminder' => ['label' => 'Cart reminder', 'terms_revision' => '3'], 'newsletter' => []]);
        $this->assertSame(['cart_reminder', 'newsletter'], $purposes->getCodes());
        $this->assertTrue($purposes->isDeclared('cart_reminder'));
        $this->assertFalse($purposes->isDeclared('x'));
        $this->assertSame('Cart reminder', $purposes->getLabel('cart_reminder'));
        $this->assertSame('newsletter', $purposes->getLabel('newsletter'));
        $this->assertSame('3', $purposes->getTermsRevision('cart_reminder'));
        $this->assertSame('1', $purposes->getTermsRevision('newsletter'), 'default terms revision');
    }
}
