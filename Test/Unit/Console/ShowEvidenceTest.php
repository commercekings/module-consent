<?php
declare(strict_types=1);

namespace CommerceKings\Consent\Test\Unit\Console;

use CommerceKings\Consent\Console\Command\ShowEvidence;
use CommerceKings\Consent\Model\RecordedEvidence;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

class ShowEvidenceTest extends TestCase
{
    public function testUnknownIdReturnsFailureWithoutInventingEvidence(): void
    {
        $evidence = $this->createMock(RecordedEvidence::class);
        $evidence->expects($this->once())->method('get')->with('unknown')->willReturn(null);
        $tester = new CommandTester(new ShowEvidence($evidence));
        $this->assertSame(Command::FAILURE, $tester->execute(['consent_id' => 'unknown']));
        $this->assertSame('No consent with this id.' . PHP_EOL, $tester->getDisplay());
    }

    public function testEvidenceLookupFailureDoesNotProduceASuccessfulExport(): void
    {
        $evidence = $this->createMock(RecordedEvidence::class);
        $evidence->expects($this->once())->method('get')->with('id')
            ->willThrowException(new \RuntimeException('Evidence unavailable'));
        $tester = new CommandTester(new ShowEvidence($evidence));
        try {
            $tester->execute(['consent_id' => 'id']);
            $this->fail('Storage failure must not be reported as success.');
        } catch (\RuntimeException $error) {
            $this->assertSame('Evidence unavailable', $error->getMessage());
        }
        $this->assertSame('', $tester->getDisplay());
    }
}
