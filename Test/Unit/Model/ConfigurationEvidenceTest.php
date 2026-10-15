<?php
declare(strict_types=1);

namespace CommerceKings\Consent\Test\Unit\Model;

use CommerceKings\Consent\Model\ConfigurationEvidence;
use CommerceKings\Consent\Model\Evidence;
use CommerceKings\Consent\Model\WordingSnapshot;
use Magento\Framework\App\Area;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\App\State;
use Magento\Store\Api\Data\StoreInterface;
use Magento\Store\Model\App\Emulation;
use Magento\Store\Model\ScopeInterface;
use Magento\Store\Model\StoreManagerInterface;
use PHPUnit\Framework\TestCase;

class ConfigurationEvidenceTest extends TestCase
{
    public function testConfiguredCmsPolicyIsIncludedInTheStorefrontScope(): void
    {
        $emulation = $this->environment();
        $evidence = $this->createMock(Evidence::class);
        $evidence->expects($this->once())->method('snapshot')->with('<p>Policy</p>')->willReturn(['policy_content' => '<p>Policy</p>']);
        $wording = $this->createMock(WordingSnapshot::class);
        $wording->expects($this->once())->method('content')->willReturn(['privacy_page' => ['content' => '<p>Policy</p>']]);
        $snapshot = $this->service($evidence, $wording, $emulation)->get('shop');
        $this->assertSame('<p>Policy</p>', $snapshot['policy_content']);
        $this->assertSame(['id' => 7, 'code' => 'shop', 'website_id' => 3, 'locale' => 'en_US'], $snapshot['store']);
    }

    public function testExplicitPolicyOverridesCmsLookup(): void
    {
        $evidence = $this->createMock(Evidence::class);
        $evidence->expects($this->once())->method('snapshot')->with('explicit')->willReturn(['policy_content' => 'explicit']);
        $wording = $this->createMock(WordingSnapshot::class);
        $wording->expects($this->never())->method('content');
        $this->assertSame('explicit', $this->service($evidence, $wording, $this->environment())->get('shop', 'explicit')['policy_content']);
    }

    public function testEnvironmentIsRestoredWhenBuildingEvidenceFails(): void
    {
        $evidence = $this->createMock(Evidence::class);
        $evidence->expects($this->once())->method('snapshot')->willThrowException(new \RuntimeException('failure'));
        $this->expectException(\RuntimeException::class);
        $this->service($evidence, $this->createStub(WordingSnapshot::class), $this->environment())->get('shop', 'explicit');
    }

    public function testAdminStoreIsRejectedBeforeEmulation(): void
    {
        $stores = $this->createStub(StoreManagerInterface::class);
        $store = $this->createStub(StoreInterface::class);
        $store->method('getId')->willReturn(0);
        $stores->method('getStore')->willReturn($store);
        $emulation = $this->createMock(Emulation::class);
        $emulation->expects($this->never())->method('startEnvironmentEmulation');
        $service = new ConfigurationEvidence($this->createStub(Evidence::class), $emulation, $stores,
            $this->createStub(State::class), $this->createStub(ScopeConfigInterface::class), $this->createStub(WordingSnapshot::class));
        $this->expectException(\InvalidArgumentException::class);
        $service->get(0);
    }

    private function environment(): Emulation
    {
        $emulation = $this->createMock(Emulation::class);
        $emulation->expects($this->once())->method('startEnvironmentEmulation')->with(7, Area::AREA_FRONTEND, true);
        $emulation->expects($this->once())->method('stopEnvironmentEmulation');
        return $emulation;
    }

    private function service(Evidence $evidence, WordingSnapshot $wording, Emulation $emulation): ConfigurationEvidence
    {
        $store = $this->createStub(StoreInterface::class);
        $store->method('getId')->willReturn(7);
        $store->method('getCode')->willReturn('shop');
        $store->method('getWebsiteId')->willReturn(3);
        $stores = $this->createStub(StoreManagerInterface::class);
        $stores->method('getStore')->willReturn($store);
        $scope = $this->createStub(ScopeConfigInterface::class);
        $scope->method('getValue')->willReturnCallback(fn ($path, $type, $id) =>
            $path === 'general/locale/code' && $type === ScopeInterface::SCOPE_STORE && $id === 7 ? 'en_US' : null);
        $state = $this->createStub(State::class);
        $state->method('emulateAreaCode')->willReturnCallback(fn ($area, $callback) => $callback());
        return new ConfigurationEvidence($evidence, $emulation, $stores, $state, $scope, $wording);
    }
}
