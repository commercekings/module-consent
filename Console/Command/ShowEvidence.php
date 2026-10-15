<?php
declare(strict_types=1);

namespace CommerceKings\Consent\Console\Command;

use CommerceKings\Consent\Model\RecordedEvidence;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Proof of one consent (GDPR Art. 7(1)): the record and the wording the visitor saw when giving it.
 */
class ShowEvidence extends Command
{
    public function __construct(private readonly RecordedEvidence $evidence)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->setName('consent:evidence:show')
            ->setDescription('Show a consent record and the wording the visitor saw when giving it')
            ->addArgument('consent_id', InputArgument::REQUIRED, 'Consent id (ck_consent.consent_id)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $evidence = $this->evidence->get((string)$input->getArgument('consent_id'));
        if (!$evidence) {
            $output->writeln('<error>No consent with this id.</error>');
            return Command::FAILURE;
        }
        $output->writeln(json_encode($evidence,
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR), OutputInterface::OUTPUT_RAW);
        return Command::SUCCESS;
    }
}
