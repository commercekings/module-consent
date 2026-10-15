<?php
declare(strict_types=1);

namespace CommerceKings\Consent\Test\Unit\Model;

use CommerceKings\Consent\Api\BrowserIdentityInterface;
use CommerceKings\Consent\Model\ConsentOwnership;
use CommerceKings\Consent\Model\ConsentStore;
use PHPUnit\Framework\TestCase;

class ConsentOwnershipTest extends TestCase
{
    private array $loads = [];

    private function ownership(string $browser): ConsentOwnership
    {
        $store = $this->createStub(ConsentStore::class);
        $store->method('load')->willReturnCallback(function ($id) {
            $this->loads[] = $id;
            return match ($id) {
                'mine' => ['browser_hash' => 'aaa'], 'blank' => ['browser_hash' => ''], default => null,
            };
        });
        $identity = $this->createStub(BrowserIdentityInterface::class);
        $identity->method('hash')->willReturn($browser);
        $identity->method('ensure')->willThrowException(new \LogicException('must not create a browser id'));
        return new ConsentOwnership($store, $identity);
    }

    /** @return array<string, array{string, string, bool}> */
    public static function cases(): array
    {
        return [
            'same browser' => ['mine', 'aaa', true],
            'another browser' => ['mine', 'bbb', false],
            'a browser without an id' => ['mine', '', false],
            'a consent without a browser' => ['blank', 'aaa', false],
            'a deleted consent' => ['gone', 'aaa', false],
            'no consent id' => ['', 'aaa', false],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('cases')]
    public function testAConsentBelongsToTheBrowserItWasGivenIn(string $consentId, string $browser, bool $expected): void
    {
        $this->assertSame($expected, $this->ownership($browser)->isCurrentVisitor($consentId));
    }

    public function testAnEmptyIdIsNotLookedUp(): void
    {
        $this->ownership('aaa')->isCurrentVisitor('');
        $this->assertSame([], $this->loads);
    }
}
