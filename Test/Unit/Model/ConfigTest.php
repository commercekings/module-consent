<?php
declare(strict_types=1);

namespace CommerceKings\Consent\Test\Unit\Model;

use CommerceKings\Consent\Model\Config;
use CommerceKings\Consent\Model\PurposePool;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\UrlInterface;
use PHPUnit\Framework\TestCase;

class ConfigTest extends TestCase
{
    private array $scopes = [];

    private function config(array $values): Config
    {
        $scope = $this->createStub(ScopeConfigInterface::class);
        $scope->method('getValue')->willReturnCallback(function ($path, $scopeType = 'default', $code = null) use ($values) {
            $this->scopes[] = [$scopeType, $code];
            return $values[$path] ?? null;
        });
        $url = $this->createStub(UrlInterface::class);
        $url->method('getDirectUrl')->willReturnCallback(fn ($path) => 'https://shop.test/' . $path);
        return new Config($scope, $url, new PurposePool([
            'cart_reminder' => ['label' => 'Reminder'],
            'analytics' => ['label' => 'Analytics', 'record' => 'optional'],
            'pixel' => ['label' => 'Pixel', 'record' => 'optional'],
        ]));
    }

    public function testSourceAndRetention(): void
    {
        $config = $this->config([
            'commercekings_consent/general/source' => ' vanilla-cookieconsent ',
            'commercekings_consent/general/keep_days' => '183',
        ]);
        $this->assertSame('vanilla-cookieconsent', $config->getSourceCode());
        $this->assertSame(183, $config->getKeepDays());
        $this->assertSame(1, $this->config([])->getKeepDays());
    }

    public function testWebsiteScopeWhenAWebsiteIsGiven(): void
    {
        $config = $this->config([]);
        $config->getSourceCode(3);
        $config->getSourceCode();
        $this->assertSame([['website', 3], ['store', null]], $this->scopes);
    }

    public function testPrivacyPageAsFullUrl(): void
    {
        $this->assertSame('https://shop.test/privacy', $this->config(['commercekings_consent/general/privacy_url' => ' /privacy '])->getPrivacyUrl());
        $this->assertSame('https://other.test/p', $this->config(['commercekings_consent/general/privacy_url' => 'https://other.test/p'])->getPrivacyUrl());
        $this->assertSame('', $this->config([])->getPrivacyUrl());
    }

    public function testCookieNameRevisionAndLifetimeAreTheShopsSettings(): void
    {
        $config = $this->config([
            'commercekings_consent/general/revision' => '3',
            'commercekings_consent/general/cookie_name' => ' ck_cookie_consent; x ',
            'commercekings_consent/general/cookie_lifetime_days' => '90',
        ]);
        $this->assertSame('ck_cookie_consentx', $config->getCookieName(), 'cookie-name characters only');
        $this->assertSame('cc_cookie', $this->config([])->getCookieName(), 'never empty');
        $this->assertSame('3', $config->getRevision());
        $this->assertSame(90, $config->getCookieLifetimeDays());
        $this->assertSame('1', $this->config([])->getRevision(), 'never below 1');
        $this->assertSame(1, $this->config([])->getCookieLifetimeDays());
    }

    public function testRequiredPurposesAreAlwaysRecordedOptionalOnesOnlyWhenTicked(): void
    {
        $config = $this->config([]);
        $this->assertTrue($config->isRecorded('cart_reminder'), 'recording required by default');
        $this->assertFalse($config->isRecorded('analytics'), 'optional purposes: not recorded by default');
        $this->assertSame(['cart_reminder'], $config->getRecordedPurposes());

        $config = $this->config(['commercekings_consent/general/record_purposes' => 'analytics,cart_reminder']);
        $this->assertTrue($config->isRecorded('analytics'));
        $this->assertFalse($config->isRecorded('pixel'));
        $this->assertSame(['cart_reminder', 'analytics'], $config->getRecordedPurposes(2));
        $this->assertContains(['website', 2], $this->scopes);
    }

    public function testOnlyAnExplicitOptionalMakesRecordingOptional(): void
    {
        $pool = new PurposePool(['a' => [], 'b' => ['record' => 'Optional'], 'c' => ['record' => 'optional'], 'd' => ['record' => 'required']]);
        $this->assertSame([false, false, true, false], array_map([$pool, 'isRecordOptional'], ['a', 'b', 'c', 'd']));
    }
}
