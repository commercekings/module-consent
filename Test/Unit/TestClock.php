<?php
declare(strict_types=1);

namespace CommerceKings\Consent\Test\Unit;

/**
 * Test dates relative to the real clock, so tests do not expire when the calendar passes a fixed date.
 * Day 0 is seven days ago (UTC), fixed for the whole run.
 */
final class TestClock
{
    private static ?string $day0 = null;

    /** "HH:MM:SS" on day 0 plus $days, e.g. at('09:00:00', 182) for a consent lifetime of 182 days. */
    public static function at(string $time, int $days = 0): string
    {
        self::$day0 ??= gmdate('Y-m-d', time() - 7 * 86400);
        return gmdate('Y-m-d', (int)strtotime(self::$day0 . ' 00:00:00 UTC') + $days * 86400) . ' ' . $time;
    }
}
