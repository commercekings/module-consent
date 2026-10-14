<?php
declare(strict_types=1);

namespace CommerceKings\Consent\Api\Data;

/** The original scope of a committed withdrawal; contains no feature data or email. @api */
class WithdrawalContext
{
    /** @param string[] $consentIds */
    public function __construct(
        public readonly string $purpose,
        public readonly array $consentIds,
        public readonly int $websiteId,
        public readonly string $browserHash,
        public readonly string $via
    ) {
    }
}
