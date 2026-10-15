<?php
declare(strict_types=1);

namespace CommerceKings\Consent\Console\Command;

use CommerceKings\Consent\Model\ConfigurationEvidence;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class ExportConfig extends Command
{
    public function __construct(
        private readonly ConfigurationEvidence $evidence
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->setName('consent:config:export')
            ->setDescription('Export store consent wording, mappings and revisions for archival')
            ->addOption('store', null, InputOption::VALUE_REQUIRED, 'Store view code or id (required)')
            ->addOption('policy-file', null, InputOption::VALUE_REQUIRED, 'Local copy of the published privacy policy');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        if ($input->getOption('store') === null) {
            throw new \InvalidArgumentException('Specify --store for the store view whose consent wording you are archiving.');
        }
        $policy = null;
        $file = $input->getOption('policy-file');
        if ($file !== null) {
            if (!is_file($file) || !is_readable($file) || ($policy = file_get_contents($file)) === false) {
                throw new \InvalidArgumentException('The policy file must be a readable local file.');
            }
        }
        $snapshot = $this->evidence->get($input->getOption('store'), $policy);
        $output->writeln(json_encode($snapshot,
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR), OutputInterface::OUTPUT_RAW);
        return 0;
    }
}
