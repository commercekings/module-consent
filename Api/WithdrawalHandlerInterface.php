<?php
declare(strict_types=1);

namespace CommerceKings\Consent\Api;

use CommerceKings\Consent\Api\Data\WithdrawalContext;

/** Module-owned cleanup after a committed consent withdrawal. @api */
interface WithdrawalHandlerInterface
{
    /**
     * Clean up only data associated with these withdrawn consent IDs, never a later grant.
     * Must be idempotent: execution is at least once, including after a process interruption.
     * Throw on failure so Consent retains this action for retry. No visitor session is available in cron.
     */
    public function execute(WithdrawalContext $context): void;
}
