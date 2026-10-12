<?php
declare(strict_types=1);

namespace CommerceKings\Consent\Test\Unit\Model;

use CommerceKings\Consent\Api\BannerConfigInterface;
use CommerceKings\Consent\Api\SourceInterface;
use CommerceKings\Consent\Model\Config;
use CommerceKings\Consent\Model\ConfiguredSource;
use CommerceKings\Consent\Model\Evidence;
use CommerceKings\Consent\Model\PurposePool;
use CommerceKings\Consent\Model\SourcePool;
use PHPUnit\Framework\TestCase;

class EvidenceTest extends TestCase
{
    public function testExportPreservesQuestionsMappingsTermsAndTheSuppliedPolicyWithoutReadingVisitorChoices(): void
    {
        $source = $this->createMock(SourceInterface::class);
        $source->method('getCode')->willReturn('banner');
        $source->method('getVersion')->willReturn('2.0');
        $source->method('getCategories')->willReturn(['emails' => ['label' => 'Reminder emails', 'description' => 'Use my checkout email for a reminder', 'purposes' => ['cart_reminder']]]);
        $source->expects($this->never())->method('getChoice');
        $configured = $this->createStub(ConfiguredSource::class);
        $configured->method('get')->willReturn($source);
        $configured->method('getClientConfig')->willReturn(['revision' => 3]);
        $banner = $this->createStub(BannerConfigInterface::class);
        $banner->method('getConfigJson')->willReturn('{"texts":{"accept":"Accept","reject":"Reject"}}');
        $config = $this->createStub(Config::class);
        $config->method('getRevision')->willReturn('3');
        $config->method('getPrivacyUrl')->willReturn('https://shop.test/privacy');
        $config->method('getCookieLifetimeDays')->willReturn(30);
        $config->method('getKeepDays')->willReturn(60);
        $evidence = new Evidence($configured, new SourcePool(['banner' => ['banner' => $banner]]), new PurposePool(['cart_reminder' => ['terms_revision' => '4']]), $config);
        $snapshot = $evidence->snapshot('Published policy version 3');
        $this->assertSame('3', $snapshot['banner_revision']);
        $this->assertSame(['cart_reminder'], $snapshot['categories']['emails']['purposes']);
        $this->assertSame('4', $snapshot['purposes']['cart_reminder']['terms_revision']);
        $this->assertSame('Reject', $snapshot['banner_config']['texts']['reject']);
        $this->assertSame('Published policy version 3', $snapshot['policy_content']);
        $this->assertSame(hash('sha256', 'Published policy version 3'), $snapshot['policy_sha256']);
        $this->assertSame(60, $snapshot['retention']['keep_after_expiry_or_withdrawal_days']);
    }

    public function testMissingSourceBannerAndPolicyAreExplicitlyAbsent(): void
    {
        $configured = $this->createStub(ConfiguredSource::class);
        $configured->method('get')->willReturn(null);
        $config = $this->createStub(Config::class);
        $snapshot = (new Evidence($configured, new SourcePool(), new PurposePool(), $config))->snapshot();
        $this->assertNull($snapshot['source']);
        $this->assertNull($snapshot['banner_config']);
        $this->assertNull($snapshot['policy_content']);
        $this->assertNull($snapshot['policy_sha256']);
    }
}
