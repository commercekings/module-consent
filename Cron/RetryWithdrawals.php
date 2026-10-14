<?php
declare(strict_types=1);

namespace CommerceKings\Consent\Cron;

use CommerceKings\Consent\Model\WithdrawalCleanup;
use CommerceKings\Consent\Model\WithdrawalCleanupStore;

/** Retry at most 100 due module actions per cron run. */
class RetryWithdrawals
{
    public function __construct(
        private readonly WithdrawalCleanupStore $store,
        private readonly WithdrawalCleanup $cleanup
    ) {
    }

    public function execute(): void
    {
        $this->cleanup->run($this->store->dueIds());
    }
}
