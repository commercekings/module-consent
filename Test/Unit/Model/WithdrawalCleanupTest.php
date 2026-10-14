<?php
declare(strict_types=1);

namespace CommerceKings\Consent\Test\Unit\Model;

use CommerceKings\Consent\Api\Data\WithdrawalContext;
use CommerceKings\Consent\Api\WithdrawalHandlerInterface;
use CommerceKings\Consent\Model\WithdrawalCleanup;
use CommerceKings\Consent\Model\WithdrawalCleanupStore;
use CommerceKings\Consent\Model\WithdrawalPool;
use Magento\Framework\Lock\LockManagerInterface;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

class WithdrawalCleanupTest extends TestCase
{
    private function task(): array
    {
        return ['task_id' => 1, 'consent_id' => 'old-consent', 'handler' => 'feature', 'purpose' => 'reminders',
            'website_id' => 2, 'browser_hash' => 'original-browser', 'via' => 'email', 'attempts' => 3];
    }

    private function locks(bool $available = true): LockManagerInterface
    {
        $locks = $this->createMock(LockManagerInterface::class);
        $locks->expects($this->once())->method('lock')->with('ck_consent_cleanup_1', 0)->willReturn($available);
        $locks->expects($available ? $this->once() : $this->never())->method('unlock')->with('ck_consent_cleanup_1');
        return $locks;
    }

    public function testSuccessReceivesOriginalScopeAndRemovesOnlyItsTask(): void
    {
        $store = $this->createMock(WithdrawalCleanupStore::class);
        $store->expects($this->once())->method('loadDue')->with(1)->willReturn($this->task());
        $store->expects($this->once())->method('complete')->with(1);
        $store->expects($this->never())->method('retry');
        $handler = $this->createMock(WithdrawalHandlerInterface::class);
        $handler->expects($this->once())->method('execute')->with($this->callback(function (WithdrawalContext $context) {
            return $context->purpose === 'reminders' && $context->consentIds === ['old-consent']
                && $context->websiteId === 2 && $context->browserHash === 'original-browser' && $context->via === 'email';
        }));
        (new WithdrawalCleanup($store, new WithdrawalPool(['reminders' => ['feature' => $handler]]),
            $this->locks(), new NullLogger()))->run([1]);
    }

    public function testFailureRetainsTaskAndDoesNotLogExceptionMessages(): void
    {
        $store = $this->createMock(WithdrawalCleanupStore::class);
        $store->method('loadDue')->willReturn($this->task());
        $store->expects($this->never())->method('complete');
        $store->expects($this->once())->method('retry')->with(1, 3);
        $handler = $this->createStub(WithdrawalHandlerInterface::class);
        $handler->method('execute')->willThrowException(new \RuntimeException('private@example.test'));
        $logger = $this->createMock(\Psr\Log\LoggerInterface::class);
        $logger->expects($this->once())->method('warning')->with(
            'Consent cleanup remains pending for retry.',
            ['task_id' => 1, 'handler' => 'feature', 'exception_class' => \RuntimeException::class]
        );
        (new WithdrawalCleanup($store, new WithdrawalPool(['reminders' => ['feature' => $handler]]),
            $this->locks(), $logger))->run([1]);
    }

    public function testTemporarilyMissingModuleKeepsItsTaskForRetry(): void
    {
        $store = $this->createMock(WithdrawalCleanupStore::class);
        $store->method('loadDue')->willReturn($this->task());
        $store->expects($this->never())->method('complete');
        $store->expects($this->once())->method('retry')->with(1, 3);
        (new WithdrawalCleanup($store, new WithdrawalPool(), $this->locks(), new NullLogger()))->run([1]);
    }

    public function testConcurrentWorkerDoesNotRunLockedTask(): void
    {
        $store = $this->createMock(WithdrawalCleanupStore::class);
        $store->expects($this->never())->method('loadDue');
        (new WithdrawalCleanup($store, new WithdrawalPool(), $this->locks(false), new NullLogger()))->run([1]);
    }

    public function testStaleWorkerDoesNotRepeatCompletedOrNotYetDueTask(): void
    {
        $store = $this->createMock(WithdrawalCleanupStore::class);
        $store->method('loadDue')->willReturn(null);
        $store->expects($this->never())->method('complete');
        $store->expects($this->never())->method('retry');
        (new WithdrawalCleanup($store, new WithdrawalPool(), $this->locks(), new NullLogger()))->run([1]);
    }

    public function testStorageFailureDoesNotPreventAnotherModuleFromRunning(): void
    {
        $store = $this->createMock(WithdrawalCleanupStore::class);
        $store->method('loadDue')->willReturnCallback(function ($id) {
            if ($id === 1) { throw new \RuntimeException('storage failure'); }
            return $this->task();
        });
        $store->expects($this->once())->method('complete')->with(2);
        $handler = $this->createMock(WithdrawalHandlerInterface::class);
        $handler->expects($this->once())->method('execute');
        $locks = $this->createStub(LockManagerInterface::class);
        $locks->method('lock')->willReturn(true);
        (new WithdrawalCleanup($store, new WithdrawalPool(['reminders' => ['feature' => $handler]]),
            $locks, new NullLogger()))->run([1, 2]);
    }
}
