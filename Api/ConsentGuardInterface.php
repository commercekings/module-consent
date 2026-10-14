<?php
declare(strict_types=1);

namespace CommerceKings\Consent\Api;

/**
 * For features that must not act on a withdrawn consent, even when the withdrawal happens at the same
 * moment: put this condition into the same UPDATE that stores or uses the consent, e.g.
 *
 *   UPDATE quote SET reminded_at = NOW() WHERE entity_id = 42 AND <validCondition('quote.consent_id')>
 *
 * The database then orders it against a withdrawal: either the update sees the consent withdrawn and
 * changes nothing, or it finishes first and the withdrawal event (fired after the consent is marked)
 * finds the row.
 */
interface ConsentGuardInterface
{
    /**
     * SQL condition: the consent id in $consentIdColumn is valid (same rules as ConsentInterface::isValid()).
     * @param string $consentIdColumn Trusted developer-supplied SQL identifier/expression or a value quoted
     *     with Magento's database adapter. Never pass raw request input as an SQL expression.
     */
    public function validCondition(string $consentIdColumn): string;
}
