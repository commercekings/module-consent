<?php
declare(strict_types=1);

namespace CommerceKings\Consent\Test\Unit\Model;

use CommerceKings\Consent\Api\ConsentInterface;
use CommerceKings\Consent\Api\Data\Consent;
use CommerceKings\Consent\Model\WithdrawalLink;
use Magento\Framework\App\DeploymentConfig;
use Magento\Framework\Encryption\Encryptor;
use Magento\Framework\Encryption\EncryptorInterface;
use Magento\Framework\Encryption\KeyValidator;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Math\Random;
use Magento\Framework\UrlInterface;
use Magento\Store\Model\Store;
use Magento\Store\Model\StoreManagerInterface;
use PHPUnit\Framework\TestCase;

class WithdrawalLinkTest extends TestCase
{
    private ?Consent $record = null;
    private int $website = 1;
    private mixed $key = 'secret';
    private array $params = [];
    private array $events = [];
    private array $withdrawals = [];
    private bool $fail = false;

    private function record(?string $withdrawn = null): Consent
    {
        return new Consent(str_repeat('a', 32), 'cart_reminder', 1, 'vanilla', 'choice',
            '2020-01-01 00:00:00', '1', '1', '2020-01-01 00:00:00', '2020-02-01 00:00:00', $withdrawn);
    }

    private function service(?EncryptorInterface $encryptor = null): WithdrawalLink
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
        $encryptor ??= new Encryptor($this->createStub(Random::class), $config, $this->createStub(KeyValidator::class));
        return new WithdrawalLink($consents, $config, $url, $stores, $encryptor);
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
        $service = $this->service(); // A new deployment starts with a new native encryptor instance.
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

    public function testWithdrawalDelegatesToTheCentralCheckpointOnlyOnceForRepeatedClicks(): void
    {
        $service = $this->service();
        $token = $this->token($service);
        $this->events = [];
        $this->assertNotNull($service->withdraw(str_repeat('a', 32), $token));
        $this->assertSame(['read'], $this->events);
        $this->assertNotNull($service->withdraw(str_repeat('a', 32), $token));
        $this->assertSame([[str_repeat('a', 32), 'email']], $this->withdrawals);
    }

    public function testCoreWithdrawalFailurePropagatesToTheCaller(): void
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
            $this->assertSame(['read'], $this->events);
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

    public function testNewLinksUseInjectedMagentoEncryptor(): void
    {
        $encryptor = $this->createMock(EncryptorInterface::class);
        $encryptor->expects($this->once())->method('hash')
            ->with('commercekings_consent_withdrawal|1|' . str_repeat('a', 32))->willReturn(str_repeat('b', 64));
        $encryptor->expects($this->never())->method('validateHash');
        $this->assertSame(str_repeat('b', 64), $this->token($this->service($encryptor)));
    }

    public function testMultipleKeysSignWithTheCurrentKeyAndRejectRawDeploymentTokens(): void
    {
        $this->key = "old-key\nnew-key";
        $service = $this->service();
        $id = str_repeat('a', 32);
        $payload = 'commercekings_consent_withdrawal|1|' . $id;
        $native = hash_hmac('sha256', $payload, 'new-key');
        $legacy = hash_hmac('sha256', $payload, $this->key);
        $this->assertSame($native, $this->token($service));
        $this->assertNotSame($native, $legacy);
        $this->events = [];
        $this->assertNull($service->getConsent($id, $legacy));
        $this->assertNull($service->getConsent(str_repeat('b', 32), $native));
        $this->website = 2;
        $this->assertNull($service->getConsent($id, $native));
        $this->website = 1;
        $this->assertNull($service->getConsent(str_repeat('b', 32), hash_hmac('sha256', $payload, 'old-key')),
            'an old-key signature is still bound to its consent id');
        $this->key = 'different-key';
        $this->assertNull($this->service()->getConsent($id, $native));
        $this->assertSame([], $this->events);
    }

    public function testLinksSignedWithAnOlderKeyStillListedKeepWorkingAfterAKeyChange(): void
    {
        $id = str_repeat('a', 32);
        $payload = 'commercekings_consent_withdrawal|1|' . $id;
        $first = hash_hmac('sha256', $payload, 'first-key');
        $second = hash_hmac('sha256', $payload, base64_decode('c2Vjb25kLWtleQ=='));
        $this->key = "first-key\nbase64c2Vjb25kLWtleQ==\nthird-key";
        $service = $this->service();
        $this->assertSame(hash_hmac('sha256', $payload, 'third-key'), $this->token($service), 'new links: newest key');
        $this->assertNotNull($service->getConsent($id, $first), 'signed before the first key change');
        $this->assertNotNull($service->getConsent($id, $second), 'signed before the second change (2.4.7+ key format)');
        $this->assertNull($service->getConsent($id, hash_hmac('sha256', $payload, 'removed-key')), 'a key no longer listed');
        $this->website = 2;
        $this->assertNull($service->getConsent($id, $first), 'still bound to its website');
        $this->website = 1;
        $this->key = "second-only\nthird-key";
        $this->assertNull($this->service()->getConsent($id, $first), 'removing a key from env.php ends its links');
    }

    /** The use case: an email sent before encryption:key:change, its unsubscribe link clicked after it. */
    public function testUnsubscribingFromAnEmailSentBeforeAKeyChangeStillWithdraws(): void
    {
        $id = str_repeat('a', 32);
        $this->key = 'key-before-the-change';
        $sentBefore = $this->token($this->service());                     // the link in the email already sent

        $this->key = "key-before-the-change\nkey-after-the-change";       // what encryption:key:change leaves
        $afterChange = $this->service();
        $this->assertNotSame($sentBefore, $this->token($afterChange), 'new emails get links signed with the new key');
        $this->assertNotNull($afterChange->withdraw($id, $sentBefore), 'the old email\'s link still withdraws');
        $this->assertSame([[$id, 'email']], $this->withdrawals);
        $this->assertNotNull($afterChange->withdraw($id, $sentBefore), 'a second click shows the result again');
        $this->assertSame([[$id, 'email']], $this->withdrawals, '...without withdrawing twice');

        $this->withdrawals = [];
        $this->key = 'key-after-the-change';                               // the shop removed the old key
        $this->assertNull($this->service()->withdraw($id, $sentBefore));
        $this->assertSame([], $this->withdrawals, 'removing the old key from env.php ends its links');
    }

    public function testOlderKeysAreIgnoredWhenTheEncryptorSignsDifferently(): void
    {
        $this->key = "first-key\nnew-key";
        $encryptor = $this->createStub(EncryptorInterface::class);
        $encryptor->method('hash')->willReturn(str_repeat('c', 64));    // e.g. a replaced Encryptor
        $id = str_repeat('a', 32);
        $old = hash_hmac('sha256', 'commercekings_consent_withdrawal|1|' . $id, 'first-key');
        $this->assertNull($this->service($encryptor)->getConsent($id, $old), 'unconfirmed: only the newest key counts');
    }

    public function testEncodedKeyRejectsRawSignatureAndNativeWithdrawalKeepsReplayProtection(): void
    {
        if (!defined('Magento\\Framework\\Config\\ConfigOptionsListConstants::STORE_KEY_ENCODED_RANDOM_STRING_PREFIX')) {
            $this->markTestSkipped('This Magento version does not support encoded deployment keys.');
        }
        $decoded = str_repeat('k', 32);
        $this->key = 'base64' . base64_encode($decoded);
        $service = $this->service();
        $id = str_repeat('a', 32);
        $payload = 'commercekings_consent_withdrawal|1|' . $id;
        $native = hash_hmac('sha256', $payload, $decoded);
        $legacy = hash_hmac('sha256', $payload, $this->key);
        $this->assertSame($native, $this->token($service));
        $this->assertNotSame($native, $legacy);
        $this->events = [];
        $this->assertNull($service->getConsent($id, $legacy));
        $this->assertNull($service->withdraw($id, $legacy));
        $this->assertSame([], $this->events);
        $this->assertSame($this->record, $service->getConsent($id, $native));
        $this->assertNotNull($service->withdraw($id, $native));
        $this->assertNotNull($service->withdraw($id, $native));
        $this->assertSame([[$id, 'email']], $this->withdrawals);
    }

    public function testUnkeyedPasswordAndMalformedHashesAreRejectedWithoutReadingOrWithdrawing(): void
    {
        $service = $this->service();
        $id = str_repeat('a', 32);
        $payload = 'commercekings_consent_withdrawal|1|' . $id;
        foreach ([hash('sha256', $payload), hash('sha256', 'salt' . $payload) . ':salt:1',
            str_repeat('A', 64), str_repeat('0', 64), str_repeat('0', 64) . "\n"] as $token) {
            $this->assertNull($service->getConsent($id, $token));
            $this->assertNull($service->withdraw($id, $token));
        }
        $this->assertSame([], $this->events);
        $this->assertSame([], $this->withdrawals);
    }

    public function testNativeSigningFailureRejectsAnOtherwiseValidLink(): void
    {
        $encryptor = $this->createStub(EncryptorInterface::class);
        $encryptor->method('hash')->willThrowException(new \RuntimeException('No key available'));
        $service = $this->service($encryptor);
        $id = str_repeat('a', 32);
        $legacy = hash_hmac('sha256', 'commercekings_consent_withdrawal|1|' . $id, $this->key);
        $this->assertNull($service->getConsent($id, $legacy));
        $this->assertNull($service->withdraw($id, $legacy));
        $this->assertSame([], $this->events);
        $this->expectException(\RuntimeException::class);
        $service->getUrl($id);
    }

    /** @dataProvider invalidKeys */
    #[\PHPUnit\Framework\Attributes\DataProvider('invalidKeys')]
    public function testInvalidDeploymentKeyCannotAuthorizePredictableWithdrawal(mixed $key): void
    {
        $this->key = $key;
        $service = $this->service();
        $id = str_repeat('a', 32);
        $token = hash_hmac('sha256', 'commercekings_consent_withdrawal|1|' . $id, (string)$key);
        $this->assertNull($service->getConsent($id, $token));
        $this->assertNull($service->withdraw($id, $token));
        $this->assertSame([], $this->events);
        $this->expectException(\LogicException::class);
        $service->getUrl($id);
    }

    public static function invalidKeys(): array
    {
        return ['missing' => [null], 'empty' => [''], 'whitespace' => [" \t\n"], 'non-string' => [42]];
    }
}
