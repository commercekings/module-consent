<?php
declare(strict_types=1);

namespace CommerceKings\Consent\Test\Unit\Model;

use CommerceKings\Consent\Api\BrowserIdentityInterface;
use CommerceKings\Consent\Api\Data\SourceChoice;
use CommerceKings\Consent\Api\PurposeRegistryInterface;
use CommerceKings\Consent\Api\SourceInterface;
use CommerceKings\Consent\Model\ConfiguredSource;
use CommerceKings\Consent\Model\ConsentStore;
use CommerceKings\Consent\Model\WithdrawnPurposes;
use Magento\Store\Api\Data\WebsiteInterface;
use Magento\Store\Model\StoreManagerInterface;
use PHPUnit\Framework\TestCase;

class WithdrawnPurposesTest extends TestCase
{
    private string $hash = 'browser';
    private ?SourceChoice $choice;
    private bool $hasSource = true;
    /** @var array<int, array> */
    private array $lookups = [];

    protected function setUp(): void
    {
        $this->choice = new SourceChoice('choice', '2026-10-18 10:00:00', '1', ['cart_reminder', 'analytics', 'retired']);
    }

    private function purposes(): WithdrawnPurposes
    {
        $browser = $this->createStub(BrowserIdentityInterface::class);
        $browser->method('hash')->willReturnCallback(fn () => $this->hash);
        $source = $this->createStub(SourceInterface::class);
        $source->method('getCode')->willReturn('vanilla');
        $source->method('getChoice')->willReturnCallback(fn () => $this->choice);
        $sources = $this->createStub(ConfiguredSource::class);
        $sources->method('get')->willReturnCallback(fn () => $this->hasSource ? $source : null);
        $purposes = $this->createStub(PurposeRegistryInterface::class);
        $purposes->method('getCodes')->willReturn(['cart_reminder', 'analytics']);
        $store = $this->createStub(ConsentStore::class);
        $store->method('findWithdrawnPurposes')->willReturnCallback(function (...$args) {
            $this->lookups[] = $args;
            return ['cart_reminder'];
        });
        $website = $this->createStub(WebsiteInterface::class);
        $website->method('getId')->willReturn(2);
        $stores = $this->createStub(StoreManagerInterface::class);
        $stores->method('getWebsite')->willReturn($website);
        return new WithdrawnPurposes($browser, $sources, $purposes, $store, $stores);
    }

    public function testListsTheWithdrawnPurposesOfThisBrowsersCurrentChoice(): void
    {
        $this->assertSame(['cart_reminder'], $this->purposes()->forCurrentChoice());
        $this->assertSame([[['cart_reminder', 'analytics'], 'browser', 2, 'vanilla', 'choice', '2026-10-18 10:00:00']],
            array_map(fn ($args) => [array_values($args[0]), ...array_slice($args, 1)], $this->lookups),
            'only declared purposes the choice accepts, for exactly this choice');
    }

    public function testNoDatabaseLookupWithoutABrowserIdentityChoiceOrSource(): void
    {
        $this->hash = '';
        $this->assertSame([], $this->purposes()->forCurrentChoice());
        $this->hash = 'browser';
        $this->choice = null;
        $this->assertSame([], $this->purposes()->forCurrentChoice());
        $this->choice = new SourceChoice('choice', '2026-10-18 10:00:00', '1', []);
        $this->assertSame([], $this->purposes()->forCurrentChoice(), 'a choice that accepts nothing');
        $this->choice = new SourceChoice('choice', '2026-10-18 10:00:00', '1', ['cart_reminder']);
        $this->hasSource = false;
        $this->assertSame([], $this->purposes()->forCurrentChoice());
        $this->assertSame([], $this->lookups);
    }
}
