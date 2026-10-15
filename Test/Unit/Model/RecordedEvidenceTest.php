<?php
declare(strict_types=1);

namespace CommerceKings\Consent\Test\Unit\Model;

use CommerceKings\Consent\Model\ConsentStore;
use CommerceKings\Consent\Model\RecordedEvidence;
use PHPUnit\Framework\TestCase;

class RecordedEvidenceTest extends TestCase
{
    public function testUnknownConsentDoesNotLoadWording(): void
    {
        $store = $this->createMock(ConsentStore::class);
        $store->expects($this->once())->method('load')->with('missing')->willReturn(null);
        $store->expects($this->never())->method('loadSnapshot');
        $this->assertNull((new RecordedEvidence($store))->get('missing'));
    }

    public function testLegacyConsentHasNoInventedWording(): void
    {
        $store = $this->createMock(ConsentStore::class);
        $store->expects($this->once())->method('load')->with('old')->willReturn(['consent_id' => 'old']);
        $store->expects($this->never())->method('loadSnapshot');
        $this->assertSame(['consent' => ['consent_id' => 'old'], 'wording' => null], (new RecordedEvidence($store))->get('old'));
    }

    public function testExactStoredWordingAndHashAreSharedWithTheCli(): void
    {
        $text = '{"text":"Earlier wording, café"}';
        $hash = hash('sha256', $text);
        $row = ['consent_id' => 'old', 'snapshot_hash' => $hash, 'withdrawn_at' => '2026-10-01 00:00:00'];
        $snapshot = ['snapshot_hash' => $hash, 'content' => $text, 'created_at' => '2026-01-01 00:00:00'];
        $store = $this->createMock(ConsentStore::class);
        $store->expects($this->once())->method('load')->with('old')->willReturn($row);
        $store->expects($this->once())->method('loadSnapshot')->with($hash)->willReturn($snapshot);
        $proof = (new RecordedEvidence($store))->get('old');
        $this->assertSame($row, $proof['consent']);
        $this->assertSame(['text' => 'Earlier wording, café'], $proof['wording']['content']);
        $this->assertTrue($proof['wording']['hash_matches']);
    }

    public function testAChangedSnapshotIsReportedAsAMismatch(): void
    {
        $evidence = new RecordedEvidence($this->createStub(ConsentStore::class));
        $proof = $evidence->format([], ['snapshot_hash' => str_repeat('a', 64), 'content' => '{}', 'created_at' => 'earlier']);
        $this->assertFalse($proof['wording']['hash_matches']);
    }
}
