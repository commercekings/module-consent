<?php
declare(strict_types=1);

namespace CommerceKings\Consent\Test\Unit\ViewModel;

use CommerceKings\Consent\Api\BrowserIdentityInterface;
use CommerceKings\Consent\Api\Data\Consent;
use CommerceKings\Consent\Api\Data\SourceChoice;
use CommerceKings\Consent\Api\PurposeRegistryInterface;
use CommerceKings\Consent\Api\SourceInterface;
use CommerceKings\Consent\Model\ConfiguredSource;
use CommerceKings\Consent\Model\ConsentStore;
use CommerceKings\Consent\Model\WithdrawalLink;
use CommerceKings\Consent\ViewModel\Withdrawal;
use Magento\Framework\App\RequestInterface;
use PHPUnit\Framework\TestCase;

class WithdrawalTest extends TestCase
{
    private string $hash = 'original';
    private ?array $current = ['withdrawn_at' => '2026-01-01 00:00:00'];
    private bool $valid = true;
    private string $purpose = 'another_feature';
    private array $lookup = [];

    private function viewModel(): Withdrawal
    {
        $request = $this->createStub(RequestInterface::class);
        $request->method('getParam')->willReturnCallback(fn ($key) => ['c' => 'cid', 't' => 'signed'][$key]);
        $links = $this->createStub(WithdrawalLink::class);
        $links->method('getConsent')->willReturnCallback(fn () => $this->valid
            ? new Consent('cid', $this->purpose, 1, 'vanilla', 'choice', '2020-01-01 00:00:00',
                '1', '1', '2020-01-01 00:00:00', '2020-02-01 00:00:00') : null);
        $purposes = $this->createStub(PurposeRegistryInterface::class);
        $purposes->method('getLabel')->willReturnCallback(fn ($purpose) => 'Label: ' . $purpose);
        $store = $this->createStub(ConsentStore::class);
        $store->method('load')->willReturn(['browser_hash' => 'original']);
        $store->method('find')->willReturnCallback(function ($key) {
            $this->lookup = $key;
            return $this->current;
        });
        $source = $this->createStub(SourceInterface::class);
        $source->method('getCode')->willReturn('vanilla');
        $source->method('getChoice')->willReturn(new SourceChoice('current-choice', '2026-01-01 00:00:00', '1', []));
        $sources = $this->createStub(ConfiguredSource::class);
        $sources->method('get')->willReturn($source);
        $browser = $this->createStub(BrowserIdentityInterface::class);
        $browser->method('hash')->willReturnCallback(fn () => $this->hash);
        return new Withdrawal($request, $links, $purposes, $store, $sources, $browser);
    }

    public function testDisplaysLabelAndSyncsOnlyItsRecordedWithdrawnPurpose(): void
    {
        $view = $this->viewModel();
        $this->assertSame('Label: another_feature', $view->getPurposeLabel());
        $config = json_decode($view->getBannerConfigJson(), true);
        $this->assertSame(['*' => ['CommerceKings_Consent/js/withdrawal-banner' => ['purpose' => 'another_feature']]], $config);
        $this->assertSame(['purpose' => 'another_feature', 'browser_hash' => 'original', 'website_id' => 1,
            'source' => 'vanilla', 'reference' => 'current-choice', 'source_time' => '2026-01-01 00:00:00'], $this->lookup);
    }

    public function testAnotherBrowserIsNeverModified(): void
    {
        $view = $this->viewModel();
        $this->hash = 'different';
        $this->assertSame('{}', $view->getBannerConfigJson());
        $this->assertSame([], $this->lookup);
    }

    public function testBrowserWithoutIdentityIsNeverModified(): void
    {
        $view = $this->viewModel();
        $this->hash = '';
        $this->assertSame('{}', $view->getBannerConfigJson());
    }

    public function testLaterGrantedOrUnrecordedChoiceIsNeverModified(): void
    {
        $view = $this->viewModel();
        $this->current = ['withdrawn_at' => null];
        $this->assertSame('{}', $view->getBannerConfigJson());
        $this->current = null;
        $this->assertSame('{}', $view->getBannerConfigJson());
    }

    public function testInvalidLinkExposesNoPurposeOrBannerAction(): void
    {
        $view = $this->viewModel();
        $this->valid = false;
        $this->assertSame('', $view->getPurposeLabel());
        $this->assertSame('{}', $view->getBannerConfigJson());
    }

    public function testInlineConfigurationEscapesScriptTags(): void
    {
        $view = $this->viewModel();
        $this->purpose = '</script><script>alert(1)</script>';
        $json = $view->getBannerConfigJson();
        $this->assertStringNotContainsString('<', $json);
        $this->assertSame($this->purpose, json_decode($json, true)['*']['CommerceKings_Consent/js/withdrawal-banner']['purpose']);
    }
}
