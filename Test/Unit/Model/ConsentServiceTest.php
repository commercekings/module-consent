<?php
declare(strict_types=1);

namespace CommerceKings\Consent\Test\Unit\Model;

use CommerceKings\Consent\Api\BrowserIdentityInterface;
use CommerceKings\Consent\Api\Data\SourceChoice;
use CommerceKings\Consent\Api\SourceInterface;
use CommerceKings\Consent\Model\Config;
use CommerceKings\Consent\Model\ConfiguredSource;
use CommerceKings\Consent\Model\ConsentService;
use CommerceKings\Consent\Model\PurposePool;
use CommerceKings\Consent\Model\WordingSnapshot;
use CommerceKings\Consent\Model\WithdrawalPool;
use CommerceKings\Consent\Model\WithdrawalCleanupStore;
use CommerceKings\Consent\Model\WithdrawalCleanup;
use Magento\Framework\Lock\LockManagerInterface;
use Psr\Log\NullLogger;
use CommerceKings\Consent\Test\Unit\InMemoryConsentStore;
use CommerceKings\Consent\Test\Unit\TestClock;
use Magento\Framework\DB\Adapter\AdapterInterface;
use Magento\Framework\Event\ManagerInterface;
use Magento\Framework\Math\Random;
use Magento\Store\Api\Data\WebsiteInterface;
use Magento\Store\Model\StoreManagerInterface;
use PHPUnit\Framework\TestCase;

/**
 * The consent lifecycle: record once per source choice, server-issued id, withdrawal persisted before the
 * event, withdrawn choices never recorded again, revisions, fail closed.
 */
class ConsentServiceTest extends TestCase
{
    private InMemoryConsentStore $store;
    private ?SourceChoice $choice = null;
    private bool $sourceInstalled = true;
    private string $bannerRevision = '1';
    private string $termsRevision = '1';
    private int $lifetimeDays = 182;
    private string $browserHash = 'bh';
    private array $events = [];
    private string $wordingHash = 'wording-1';
    /** optional purposes the shop does not record */
    private array $unrecorded = [];
    private array $categories = [
        'reminders' => ['purposes' => ['cart_reminder']],
        'statistics' => ['purposes' => ['analytics']],
    ];

    protected function setUp(): void
    {
        $this->store = new InMemoryConsentStore();
        $this->store->db = $this->createStub(AdapterInterface::class);
        $this->store->db->method('getTransactionLevel')->willReturn(0);
    }

    private function service(?LockManagerInterface $locks = null, ?WithdrawalCleanupStore $tasks = null): ConsentService
    {
        $source = $this->createStub(SourceInterface::class);
        $source->method('getCode')->willReturn('banner');
        $source->method('getChoice')->willReturnCallback(fn () => $this->choice);
        $source->method('getCategories')->willReturnCallback(fn () => $this->categories);
        $configured = $this->createStub(ConfiguredSource::class);
        $configured->method('get')->willReturnCallback(fn () => $this->sourceInstalled ? $source : null);
        $browser = $this->createStub(BrowserIdentityInterface::class);
        $browser->method('hash')->willReturnCallback(fn () => $this->browserHash);
        $browser->method('ensure')->willReturnCallback(fn () => $this->browserHash = $this->browserHash ?: 'new');
        $config = $this->createStub(Config::class);
        $config->method('getRevision')->willReturnCallback(fn () => $this->bannerRevision);
        $config->method('getCookieLifetimeDays')->willReturnCallback(fn () => $this->lifetimeDays);
        $config->method('isRecorded')->willReturnCallback(fn ($purpose) => !in_array($purpose, $this->unrecorded, true));
        $website = $this->createStub(WebsiteInterface::class);
        $website->method('getId')->willReturn(1);
        $stores = $this->createStub(StoreManagerInterface::class);
        $stores->method('getWebsite')->willReturn($website);
        $stores->method('getWebsites')->willReturn([$website]);
        $events = $this->createStub(ManagerInterface::class);
        $events->method('dispatch')->willReturnCallback(function ($name, $data) {
            // the withdrawal is already stored when features hear about it
            foreach ($data['consent_ids'] as $id) {
                $this->assertNotNull($this->store->rows[$id]['withdrawn_at']);
            }
            $this->events[] = [$name, $data];
        });
        $purposes = new PurposePool([
            'cart_reminder' => ['label' => 'Cart reminder', 'terms_revision' => $this->termsRevision],
            'analytics' => ['label' => 'Analytics'],
        ]);
        $wording = $this->createStub(WordingSnapshot::class);
        $wording->method('currentHash')->willReturnCallback(fn () => $this->wordingHash);
        if ($locks === null) {
            $locks = $this->createStub(LockManagerInterface::class);
            $locks->method('lock')->willReturn(true);
        }
        if ($tasks === null) {
            $tasks = $this->createStub(WithdrawalCleanupStore::class);
            $tasks->method('schedule')->willReturn([]);
        }
        return new ConsentService($this->store, $configured, $purposes, $browser, $config, $stores, $events, $wording,
            new Random(), new WithdrawalPool(), $tasks, $this->createStub(WithdrawalCleanup::class), $locks, new NullLogger());
    }

    private function choose(array $purposes, string $time, string $reference = 'cid-1', string $revision = '1'): void
    {
        $this->choice = new SourceChoice($reference, $time, $revision, $purposes);
    }

    public function testUnavailableScopeLockLeavesConsentAndCleanupUntouched(): void
    {
        $this->choose(['cart_reminder'], TestClock::at('09:00:00'));
        $id = $this->service()->getCurrent('cart_reminder')->consentId;
        $before = $this->store->rows;
        $db = $this->createMock(AdapterInterface::class);
        $db->method('getTransactionLevel')->willReturn(0);
        $db->expects($this->never())->method('beginTransaction');
        $this->store->db = $db;
        $locks = $this->createMock(LockManagerInterface::class);
        $locks->expects($this->once())->method('lock')->with($this->isString(), 5)->willReturn(false);
        $locks->expects($this->never())->method('unlock');
        $tasks = $this->createMock(WithdrawalCleanupStore::class);
        $tasks->expects($this->never())->method('schedule');
        try {
            $this->service($locks, $tasks)->withdraw($id, 'email');
            $this->fail('A busy withdrawal must not acknowledge success.');
        } catch (\CommerceKings\Consent\Exception\WithdrawalBusyException $error) {
            $this->assertSame('Consent withdrawal is busy; retry the request.', $error->getMessage());
        }
        $this->assertSame($before, $this->store->rows);
        $this->assertSame([], $this->store->marks);
        $this->assertSame([], $this->events);
    }

    public function testGrantPersistenceFailureCannotAcknowledgePermissionAndCanBeRetried(): void
    {
        $this->choose(['cart_reminder'], TestClock::at('09:00:00'));
        $this->store = $this->createPartialMock(InMemoryConsentStore::class, ['record']);
        $error = new \RuntimeException('Storage unavailable');
        $this->store->expects($this->once())->method('record')->willThrowException($error);
        try {
            $this->service()->grantCurrent('cart_reminder');
            $this->fail('An unstored grant must not be acknowledged.');
        } catch (\RuntimeException $caught) {
            $this->assertSame($error, $caught);
        }
        $this->assertSame([], $this->store->rows);
        $this->assertSame([], $this->events);
        $this->store = new InMemoryConsentStore();
        $this->assertNotNull($this->service()->grantCurrent('cart_reminder'));
        $this->assertCount(1, $this->store->rows);
    }

    public function testAnExpiryPastTheDatabaseLimitIsCappedNotStoredAsExpired(): void
    {
        // a TIMESTAMP ends in January 2038; past it the database would store an expiry that is already over
        $this->lifetimeDays = 5000;
        $this->choose(['cart_reminder'], TestClock::at('09:00:00'));
        $consent = $this->service()->getCurrent('cart_reminder');
        $this->assertSame('2038-01-19 03:14:07', $consent->expiresAt);
    }

    public function testFirstUseRecordsTheChoiceOnceWithAServerIssuedId(): void
    {
        $this->choose(['cart_reminder'], TestClock::at('09:00:00'));
        $first = $this->service()->getCurrent('cart_reminder');
        $this->assertMatchesRegularExpression('/^[0-9a-f]{32}$/', $first->consentId);
        $this->assertSame(['cart_reminder', 1, 'banner', 'cid-1', TestClock::at('09:00:00'), '1', '1'], [
            $first->purpose, $first->websiteId, $first->source, $first->reference, $first->sourceTime, $first->bannerRevision, $first->termsRevision,
        ]);
        $this->assertSame(TestClock::at('09:00:00', 182), $first->expiresAt, 'source time + source cookie lifetime when the choice has no expiry');
        $this->assertSame($first->consentId, $this->service()->getCurrent('cart_reminder')->consentId, 'same choice, same consent');
        $this->assertCount(1, $this->store->rows);
        $this->assertTrue($this->service()->isValid($first->consentId));
    }

    public function testEverySavedChoiceIsItsOwnConsentAction(): void
    {
        $this->choose(['cart_reminder'], TestClock::at('09:00:00'));
        $first = $this->service()->getCurrent('cart_reminder');
        $this->choose(['cart_reminder', 'analytics'], TestClock::at('10:00:00'));
        $this->assertNotSame($first->consentId, $this->service()->getCurrent('cart_reminder')->consentId);
    }

    /** @dataProvider noConsent */
    #[\PHPUnit\Framework\Attributes\DataProvider('noConsent')]
    public function testFailsClosed(callable $setup): void
    {
        $this->choose(['cart_reminder'], TestClock::at('09:00:00'));
        $setup($this);
        $this->assertNull($this->service()->getCurrent('cart_reminder'));
        $this->assertSame([], $this->store->rows, 'nothing recorded');
    }

    public static function noConsent(): array
    {
        return [
            'purpose not accepted' => [fn (self $t) => $t->choose(['analytics'], TestClock::at('09:00:00'))],
            'no choice in the source' => [fn (self $t) => $t->choice = null],
            'no source chosen / installed' => [fn (self $t) => $t->sourceInstalled = false],
            'choice without identity' => [fn (self $t) => $t->choose(['cart_reminder'], TestClock::at('09:00:00'), '')],
            'choice without time' => [fn (self $t) => $t->choose(['cart_reminder'], '')],
            'choice for another banner revision' => [fn (self $t) => $t->choose(['cart_reminder'], TestClock::at('09:00:00'), 'cid-1', '0')],
            'no categories configured' => [fn (self $t) => $t->categories = []],
            'purpose no longer mapped' => [fn (self $t) => $t->categories = ['statistics' => ['purposes' => ['analytics']]]],
        ];
    }

    public function testUndeclaredPurposeIsNeverConsented(): void
    {
        $this->choose(['newsletter'], TestClock::at('09:00:00'));
        $this->assertNull($this->service()->getCurrent('newsletter'));
    }

    public function testBannerWithdrawalPersistsThenFiresTheEventWithTheConsentIds(): void
    {
        $this->choose(['cart_reminder'], TestClock::at('09:00:00'));
        $id = $this->service()->getCurrent('cart_reminder')->consentId;
        $this->choose([], TestClock::at('09:05:00'));
        $this->assertSame([$id], $this->service()->withdrawCurrent('cart_reminder', 'banner'));
        $this->assertFalse($this->service()->isValid($id));
        $this->assertSame('banner', $this->service()->get($id)->withdrawnVia);
        $this->assertSame([['commercekings_consent_withdrawn', [
            'purpose' => 'cart_reminder', 'browser_hash' => 'bh', 'website_id' => 1, 'consent_ids' => [$id], 'via' => 'banner',
        ]]], $this->events);
    }

    public function testWithdrawalWhileTheSourceStillSaysYesDoesNothing(): void
    {
        $this->choose(['cart_reminder'], TestClock::at('09:00:00'));
        $id = $this->service()->getCurrent('cart_reminder')->consentId;
        $this->assertSame([], $this->service()->withdrawCurrent('cart_reminder', 'banner'), 'forged call');
        $this->assertTrue($this->service()->isValid($id));
        $this->assertSame([], $this->events);
    }

    public function testNoCurrentChoiceWithdrawsNothing(): void
    {
        // e.g. the banner revision was raised: the old consent is invalid, but nobody withdrew it
        $this->choose(['cart_reminder'], TestClock::at('09:00:00'));
        $id = $this->service()->getCurrent('cart_reminder')->consentId;
        $this->choice = null;
        $this->assertSame([], $this->service()->withdrawCurrent('cart_reminder', 'banner'));
        $this->assertNull($this->service()->get($id)->withdrawnAt);
        $this->assertSame([], $this->events);
    }

    public function testARequestStillOnItsWayCannotUndoTheWithdrawal(): void
    {
        // the checkout capture left with the 09:04 "yes" cookie, the visitor said "no" at 09:05, the
        // capture arrives after the withdrawal - and the choice had never been recorded before
        $this->choose([], TestClock::at('09:05:00'));
        $this->service()->withdrawCurrent('cart_reminder', 'banner');
        $this->choose(['cart_reminder'], TestClock::at('09:04:00'));
        $this->assertNull($this->service()->getCurrent('cart_reminder'));
        $this->assertSame([], $this->store->rows);
    }

    public function testAYesRecordedWhileTheWithdrawalRunsIsWithdrawnToo(): void
    {
        // the checkout's subscribe call records the 09:04 "yes" for the first time while "Reject" (09:05) is being
        // written: the withdrawal writes its marks first and only then reads the active consents (FOR UPDATE)
        $this->choose(['cart_reminder'], TestClock::at('09:04:00'));
        $this->service()->getCurrent('cart_reminder');
        $late = array_values($this->store->rows)[0];
        $db = $this->store->db;
        $this->store = new class extends InMemoryConsentStore {
            public ?array $late = null;
            public function mark(string $purpose, string $browserHash, int $websiteId, string $source, string $reference, string $sourceTime): void
            {
                if ($this->late !== null) {
                    $this->rows[$this->late['consent_id']] = $this->late; // committed just before the locking read
                    $this->late = null;
                }
                parent::mark($purpose, $browserHash, $websiteId, $source, $reference, $sourceTime);
            }
        };
        $this->store->late = $late;
        $this->store->db = $db;
        $this->choose([], TestClock::at('09:05:00'));
        $this->service()->withdrawCurrent('cart_reminder', 'banner');
        $this->assertNotNull($this->store->rows[$late['consent_id']]['withdrawn_at'] ?? null, 'no reminder after the "no"');
        $this->choose(['cart_reminder'], TestClock::at('09:04:00'));
        $this->assertNull($this->service()->getCurrent('cart_reminder'));
    }

    public function testAnOldYesOnAnotherDeviceNeverCountsAgain(): void
    {
        // laptop said yes, the reminder's unsubscribe link was opened on the phone
        $this->choose(['cart_reminder'], TestClock::at('09:00:00'));
        $id = $this->service()->getCurrent('cart_reminder')->consentId;
        $this->service()->withdraw($id, 'email');
        // the laptop still has the same "yes" cookie - no matter how often it asks
        $this->assertNull($this->service()->getCurrent('cart_reminder'));
        $this->assertNull($this->service()->getCurrent('cart_reminder'));
        $this->assertCount(1, $this->store->rows);
        // a new choice in the banner (switching the category on again) is a new consent
        $this->choose(['cart_reminder'], TestClock::at('10:00:00'));
        $this->assertNotNull($this->service()->grantCurrent('cart_reminder'));
    }

    public function testWithdrawingOneConsentWithdrawsEveryActiveOneOfThatBrowserAndPurpose(): void
    {
        $this->choose(['cart_reminder'], TestClock::at('09:00:00'));
        $first = $this->service()->getCurrent('cart_reminder')->consentId;
        $this->choose(['cart_reminder', 'analytics'], TestClock::at('10:00:00'));
        $second = $this->service()->getCurrent('cart_reminder')->consentId;
        $this->choose(['cart_reminder'], TestClock::at('09:00:00'), 'cid-1');
        $this->browserHash = 'other-browser';
        $other = $this->service()->getCurrent('cart_reminder')->consentId;

        $this->assertEqualsCanonicalizing([$first, $second], $this->service()->withdraw($first, 'email'));
        $this->assertTrue($this->service()->isValid($other), 'another browser is untouched');
        $this->assertSame([], $this->service()->withdraw($first, 'email'), 'already withdrawn: nothing new, no event');
        $this->assertCount(1, $this->events);
    }

    public function testRevisionsAndExpiryEndValidity(): void
    {
        $this->choose(['cart_reminder'], TestClock::at('09:00:00'));
        $id = $this->service()->getCurrent('cart_reminder')->consentId;
        $this->bannerRevision = '2';
        $this->assertFalse($this->service()->isValid($id), 'banner revision raised: every purpose asks again');
        $this->bannerRevision = '1';
        $this->termsRevision = '2';
        $this->assertFalse($this->service()->isValid($id), 'the purpose terms changed');
        $this->termsRevision = '1';
        $this->sourceInstalled = false;
        $this->assertFalse($this->service()->isValid($id), 'source switched off / removed');
        $this->sourceInstalled = true;
        $this->store->rows[$id]['expires_at'] = '2020-01-01 00:00:00';
        $this->assertFalse($this->service()->isValid($id), 'expired');
        $this->assertFalse($this->service()->isValid(''));
        $this->assertFalse($this->service()->isValid('unknown'));
    }

    public function testSourceExpiryIsUsedWhenGiven(): void
    {
        $this->choice = new SourceChoice('cid-1', TestClock::at('09:00:00'), '1', ['cart_reminder'], TestClock::at('00:00:00', 52));
        $this->assertSame(TestClock::at('00:00:00', 52), $this->service()->getCurrent('cart_reminder')->expiresAt);
    }

    public function testSourceCookieCannotExtendConsentBeyondConfiguredLifetime(): void
    {
        $this->choice = new SourceChoice('cid-1', TestClock::at('09:00:00'), '1', ['cart_reminder'], TestClock::at('00:00:00', 1000));
        $this->assertSame(TestClock::at('09:00:00', 182), $this->service()->getCurrent('cart_reminder')->expiresAt);
    }

    public function testReducingLifetimeInvalidatesOlderPermissionWithoutMutatingEvidence(): void
    {
        $this->choose(['cart_reminder'], gmdate('Y-m-d H:i:s', time() - 2 * 86400));
        $consent = $this->service()->getCurrent('cart_reminder');
        $this->assertNotNull($consent);
        $this->lifetimeDays = 1;
        $this->assertFalse($this->service()->isValid($consent->consentId));
        $this->assertNull($this->service()->getCurrent('cart_reminder'));
        $this->assertNull($this->store->rows[$consent->consentId]['withdrawn_at']);
    }

    public function testGuardConditionChecksTheSameRulesInSql(): void
    {
        $db = $this->createStub(AdapterInterface::class);
        $db->method('quoteInto')->willReturnCallback(fn ($text, $value) => str_replace('?', "'" . $value . "'", $text));
        $db->method('quoteIdentifier')->willReturnCallback(fn ($name) => '`' . $name . '`');
        $this->store = $this->createStub(InMemoryConsentStore::class);
        $this->store->method('connection')->willReturn($db);
        $this->store->method('tableName')->willReturn('ck_consent');
        $sql = $this->service()->validCondition('c.consent_id');
        $this->assertSame(
            "EXISTS (SELECT 1 FROM `ck_consent` ckc WHERE ckc.consent_id = c.consent_id AND ckc.withdrawn_at IS NULL"
            . " AND ckc.expires_at > NOW() AND ((ckc.website_id = 1 AND ckc.source = 'banner' AND ckc.banner_revision = '1'"
            . " AND LEAST(ckc.source_time, ckc.granted_at) > NOW() - INTERVAL 182 DAY AND ((ckc.purpose = 'cart_reminder' AND ckc.terms_revision = '1') OR (ckc.purpose = 'analytics' AND ckc.terms_revision = '1')))))",
            $sql
        );
        $this->categories = ['statistics' => ['purposes' => ['analytics', 'unknown']]];
        $sql = $this->service()->validCondition('x');
        $this->assertStringContainsString("ckc.purpose = 'analytics'", $sql);
        $this->assertStringNotContainsString('cart_reminder', $sql, 'declared but unmapped purpose is blocked');
        $this->assertStringNotContainsString('unknown', $sql, 'mapped but undeclared purpose is blocked');
        $this->categories = [];
        $this->assertSame('0 = 1', $this->service()->validCondition('x'), 'site wiring removed: nothing is valid');
        $this->sourceInstalled = false;
        $this->assertSame('0 = 1', $this->service()->validCondition('x'), 'no source anywhere: nothing is valid');
    }

    public function testRemovingMappingInvalidatesExistingConsentWithoutWithdrawingIt(): void
    {
        $this->choose(['cart_reminder', 'analytics'], TestClock::at('09:00:00'));
        $service = $this->service();
        $reminder = $service->getCurrent('cart_reminder')->consentId;
        $analytics = $service->getCurrent('analytics')->consentId;
        $this->categories = ['statistics' => ['purposes' => ['analytics']]];

        $this->assertFalse($service->isValid($reminder));
        $this->assertTrue($service->isValid($analytics), 'unrelated mapping remains valid');
        $this->assertNull($service->getCurrent('cart_reminder'), 'old yes choice does not bypass missing mapping');
        $this->assertNull($service->grantCurrent('cart_reminder'), 'explicit grant cannot bypass missing mapping');
        $this->assertNull($service->get($reminder)->withdrawnAt, 'configuration removal is not visitor withdrawal');
        $this->assertCount(2, $this->store->rows, 'records remain available as evidence');
        $this->assertSame([], $this->events);
    }

    public function testRemovingAllSiteCategoriesInvalidatesExistingConsentWithSourceStillInstalled(): void
    {
        $this->choose(['cart_reminder'], TestClock::at('09:00:00'));
        $service = $this->service();
        $id = $service->getCurrent('cart_reminder')->consentId;
        $this->categories = [];
        $this->assertFalse($service->isValid($id));
        $this->assertNull($service->getCurrent('cart_reminder'));
        $this->assertNull($service->grantCurrent('cart_reminder'));
    }

    public function testAnyRemainingCategoryMappingKeepsExistingConsentValid(): void
    {
        $this->choose(['cart_reminder'], TestClock::at('09:00:00'));
        $service = $this->service();
        $id = $service->getCurrent('cart_reminder')->consentId;
        $this->categories = ['other' => ['purposes' => ['cart_reminder']]];
        $this->assertTrue($service->isValid($id), 'mapping to any category is sufficient');
    }

    public function testAfterAWithdrawalOnlyAnExplicitChoiceCountsAgain(): void
    {
        // opted out by email; on the laptop the visitor later changes ONLY analytics - the banner saves
        // again (new time), personalization still on from before: that is no new choice for the reminder
        $this->choose(['cart_reminder'], TestClock::at('09:00:00'));
        $this->service()->withdraw($this->service()->getCurrent('cart_reminder')->consentId, 'email');
        $this->choose(['cart_reminder', 'analytics'], TestClock::at('11:00:00'));
        $this->assertNull($this->service()->getCurrent('cart_reminder'));
        // switching the reminder category on again is (js/consent/provider.js -> /ckconsent/grant)
        $this->assertNotNull($granted = $this->service()->grantCurrent('cart_reminder'));
        $this->assertSame($granted->consentId, $this->service()->getCurrent('cart_reminder')->consentId);
    }

    public function testExplicitGrantStillRefusesChoicesFromBeforeTheWithdrawal(): void
    {
        $this->choose([], TestClock::at('09:05:00'));
        $this->service()->withdrawCurrent('cart_reminder', 'banner');
        $this->choose(['cart_reminder'], TestClock::at('09:04:00'));
        $this->assertNull($this->service()->grantCurrent('cart_reminder'));
    }

    public function testAnotherSeriesIsANewChoice(): void
    {
        // cookies cleared, the banner asked again and the visitor said yes: new consentId
        $this->choose(['cart_reminder'], TestClock::at('09:00:00'));
        $this->service()->withdraw($this->service()->getCurrent('cart_reminder')->consentId, 'email');
        $this->choose(['cart_reminder'], TestClock::at('12:00:00'), 'cid-2');
        $this->assertNotNull($this->service()->getCurrent('cart_reminder'));
    }

    public function testChoiceDatedInTheFutureIsRefused(): void
    {
        // a wrong clock or an edited cookie: more than a day ahead gives no consent at all
        $this->choose(['cart_reminder'], gmdate('Y-m-d H:i:s', time() + 2 * 86400));
        $this->assertNull($this->service()->getCurrent('cart_reminder'));
        $this->assertSame([], $this->store->rows);
    }

    public function testSlightlyAheadClockCountsTheLifetimeFromServerTime(): void
    {
        $this->choose(['cart_reminder'], gmdate('Y-m-d H:i:s', time() + 3600));
        $consent = $this->service()->getCurrent('cart_reminder');
        $this->assertNotNull($consent, 'within the tolerance');
        $this->assertLessThanOrEqual(gmdate('Y-m-d H:i:s', time() + 182 * 86400 + 5), $consent->expiresAt, 'not from the future time');
    }

    public function testLifetimeRunsFromTheEarlierOfChoiceAndServerTime(): void
    {
        $this->choose(['cart_reminder'], TestClock::at('09:00:00'));
        $id = $this->service()->getCurrent('cart_reminder')->consentId;
        // the banner claims a recent time, but the server recorded it long ago
        $this->store->rows[$id]['source_time'] = gmdate('Y-m-d H:i:s');
        $this->store->rows[$id]['granted_at'] = gmdate('Y-m-d H:i:s', time() - 200 * 86400);
        $this->assertFalse($this->service()->isValid($id));
    }

    public function testEachConsentPointsToTheWordingShownWhenItWasRecorded(): void
    {
        $this->choose(['cart_reminder'], TestClock::at('09:00:00'));
        $first = $this->service()->getCurrent('cart_reminder');
        $this->assertSame('wording-1', $first->snapshotHash);
        $this->wordingHash = 'wording-2';
        $this->assertSame('wording-1', $this->service()->getCurrent('cart_reminder')->snapshotHash, 'an existing consent keeps its wording');
        $this->choose(['cart_reminder'], TestClock::at('10:00:00'));
        $this->assertSame('wording-2', $this->service()->getCurrent('cart_reminder')->snapshotHash, 'a new choice gets the new wording');
    }

    public function testAnUnrecordedPurposeStoresNothingAndIsNeverValidOnTheServer(): void
    {
        $this->unrecorded = ['analytics'];
        $this->choose(['analytics', 'cart_reminder'], TestClock::at('09:00:00'));
        $this->assertNull($this->service()->getCurrent('analytics'));
        $this->assertNull($this->service()->grantCurrent('analytics'));
        $this->assertNotNull($this->service()->getCurrent('cart_reminder'), 'a recorded purpose next to it still works');
        $this->assertSame(['cart_reminder'], array_values(array_unique(array_column($this->store->rows, 'purpose'))));
    }

    public function testAnUnrecordedPurposeIsLeftOutOfTheGuard(): void
    {
        $db = $this->createStub(AdapterInterface::class);
        $db->method('quoteInto')->willReturnCallback(fn ($text, $value) => str_replace('?', "'" . $value . "'", $text));
        $db->method('quoteIdentifier')->willReturnCallback(fn ($name) => '`' . $name . '`');
        $this->store = $this->createStub(InMemoryConsentStore::class);
        $this->store->method('connection')->willReturn($db);
        $this->store->method('tableName')->willReturn('ck_consent');
        $this->unrecorded = ['analytics'];
        $sql = $this->service()->validCondition('x');
        $this->assertStringContainsString("ckc.purpose = 'cart_reminder'", $sql);
        $this->assertStringNotContainsString("ckc.purpose = 'analytics'", $sql);
        $this->unrecorded = ['analytics', 'cart_reminder'];
        $this->assertSame('0 = 1', $this->service()->validCondition('x'));
    }

    public function testRecordsOfAPurposeNoLongerRecordedStopCounting(): void
    {
        $this->choose(['analytics'], TestClock::at('09:00:00'));
        $consent = $this->service()->getCurrent('analytics');
        $this->assertTrue($this->service()->isValid($consent->consentId));
        $this->unrecorded = ['analytics'];
        $this->assertFalse($this->service()->isValid($consent->consentId), 'the same answer as getCurrent() and the guard');
    }
}
