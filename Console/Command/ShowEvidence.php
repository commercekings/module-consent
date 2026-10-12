<?php
declare(strict_types=1);

namespace CommerceKings\Consent\Console\Command;

use CommerceKings\Consent\Model\ConsentStore;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Proof of one consent (GDPR Art. 7(1)): the record and the wording the visitor saw when giving it.
 */
class ShowEvidence extends Command
{
    public function __construct(private readonly ConsentStore $store)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->setName('consent:evidence:show')
            ->setDescription('Show a consent record and the wording the visitor saw when giving it')
            ->addArgument('consent_id', InputArgument::REQUIRED, 'Consent id (ck_consent.consent_id, e.g. quote.ck_consent_id)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $consent = $this->store->load((string)$input->getArgument('consent_id'));
        if (!$consent) {
            $output->writeln('<error>No consent with this id.</error>');
            return Command::FAILURE;
        }
        $hash = (string)($consent['snapshot_hash'] ?? '');
        $snapshot = $hash !== '' ? $this->store->loadSnapshot($hash) : null;
        $output->writeln(json_encode([
            'consent' => $consent,
            // null: recorded before wording snapshots existed - see the consent:config:export archive of its revision
            'wording' => $snapshot ? [
                'snapshot_hash' => $snapshot['snapshot_hash'],
                'first_seen' => $snapshot['created_at'],
                'hash_matches' => hash('sha256', (string)$snapshot['content']) === $snapshot['snapshot_hash'],
                'content' => json_decode((string)$snapshot['content'], true),
            ] : null,
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR), OutputInterface::OUTPUT_RAW);
        return Command::SUCCESS;
    }
}
