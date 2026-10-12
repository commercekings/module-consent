<?php
declare(strict_types=1);

namespace CommerceKings\Consent\Test\Unit\Model;

use CommerceKings\Consent\Api\ConsentInterface;
use CommerceKings\Consent\Api\Data\Consent;
use CommerceKings\Consent\Model\ConsentStore;
use CommerceKings\Consent\Model\WithdrawalLink;
use Magento\Framework\App\DeploymentConfig;
use Magento\Framework\DB\Adapter\AdapterInterface;
use Magento\Framework\DB\Select;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\UrlInterface;
use Magento\Store\Model\Store;
use Magento\Store\Model\StoreManagerInterface;
use PHPUnit\Framework\TestCase;

class WithdrawalLinkTest extends TestCase
{
    private ?Consent $record = null;
    private int $website = 1;
    private string $key = 'secret';
    private array $params = [];
    private array $events = [];
    private array $withdrawals = [];
    private bool $fail = false;
    private bool $withdrawUnderLock = false;

    private function record(?string $withdrawn = null): Consent
    {
        return new Consent(str_repeat('a', 32), 'cart_reminder', 1, 'vanilla', 'choice',
            '2020-01-01 00:00:00', '1', '1', '2020-01-01 00:00:00', '2020-02-01 00:00:00', $withdrawn);
    }

    private function service(): WithdrawalLink
    {
        $this->record = $this->record();
        $consents = $this->createStub(ConsentInterface::class);
        $consents->method('get')->willReturnCallback(function ($id) {
            $this->events[] = 'read';
            return $this->record?->consentId === $id ? $this->record : null;
        });
        $consents->method('withdraw')->willReturnCallback(function ($id, $via) {
            $this->withdrawals[] = [$id, $via];
            if ($this->fail) {
                throw new \RuntimeException('withdrawal failed');
            }
            $this->record = $this->record('2026-01-01 00:00:00');
            return [$id];
        });
        $config = $this->createStub(DeploymentConfig::class);
        $config->method('get')->willReturnCallback(fn () => $this->key);
        $url = $this->createStub(UrlInterface::class);
        $url->method('getUrl')->willReturnCallback(function ($route, $params) {
            $this->params = $params;
            return 'https://shop/' . $route . '?' . http_build_query($params['_query']);
        });
        $store = $this->createStub(Store::class);
        $store->method('getId')->willReturn(3);
        $store->method('getWebsiteId')->willReturnCallback(fn () => $this->website);
        $stores = $this->createStub(StoreManagerInterface::class);
        $stores->method('getStore')->willReturn($store);
        $select = $this->createStub(Select::class);
        $select->method('from')->willReturnSelf();
        $select->method('where')->willReturnSelf();
        $select->method('forUpdate')->willReturnCallback(function ($lock) use ($select) {
            $this->assertTrue($lock);
            $this->events[] = 'lock';
            return $select;
        });
        $db = $this->createStub(AdapterInterface::class);
        $db->method('select')->willReturn($select);
        foreach (['beginTransaction', 'commit', 'rollBack'] as $method) {
            $db->method($method)->willReturnCallback(function () use ($method) {
                $this->events[] = $method;
            });
        }
        $db->method('fetchAll')->willReturnCallback(function () {
            if ($this->withdrawUnderLock) {
                $this->record = $this->record('2026-01-01 00:00:00');
            }
            return [];
        });
        $storage = $this->createStub(ConsentStore::class);
        $storage->method('connection')->willReturn($db);
        $storage->method('tableName')->willReturn('ck_consent');
        return new WithdrawalLink($consents, $config, $url, $stores, $storage);
    }

    private function token(WithdrawalLink $service): string
    {
        $service->getUrl(str_repeat('a', 32));
        return $this->params['_query']['t'];
    }

    public function testLinkUsesConsentIdCurrentStoreAndNoFeatureData(): void
    {
        $service = $this->service();
        $url = $service->getUrl(str_repeat('a', 32));
        $this->assertStringContainsString('/ckconsent/optout?', $url);
        $this->assertSame(3, $this->params['_scope']);
        $this->assertTrue($this->params['_nosid']);
        $this->assertSame(['c', 't'], array_keys($this->params['_query']));
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $this->params['_query']['t']);
        $this->assertSame($this->record, $service->getConsent(str_repeat('a', 32), $this->params['_query']['t']));
    }

    public function testExpiredAndWithdrawnRecordsCanStillConfirmWithdrawal(): void
    {
        $service = $this->service();
        $token = $this->token($service);
        $this->record = $this->record('2026-01-01 00:00:00');
        $this->assertSame($this->record, $service->getConsent(str_repeat('a', 32), $token));
    }

    public function testForgedChangedIdWrongWebsiteAndOtherKeyAreRejectedBeforeReading(): void
    {
        $service = $this->service();
        $token = $this->token($service);
        $this->events = [];
        $this->assertNull($service->getConsent(str_repeat('b', 32), $token));
        $this->assertNull($service->getConsent(str_repeat('a', 32), 'bad'));
        $this->website = 2;
        $this->assertNull($service->getConsent(str_repeat('a', 32), $token));
        $this->website = 1;
        $this->key = 'changed-key';
        $this->assertNull($service->getConsent(str_repeat('a', 32), $token));
        $this->assertSame([], $this->events);
    }

    public function testMissingRecordDoesNotGenerateALink(): void
    {
        $service = $this->service();
        $this->record = null;
        $this->expectException(LocalizedException::class);
        $service->getUrl(str_repeat('a', 32));
    }

    public function testAnotherWebsiteDoesNotGenerateALink(): void
    {
        $service = $this->service();
        $this->website = 2;
        $this->expectException(LocalizedException::class);
        $service->getUrl(str_repeat('a', 32));
    }

    public function testMissingCryptKeyFailsClosed(): void
    {
        $service = $this->service();
        $this->key = '';
        $this->expectException(\LogicException::class);
        $this->token($service);
    }

    public function testWithdrawalLocksReloadsAndCommitsOnlyOnceForRepeatedClicks(): void
    {
        $service = $this->service();
        $token = $this->token($service);
        $this->events = [];
        $this->assertNotNull($service->withdraw(str_repeat('a', 32), $token));
        $this->assertSame(['beginTransaction', 'lock', 'read', 'commit'], $this->events);
        $this->assertNotNull($service->withdraw(str_repeat('a', 32), $token));
        $this->assertSame([[str_repeat('a', 32), 'email']], $this->withdrawals);
    }

    public function testWithdrawalUsesFreshRecordAfterLocking(): void
    {
        $service = $this->service();
        $token = $this->token($service);
        $this->withdrawUnderLock = true;
        $this->assertNotNull($service->withdraw(str_repeat('a', 32), $token));
        $this->assertSame([], $this->withdrawals);
    }

    public function testWithdrawalFailureRollsBack(): void
    {
        $service = $this->service();
        $token = $this->token($service);
        $this->fail = true;
        $this->events = [];
        try {
            $service->withdraw(str_repeat('a', 32), $token);
            $this->fail('expected failure');
        } catch (\RuntimeException $error) {
            $this->assertSame('withdrawal failed', $error->getMessage());
            $this->assertSame(['beginTransaction', 'lock', 'read', 'rollBack'], $this->events);
        }
    }

    public function testInvalidLinkNeverStartsWithdrawal(): void
    {
        $service = $this->service();
        $this->events = [];
        $this->assertNull($service->withdraw(str_repeat('a', 32), 'bad'));
        $this->assertSame([], $this->events);
        $this->assertSame([], $this->withdrawals);
    }
}
