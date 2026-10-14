<?php
declare(strict_types=1);

namespace CommerceKings\Consent\Test\Unit\Model;

use CommerceKings\Consent\Api\WithdrawalHandlerInterface;
use CommerceKings\Consent\Model\WithdrawalPool;
use PHPUnit\Framework\TestCase;

class WithdrawalPoolTest extends TestCase
{
    public function testSeveralHandlersCanShareAPurposeWithoutMatchingOtherPurposes(): void
    {
        $handler = $this->createStub(WithdrawalHandlerInterface::class);
        $pool = new WithdrawalPool(['reminders' => ['first' => $handler, 'second' => $handler]]);
        $this->assertSame(['first', 'second'], $pool->getNames('reminders'));
        $this->assertSame($handler, $pool->get('reminders', 'first'));
        $this->assertNull($pool->get('analytics', 'first'));
        $this->assertSame([], $pool->getNames('analytics'));
    }

    public function testCoreWorksWithoutFeatureHandlers(): void
    {
        $this->assertSame([], (new WithdrawalPool())->getNames('reminders'));
    }

    public function testInvalidRegistrationFailsRatherThanDroppingCleanupSilently(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new WithdrawalPool(['reminders' => ['invalid' => new \stdClass()]]);
    }
}
