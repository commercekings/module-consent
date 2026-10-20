<?php
declare(strict_types=1);

namespace CommerceKings\Consent\Test\Unit\Model;

use CommerceKings\Consent\Api\BrowserIdentityInterface;
use CommerceKings\Consent\Api\Data\SourceChoice;
use CommerceKings\Consent\Api\SourceInterface;
use CommerceKings\Consent\Model\CategoryMapper;
use CommerceKings\Consent\Model\Config;
use CommerceKings\Consent\Model\ConfiguredSource;
use CommerceKings\Consent\Model\ConsentService;
use CommerceKings\Consent\Model\PurposePool;
use CommerceKings\Consent\Model\WithdrawalCleanup;
use CommerceKings\Consent\Model\WithdrawalCleanupStore;
use CommerceKings\Consent\Model\WithdrawalPool;
use CommerceKings\Consent\Model\WordingSnapshot;
use CommerceKings\Consent\Test\Unit\InMemoryConsentStore;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\DB\Adapter\AdapterInterface;
use Magento\Framework\Event\ManagerInterface;
use Magento\Framework\Lock\LockManagerInterface;
use Magento\Framework\Math\Random;
use Magento\Store\Api\Data\StoreInterface;
use Magento\Store\Model\StoreManagerInterface;
use Magento\Store\Model\Website;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * Which settings decide whether a purpose is active (external review 3, ER3-4): the store view a visitor
 * uses for a grant or withdrawal, and per website "active in any of its store views" for validity and the
 * guard - whatever store a cron or consumer runs in. Website 1 has store view 1, website 2 store views 3, 4.
 */
class ConsentScopeTest extends TestCase
{
    private const GA = 'google/gtag/analytics4/active';
    private const BRIDGE = 'commercekings_consent_notification/general/enabled';
    private const WEBSITE_OF = [1 => 1, 3 => 2, 4 => 2];

    /** @var array<string, bool> "default", "websites/<id>" or "stores/<id>" => flag, per path */
    private array $flags = [];
    private int $currentStore = 1;
    private InMemoryConsentStore $store;

    protected function setUp(): void
    {
        $this->store = new class extends InMemoryConsentStore {
            public function tableName(): string
            {
                return 'ck_consent';
            }
        };
        $this->store->now = gmdate('Y-m-d H:i:s');
        $db = $this->createStub(AdapterInterface::class);
        $db->method('quoteInto')->willReturnCallback(fn ($sql, $value) => str_replace('?', "'" . $value . "'", $sql));
        $db->method('quoteIdentifier')->willReturnArgument(0);
        $db->method('getTransactionLevel')->willReturn(0);
        $this->store->db = $db;
    }

    /** Magento's inheritance: a store view's value, else its website's, else the default. */
    private function flag(string $path, string $type, int|string|null $code): bool
    {
        $values = $this->flags[$path] ?? [];
        $store = $type === 'store' || $type === 'stores' ? (int)($code ?? $this->currentStore) : null;
        $website = $store !== null ? self::WEBSITE_OF[$store] : (int)$code;
        foreach (array_filter([$store !== null ? 'stores/' . $store : null, 'websites/' . $website, 'default']) as $key) {
            if (array_key_exists($key, $values)) {
                return $values[$key];
            }
        }
        return false;
    }

    private function storeManager(): StoreManagerInterface
    {
        $stores = $this->createStub(StoreManagerInterface::class);
        $stores->method('getStore')->willReturnCallback(function ($id = null) {
            $store = $this->createStub(StoreInterface::class);
            $store->method('getId')->willReturn($id ?? $this->currentStore);
            $store->method('getWebsiteId')->willReturn(self::WEBSITE_OF[$id ?? $this->currentStore]);
            return $store;
        });
        $website = function (int $id): Website {
            $website = $this->createStub(Website::class);
            $website->method('getId')->willReturn($id);
            $website->method('getStoreIds')->willReturn(array_keys(array_filter(self::WEBSITE_OF, fn ($w) => $w === $id)));
            return $website;
        };
        $stores->method('getWebsite')->willReturnCallback(fn ($id = null) => $website((int)($id ?? self::WEBSITE_OF[$this->currentStore])));
        $stores->method('getWebsites')->willReturnCallback(fn () => [$website(1), $website(2)]);
        $stores->method('getStores')->willReturnCallback(fn () => array_map(function (int $id) {
            $store = $this->createStub(StoreInterface::class);
            $store->method('getId')->willReturn($id);
            $store->method('getWebsiteId')->willReturn(self::WEBSITE_OF[$id]);
            return $store;
        }, array_keys(self::WEBSITE_OF)));
        return $stores;
    }

    private function pool(): PurposePool
    {
        $scope = $this->createStub(ScopeConfigInterface::class);
        $scope->method('isSetFlag')->willReturnCallback(fn ($path, $type = 'default', $code = null) => $this->flag($path, $type, $code));
        return new PurposePool([
            'google_analytics' => ['record' => 'optional', 'active_if' => ['ga4' => self::GA]],
            'cart_reminder' => ['active_if' => ['bridge' => self::BRIDGE]],
        ], $scope, $this->storeManager());
    }

    /** @param string[] $accepted the categories the visitor's choice accepts */
    private function service(PurposePool $purposes, array $accepted = ['analytics', 'marketing']): ConsentService
    {
        $mapper = new CategoryMapper($purposes);
        $categories = ['analytics' => ['purposes' => ['google_analytics']], 'marketing' => ['purposes' => ['cart_reminder']]];
        // a source like the vanilla banner: its categories (and so what its choice grants) built by the CategoryMapper
        $source = new class ($mapper, $categories, $accepted) implements SourceInterface {
            public function __construct(private CategoryMapper $mapper, private array $categories, private array $accepted)
            {
            }

            public function getCode(): string
            {
                return 'banner';
            }

            public function getVersion(): string
            {
                return '1';
            }

            public function getCategories(): array
            {
                return $this->mapper->normalize($this->categories, '/[^a-z0-9_]/i');
            }

            public function getChoice(): ?SourceChoice
            {
                return new SourceChoice('choice-1', gmdate('c', time() - 60), '1',
                    $this->mapper->acceptedPurposes($this->getCategories(), $this->accepted));
            }

            public function getClientConfig(): array
            {
                return [];
            }
        };
        $configured = $this->createStub(ConfiguredSource::class);
        $configured->method('get')->willReturn($source);
        $config = $this->createStub(Config::class);
        $config->method('isRecorded')->willReturn(true);
        $config->method('getRevision')->willReturn('1');
        $config->method('getCookieLifetimeDays')->willReturn(182);
        $browser = $this->createStub(BrowserIdentityInterface::class);
        $browser->method('ensure')->willReturn('browser-1');
        $browser->method('hash')->willReturn('browser-1');
        return new ConsentService($this->store, $configured, $purposes, $browser, $config, $this->storeManager(),
            $this->createStub(ManagerInterface::class), $this->createStub(WordingSnapshot::class), new Random(),
            new WithdrawalPool(), $this->createStub(WithdrawalCleanupStore::class),
            $this->createStub(WithdrawalCleanup::class), $this->locks(), new NullLogger());
    }

    private function locks(): LockManagerInterface
    {
        $locks = $this->createStub(LockManagerInterface::class);
        $locks->method('lock')->willReturn(true);
        $locks->method('unlock')->willReturn(true);
        return $locks;
    }

    // (a) GA4 on in one store view only: the visitor there accepts, the grant is recorded and stays valid
    public function testAPurposeOnInOneStoreViewOnlyIsGrantedThereAndStaysValid(): void
    {
        $this->flags[self::GA] = ['stores/3' => true];
        $this->currentStore = 3;
        $purposes = $this->pool();
        $consent = $this->service($purposes)->grantCurrent('google_analytics');
        self::assertNotNull($consent, 'granted in the store view that shows the purpose');
        self::assertSame(2, $consent->websiteId);
        $this->currentStore = 1; // a cron in another website's store view checks it
        self::assertTrue($this->service($purposes)->isValid($consent->consentId), 'valid for website 2 from anywhere');
        self::assertStringContainsString("ckc.purpose = 'google_analytics'", $this->service($purposes)->validCondition('s.ref'));
    }

    // (b) the bridge on for website 2 only, the cron in the default store view: website 2's purpose stays in the guard
    public function testAWorkerInAnotherWebsitesStoreViewKeepsTheWebsitesPurpose(): void
    {
        $this->flags[self::BRIDGE] = ['websites/2' => true];
        $this->currentStore = 3;
        $purposes = $this->pool();
        $consent = $this->service($purposes)->grantCurrent('cart_reminder');
        self::assertNotNull($consent);
        $this->currentStore = 1; // the consumer runs in website 1's store view, where the bridge is off
        $guard = $this->service($purposes)->validCondition('s.ref');
        self::assertNotSame('0 = 1', $guard, 'website 2 is not lost');
        self::assertStringContainsString('ckc.website_id = 2', $guard);
        self::assertStringContainsString("ckc.purpose = 'cart_reminder'", $guard);
        self::assertStringNotContainsString('ckc.website_id = 1', $guard, 'website 1 has the bridge off');
        self::assertTrue($this->service($purposes)->isValid($consent->consentId));
    }

    public function testOffEverywhereNothingIsGrantedOrValid(): void
    {
        $this->currentStore = 3;
        $purposes = $this->pool();
        self::assertNull($this->service($purposes)->grantCurrent('google_analytics'));
        self::assertNull($this->service($purposes)->grantCurrent('cart_reminder'));
        self::assertSame('0 = 1', $this->service($purposes)->validCondition('s.ref'));
    }

    public function testOnInStoreView3OnlyIsNotGrantedInStoreView4OfTheSameWebsite(): void
    {
        $this->flags[self::GA] = ['stores/3' => true];
        $this->currentStore = 4; // the banner there does not show it
        self::assertNull($this->service($this->pool())->grantCurrent('google_analytics'));
    }

    public function testAStoreViewOnlyPurposeIsWithdrawnWhereTheVisitorSaysNo(): void
    {
        $this->flags[self::GA] = ['stores/3' => true];
        $this->currentStore = 3;
        $purposes = $this->pool();
        $consent = $this->service($purposes)->grantCurrent('google_analytics');
        self::assertNotNull($consent);
        // the visitor switches it off in the same store view: the choice now accepts nothing
        $withdraw = $this->service($purposes, []);
        self::assertSame([$consent->consentId], $withdraw->withdrawCurrent('google_analytics', 'banner'));
        self::assertFalse($this->service($purposes)->isValid($consent->consentId));
    }

    public function testThePoolAnswersPerStoreViewPerWebsiteAndForTheCurrentContext(): void
    {
        $this->flags[self::GA] = ['stores/3' => true];
        $purposes = $this->pool();
        self::assertTrue($purposes->isDeclared('google_analytics', 2), 'website 2: on in one of its store views');
        self::assertTrue($purposes->isDeclared('google_analytics', 2, 3));
        self::assertFalse($purposes->isDeclared('google_analytics', 2, 4));
        self::assertFalse($purposes->isDeclared('google_analytics', 1));
        $this->currentStore = 1;
        self::assertFalse($purposes->isDeclared('google_analytics'), 'no ids: the current store view');
        self::assertTrue($purposes->inContext(2, null, fn () => $purposes->isDeclared('google_analytics')),
            'no ids inside a website context: that website');
        self::assertSame(['google_analytics'], $purposes->inContext(2, 3, fn () => $purposes->getCodes()), 'getCodes() follows the context');
        self::assertSame([], $purposes->inContext(2, 4, fn () => $purposes->getCodes()));
        self::assertFalse($purposes->isDeclared('google_analytics'), 'the context ends with its callback');
        try {
            $purposes->inContext(2, null, fn () => throw new \RuntimeException('x'));
        } catch (\RuntimeException) {
        }
        self::assertFalse($purposes->isDeclared('google_analytics'), 'and after an exception');
    }

    public function testTheCategoriesFollowTheContext(): void
    {
        $this->flags[self::BRIDGE] = ['websites/2' => true];
        $this->currentStore = 1;
        $purposes = $this->pool();
        $mapper = new CategoryMapper($purposes);
        $categories = ['marketing' => ['purposes' => ['cart_reminder']]];
        self::assertSame([], $mapper->normalize($categories, '/x/')['marketing']['purposes'], 'store view 1: off');
        self::assertSame(['cart_reminder'], $purposes->inContext(2, null,
            fn () => $mapper->normalize($categories, '/x/'))['marketing']['purposes'], 'website 2: on');
    }

    // Magento passes null for an optional constructor argument that di.xml does not configure: both must be set there
    public function testDiXmlGivesThePoolItsScopeConfigAndStoreManager(): void
    {
        $di = simplexml_load_file(dirname(__DIR__, 3) . '/etc/di.xml');
        $arguments = [];
        foreach ($di->xpath('//type[@name="CommerceKings\Consent\Model\PurposePool"]/arguments/argument') as $argument) {
            $arguments[(string)$argument['name']] = trim((string)$argument);
        }
        self::assertSame(ScopeConfigInterface::class, $arguments['scopeConfig'] ?? null);
        self::assertSame(StoreManagerInterface::class, $arguments['storeManager'] ?? null);
    }
}
