<?php
declare(strict_types=1);

namespace CommerceKings\Consent\Model\CookieFormat;

use CommerceKings\Consent\Api\CookieFormatInterface;

/**
 * Default cookie reader: a banner's cookie as a decoded array, read by paths (dots for nesting). A reader
 * for one library version only declares where each value sits:
 *
 *   VERSION     the library version the reader was written for
 *   CATEGORIES  accepted categories - a list ["a", "b"] or a map {"a": true, "b": false}
 *   REFERENCE   the banner's id for this visitor's choices (required - without it the cookie isn't read)
 *   TIME        when the choice was saved - the first path that has a value wins
 *   REVISION    the revision the choice was made for
 *   EXPIRES     when the banner forgets the choice, in milliseconds
 *
 * Override a method only when a value needs more than a path.
 */
abstract class AbstractCookieFormat implements CookieFormatInterface
{
    protected const VERSION = '';
    protected const CATEGORIES = 'categories';
    protected const REFERENCE = 'consentId';
    protected const TIME = ['lastConsentTimestamp', 'consentTimestamp'];
    protected const REVISION = 'revision';
    protected const EXPIRES = 'expirationTime';

    public function getVersion(): string
    {
        return static::VERSION;
    }

    public function matches(array $cookie): bool
    {
        return is_array($this->value($cookie, static::CATEGORIES)) && $this->reference($cookie) !== '';
    }

    public function acceptedCategories(array $cookie): array
    {
        $categories = (array)$this->value($cookie, static::CATEGORIES);
        if (!array_is_list($categories)) {
            // {"category": true|false}
            $categories = array_keys(array_filter($categories, fn ($on) => $on === true));
        }
        return array_values(array_map('strval', array_filter($categories, fn ($name) => is_string($name) || is_int($name))));
    }

    public function consentTime(array $cookie): string
    {
        foreach (static::TIME as $path) {
            $value = $this->value($cookie, $path);
            if ($value === null) {
                continue;
            }
            if (!is_string($value)) {
                return '';
            }
            $time = strtotime($value);
            if ($time) {
                return gmdate('Y-m-d H:i:s', $time);
            }
        }
        return '';
    }

    public function reference(array $cookie): string
    {
        $value = $this->value($cookie, static::REFERENCE);
        return is_string($value) || is_int($value)
            ? substr((string)preg_replace('/[^a-z0-9-]/i', '', (string)$value), 0, 80) : '';
    }

    public function revision(array $cookie): string
    {
        $value = $this->value($cookie, static::REVISION);
        return is_string($value) || is_int($value) ? (string)(int)$value : '0';
    }

    public function expiresAt(array $cookie): string
    {
        $ms = $this->value($cookie, static::EXPIRES);
        return is_numeric($ms) && $ms > 0 ? gmdate('Y-m-d H:i:s', (int)($ms / 1000)) : '';
    }

    /** The value at a dotted path, null when missing. */
    protected function value(array $cookie, string $path): mixed
    {
        $value = $cookie;
        foreach (explode('.', $path) as $key) {
            if (!is_array($value) || !array_key_exists($key, $value)) {
                return null;
            }
            $value = $value[$key];
        }
        return $value;
    }
}
