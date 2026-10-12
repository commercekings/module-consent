<?php
declare(strict_types=1);

namespace CommerceKings\Consent\Test\Unit\Cron;

use CommerceKings\Consent\Cron\CleanUp;
use CommerceKings\Consent\Model\Config;
use CommerceKings\Consent\Model\ConsentStore;
use Magento\Store\Api\Data\WebsiteInterface;
use Magento\Store\Model\StoreManagerInterface;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

class CleanupTest extends TestCase
{
    public function testWithdrawalMarksOutliveTheLongestWebsiteCookieEvenWithShortEvidenceRetention(): void
    {
        $config = $this->createStub(Config::class);
        $config->method('getKeepDays')->willReturn(30);
        $config->method('getCookieLifetimeDays')->willReturnMap([[1, 90], [2, 365]]);
        $websites = [];
        foreach ([1, 2] as $id) {
            $website = $this->createStub(WebsiteInterface::class);
            $website->method('getId')->willReturn($id);
            $websites[] = $website;
        }
        $stores = $this->createStub(StoreManagerInterface::class);
        $stores->method('getWebsites')->willReturn($websites);
        $store = $this->createMock(ConsentStore::class);
        $store->expects($this->once())->method('cleanUp')->with(30, 366)->willReturn(3);
        $this->assertSame(3, (new CleanUp($store, $config, new NullLogger(), $stores))->execute());
    }
}
