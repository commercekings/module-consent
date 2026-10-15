<?php
declare(strict_types=1);

namespace CommerceKings\Consent\Test\Unit\Console;

use CommerceKings\Consent\Console\Command\ExportConfig;
use CommerceKings\Consent\Model\ConfigurationEvidence;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Tester\CommandTester;

class ExportConfigTest extends TestCase
{
    public function testExportPreservesPolicyMarkupFromTheSharedEvidenceBuilder(): void
    {
        $policy = '<info>Published policy wording</info>';
        $file = tempnam(sys_get_temp_dir(), 'ck-policy-');
        file_put_contents($file, $policy);
        try {
            $evidence = $this->createMock(ConfigurationEvidence::class);
            $evidence->expects($this->once())->method('get')->with('shop', $policy)
                ->willReturn(['policy_content' => $policy]);
            $tester = new CommandTester(new ExportConfig($evidence));
            $this->assertSame(0, $tester->execute(['--store' => 'shop', '--policy-file' => $file]));
            $json = json_decode($tester->getDisplay(), true, 512, JSON_THROW_ON_ERROR);
            $this->assertSame($policy, $json['policy_content'], 'Symfony must not interpret policy tags as console styles');
        } finally {
            unlink($file);
        }
    }

    public function testStoreScopeMustBeExplicit(): void
    {
        $evidence = $this->createMock(ConfigurationEvidence::class);
        $evidence->expects($this->never())->method('get');
        $tester = new CommandTester(new ExportConfig($evidence));
        $this->expectException(\InvalidArgumentException::class);
        $tester->execute([]);
    }
}
