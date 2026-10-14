<?php
declare(strict_types=1);

namespace CommerceKings\Consent\Model;

use CommerceKings\Consent\Api\Data\WithdrawalContext;
use Magento\Framework\Lock\LockManagerInterface;
use Psr\Log\LoggerInterface;

/** Runs committed cleanup tasks synchronously or from cron, independently of the visitor's session. */
class WithdrawalCleanup
{
    public function __construct(
        private readonly WithdrawalCleanupStore $store,
        private readonly WithdrawalPool $pool,
        private readonly LockManagerInterface $locks,
        private readonly LoggerInterface $logger
    ) {
    }

    /** @param int[] $taskIds */
    public function run(array $taskIds): void
    {
        foreach ($taskIds as $id) {
            try {
                $this->process($id);
            } catch (\Throwable $error) {
                // Even a storage/lock failure must not stop other handlers or undo withdrawal.
                $this->logger->error('Consent cleanup task could not be processed.', [
                    'task_id' => $id, 'exception_class' => get_class($error),
                ]);
            }
        }
    }

    private function process(int $id): void
    {
        $lock = 'ck_consent_cleanup_' . $id;
        if (!$this->locks->lock($lock, 0)) {
            return;
        }
        try {
            $task = $this->store->loadDue($id);
            if (!$task) {
                return;
            }
            try {
                $handler = $this->pool->get((string)$task['purpose'], (string)$task['handler']);
                if (!$handler) {
                    throw new \LogicException('The registered withdrawal handler is unavailable.');
                }
                $handler->execute(new WithdrawalContext(
                    (string)$task['purpose'], [(string)$task['consent_id']], (int)$task['website_id'],
                    (string)$task['browser_hash'], (string)$task['via']
                ));
                $this->store->complete($id);
            } catch (\Throwable $error) {
                $this->store->retry($id, (int)$task['attempts']);
                // Exception messages may contain module-owned PII; log only the class and task metadata.
                $this->logger->warning('Consent cleanup remains pending for retry.', [
                    'task_id' => $id, 'handler' => $task['handler'], 'exception_class' => get_class($error),
                ]);
            }
        } finally {
            $this->locks->unlock($lock);
        }
    }
}
