<?php
declare(strict_types=1);

namespace CommerceKings\Consent\Test\Unit\ViewModel;

use CommerceKings\Consent\Model\ConfiguredSource;
use CommerceKings\Consent\ViewModel\ConsentConfig;
use PHPUnit\Framework\TestCase;

class ConsentConfigTest extends TestCase
{
    public function testCannotBreakOutOfTheScriptElement(): void
    {
        $source = $this->createMock(ConfiguredSource::class);
        $source->method('getClientConfig')->willReturn(['adapter' => 'A_B/js/x', 'cookie' => '</script><script>alert(1)</script>']);
        $json = (new ConsentConfig($source))->getJson();
        $this->assertStringNotContainsString('<', $json);
        $this->assertSame('A_B/js/x', json_decode($json, true)['adapter']);
    }
}
