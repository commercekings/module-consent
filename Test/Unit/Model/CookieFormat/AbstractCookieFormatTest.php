<?php
declare(strict_types=1);

namespace CommerceKings\Consent\Test\Unit\Model\CookieFormat;

use CommerceKings\Consent\Model\CookieFormat\AbstractCookieFormat;
use PHPUnit\Framework\TestCase;

class AbstractCookieFormatTest extends TestCase
{
    private function format(): AbstractCookieFormat
    {
        // a reader that only declares paths, nested
        return new class extends AbstractCookieFormat {
            protected const VERSION = '9.0.0';
            protected const CATEGORIES = 'state.on';
            protected const REFERENCE = 'meta.id';
            protected const TIME = ['meta.saved', 'meta.first'];
            protected const REVISION = 'meta.rev';
            protected const EXPIRES = 'meta.until';
        };
    }

    public function testReadsEveryValueByItsPath(): void
    {
        $cookie = ['state' => ['on' => ['a' => true, 'b' => false, 'c' => true]],
            'meta' => ['id' => "x1'<y>", 'first' => '2026-10-11T10:00:00Z', 'rev' => '2', 'until' => 1807631509815]];
        $format = $this->format();
        $this->assertSame('9.0.0', $format->getVersion());
        $this->assertTrue($format->matches($cookie));
        $this->assertSame(['a', 'c'], $format->acceptedCategories($cookie), 'map {category: bool}');
        $this->assertSame('2026-10-11 10:00:00', $format->consentTime($cookie), 'first time path with a value');
        $this->assertSame('x1y', $format->reference($cookie), 'sanitized');
        $this->assertSame('2', $format->revision($cookie));
        $this->assertSame('2027-04-13 15:51:49', $format->expiresAt($cookie));
    }

    /** Times MySQL cannot store would become 0000-00-00 rows: a bad time is no time. */
    public function testTimesOutsideTheStorableRangeAreIgnored(): void
    {
        $format = $this->format();
        $this->assertSame('', $format->consentTime(['meta' => ['saved' => '1969-12-31T23:59:59Z']]), 'before 1970');
        $this->assertSame('', $format->consentTime(['meta' => ['saved' => '2040-01-01T00:00:00Z']]), 'after 2038');
        $this->assertSame('', $format->expiresAt(['meta' => ['until' => 100000000000000]]), 'a five-digit year');
        $this->assertSame('2038-01-19 03:14:07', $format->expiresAt(['meta' => ['until' => 2147483647000]]), 'the last second');
        $this->assertSame('2026-10-11 10:00:00', $format->consentTime(['meta' => ['saved' => '2026-10-11T10:00:00Z']]));
    }

    public function testListOfCategoriesAndMissingValues(): void
    {
        $format = $this->format();
        $this->assertSame(['a', 'b'], $format->acceptedCategories(['state' => ['on' => ['a', 'b']]]), 'list');
        $this->assertFalse($format->matches(['state' => ['on' => ['a']]]), 'no reference - not read');
        $this->assertFalse($format->matches(['meta' => ['id' => 'x']]), 'no categories');
        $this->assertSame('', $format->consentTime([]));
        $this->assertSame('0', $format->revision([]));
        $this->assertSame('', $format->expiresAt(['meta' => ['until' => 'soon']]));
    }

    public function testNestedArraysAreRejectedWithoutCastingWarningsOrFallbackGrants(): void
    {
        $format = $this->format();
        $cookie = ['meta' => ['id' => ['fake'], 'saved' => ['fake'], 'first' => '2026-10-11T10:00:00Z', 'rev' => [1]]];
        $this->assertSame('', $format->reference($cookie));
        $this->assertSame('', $format->consentTime($cookie));
        $this->assertSame('0', $format->revision($cookie));
        $this->assertFalse($format->matches($cookie));
    }
}
