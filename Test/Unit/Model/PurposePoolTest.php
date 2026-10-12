<?php
declare(strict_types=1);

namespace CommerceKings\Consent\Test\Unit\Model;

use CommerceKings\Consent\Model\Config\Source\OptionalPurposes;
use CommerceKings\Consent\Model\PurposePool;
use Magento\Framework\App\Config\ScopeConfigInterface;
use PHPUnit\Framework\TestCase;

class PurposePoolTest extends TestCase
{
    private array $flags = [];

    private function pool(): PurposePool
    {
        $scope = $this->createStub(ScopeConfigInterface::class);
        $scope->method('isSetFlag')->willReturnCallback(fn ($path) => $this->flags[$path] ?? false);
        return new PurposePool([
            'cart_reminder' => ['label' => 'Reminder'],
            'analytics' => ['label' => 'Analytics', 'record' => 'optional',
                'active_if' => ['ga' => 'google/analytics/active', 'gcm' => 'consent_mode/enabled']],
            'marketing' => ['label' => 'Marketing', 'record' => 'optional', 'active_if' => ['gcm' => 'consent_mode/enabled']],
        ], $scope);
    }

    public function testAPurposeWithoutActiveIfIsAlwaysActive(): void
    {
        $this->assertTrue($this->pool()->isDeclared('cart_reminder'));
        $this->assertSame(['cart_reminder'], $this->pool()->getCodes());
    }

    public function testActiveWhileAnyOfItsSettingsIsOn(): void
    {
        $this->flags = ['google/analytics/active' => true];
        $this->assertSame(['cart_reminder', 'analytics'], $this->pool()->getCodes());
        $this->assertFalse($this->pool()->isDeclared('marketing'), 'inactive = not declared: hidden and never granted');
        $this->flags = ['consent_mode/enabled' => true];
        $this->assertSame(['cart_reminder', 'analytics', 'marketing'], $this->pool()->getCodes());
    }

    public function testAdminListsEveryOptionalPurposeEvenWhenInactive(): void
    {
        $this->assertSame(['cart_reminder', 'analytics', 'marketing'], $this->pool()->getAllCodes());
        $this->assertSame(['analytics', 'marketing'], array_column((new OptionalPurposes($this->pool()))->toOptionArray(), 'value'));
    }

    public function testWithoutConfigEverythingIsActive(): void
    {
        $pool = new PurposePool(['marketing' => ['active_if' => ['x' => 'some/flag']]]);
        $this->assertTrue($pool->isDeclared('marketing'));
    }
}
