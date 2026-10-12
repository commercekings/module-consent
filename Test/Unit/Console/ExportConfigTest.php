<?php
declare(strict_types=1);

namespace CommerceKings\Consent\Test\Unit\Console;

use CommerceKings\Consent\Console\Command\ExportConfig;
use CommerceKings\Consent\Model\Evidence;
use Magento\Framework\App\Area;
use Magento\Framework\App\State;
use Magento\Store\Model\App\Emulation;
use Magento\Store\Model\Store;
use Magento\Store\Model\StoreManagerInterface;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Tester\CommandTester;

class ExportConfigTest extends TestCase
{
    public function testExportPreservesPolicyMarkupAndRestoresTheStoreEnvironment(): void
    {
        $policy = '<info>Published policy wording</info>';
        $file = tempnam(sys_get_temp_dir(), 'ck-policy-');
        file_put_contents($file, $policy);
        try {
            $evidence = $this->createMock(Evidence::class);
            $evidence->expects($this->once())->method('snapshot')->with($policy)->willReturn(['policy_content' => $policy]);
            $emulation = $this->createMock(Emulation::class);
            $emulation->expects($this->once())->method('startEnvironmentEmulation')->with(7, Area::AREA_FRONTEND, true);
            $emulation->expects($this->once())->method('stopEnvironmentEmulation');
            $store = $this->createMock(Store::class);
            $store->method('getId')->willReturn(7);
            $store->method('getCode')->willReturn('shop');
            $store->method('getWebsiteId')->willReturn(3);
            $store->method('getConfig')->with('general/locale/code')->willReturn('en_US');
            $stores = $this->createMock(StoreManagerInterface::class);
            $stores->method('getStore')->with('shop')->willReturn($store);
            $state = $this->createMock(State::class);
            $state->expects($this->once())->method('emulateAreaCode')->with(Area::AREA_FRONTEND, $this->callback('is_callable'))
                ->willReturnCallback(fn ($area, $callback) => $callback());
            $tester = new CommandTester(new ExportConfig($evidence, $emulation, $stores, $state));
            $this->assertSame(0, $tester->execute(['--store' => 'shop', '--policy-file' => $file]));
            $json = json_decode($tester->getDisplay(), true, 512, JSON_THROW_ON_ERROR);
            $this->assertSame($policy, $json['policy_content'], 'Symfony must not interpret policy tags as console styles');
            $this->assertSame(['id' => 7, 'code' => 'shop', 'website_id' => 3, 'locale' => 'en_US'], $json['store']);
        } finally {
            unlink($file);
        }
    }

    public function testStoreScopeMustBeExplicit(): void
    {
        $emulation = $this->createMock(Emulation::class);
        $emulation->expects($this->never())->method('startEnvironmentEmulation');
        $tester = new CommandTester(new ExportConfig($this->createStub(Evidence::class), $emulation,
            $this->createStub(StoreManagerInterface::class), $this->createStub(State::class)));
        $this->expectException(\InvalidArgumentException::class);
        $tester->execute([]);
    }
}
