<?php
declare(strict_types=1);

namespace CommerceKings\Consent\Exception;

/** Another withdrawal for the same browser, purpose and website is running; nothing was changed - retry. */
class WithdrawalBusyException extends \RuntimeException
{
}
