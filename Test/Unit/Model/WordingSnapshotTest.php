<?php
declare(strict_types=1);

namespace CommerceKings\Consent\Test\Unit\Model;

use CommerceKings\Consent\Model\ConsentStore;
use CommerceKings\Consent\Model\Evidence;
use CommerceKings\Consent\Model\WordingSnapshot;
use Magento\Cms\Api\Data\PageInterface;
use Magento\Cms\Api\GetPageByIdentifierInterface;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Store\Api\Data\StoreInterface;
use Magento\Store\Model\StoreManagerInterface;
use PHPUnit\Framework\TestCase;

class WordingSnapshotTest extends TestCase
{
    private array $evidence = [
        'source' => 'vanilla',
        'source_version' => '3.1.0',
        'banner_revision' => '2',
        'categories' => ['analytics' => ['label' => 'Analytics', 'purposes' => ['analytics']]],
        'purposes' => ['analytics' => ['label' => 'Analytics', 'terms_revision' => '1']],
        'banner_config' => ['texts' => ['banner_accept' => 'Accept all']],
        'privacy_url' => 'https://shop.test/privacy',
        'exported_at' => 'varies',
        'retention' => ['keep_after_expiry_or_withdrawal_days' => 30],
    ];
    private string $privacyKey = 'privacy';
    private ?string $pageContent = '<p>Policy v1</p>';
    private array $saved = [];

    public function testSameWordingGivesTheSameHashAndAChangedTextANewOne(): void
    {
        $first = $this->snapshot()->currentHash();
        $this->assertSame($first, $this->snapshot()->currentHash());
        $this->evidence['banner_config']['texts']['banner_accept'] = 'Accept everything';
        $this->assertNotSame($first, $this->snapshot()->currentHash());
    }

    public function testExportTimeAndRetentionAreNotPartOfTheWording(): void
    {
        $first = $this->snapshot()->currentHash();
        $this->evidence['exported_at'] = 'later';
        $this->evidence['retention']['keep_after_expiry_or_withdrawal_days'] = 90;
        $this->assertSame($first, $this->snapshot()->currentHash());
    }

    public function testThePrivacyPageOfTheShopIsPartOfTheWording(): void
    {
        $snapshot = $this->snapshot();
        $this->assertSame('<p>Policy v1</p>', $snapshot->content()['privacy_page']['content']);
        $first = $snapshot->currentHash();
        $this->pageContent = '<p>Policy v2</p>';
        $this->assertNotSame($first, $this->snapshot()->currentHash());
    }

    public function testAnExternalOrMissingPrivacyPageIsOnlyTheUrl(): void
    {
        $this->privacyKey = 'https://elsewhere.test/privacy';
        $this->assertNull($this->snapshot()->content()['privacy_page']);
        $this->privacyKey = 'missing';
        $this->pageContent = null;
        $this->assertNull($this->snapshot()->content()['privacy_page']);
    }

    public function testTheWordingIsSavedOncePerRequestWithItsWebsiteStoreAndRevision(): void
    {
        $snapshot = $this->snapshot();
        $hash = $snapshot->currentHash();
        $snapshot->currentHash();
        $this->assertCount(1, $this->saved);
        [$savedHash, $websiteId, $storeId, $revision, $json] = $this->saved[0];
        $this->assertSame([$hash, 1, 3, '2'], [$savedHash, $websiteId, $storeId, $revision]);
        $this->assertSame($hash, hash('sha256', $json));
        $this->assertSame('sr_Latn_RS', json_decode($json, true)['store']['locale']);
    }

    private function snapshot(): WordingSnapshot
    {
        $evidence = $this->createMock(Evidence::class);
        $evidence->method('snapshot')->willReturnCallback(fn () => $this->evidence);
        $consents = $this->createMock(ConsentStore::class);
        $consents->method('saveSnapshot')->willReturnCallback(function (...$args): void {
            $this->saved[] = $args;
        });
        $store = $this->createMock(StoreInterface::class);
        $store->method('getId')->willReturn(3);
        $store->method('getCode')->willReturn('sr');
        $store->method('getWebsiteId')->willReturn(1);
        $stores = $this->createMock(StoreManagerInterface::class);
        $stores->method('getStore')->willReturn($store);
        $scope = $this->createMock(ScopeConfigInterface::class);
        $scope->method('getValue')->willReturnCallback(fn (string $path) => match ($path) {
            'general/locale/code' => 'sr_Latn_RS',
            'commercekings_consent/general/privacy_url' => $this->privacyKey,
            default => null,
        });
        $pages = $this->createMock(GetPageByIdentifierInterface::class);
        $pages->method('execute')->willReturnCallback(function (string $key, int $storeId): PageInterface {
            if ($this->pageContent === null || $storeId !== 3) {
                throw new NoSuchEntityException();
            }
            $page = $this->createMock(PageInterface::class);
            $page->method('getTitle')->willReturn('Privacy');
            $page->method('getContent')->willReturn($this->pageContent);
            return $page;
        });
        return new WordingSnapshot($evidence, $consents, $stores, $scope, $pages);
    }
}
