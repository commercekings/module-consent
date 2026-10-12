<?php
declare(strict_types=1);

namespace CommerceKings\Consent\Console\Command;

use CommerceKings\Consent\Model\Evidence;
use Magento\Framework\App\Area;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\App\State;
use Magento\Store\Model\App\Emulation;
use Magento\Store\Model\StoreManagerInterface;
use Magento\Store\Model\ScopeInterface;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class ExportConfig extends Command
{
    public function __construct(
        private readonly Evidence $evidence,
        private readonly Emulation $emulation,
        private readonly StoreManagerInterface $stores,
        private readonly State $state,
        private readonly ScopeConfigInterface $scopeConfig
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
        $store = $this->stores->getStore($input->getOption('store'));
        if (!(int)$store->getId()) {
            throw new \InvalidArgumentException('Specify a storefront store view, not the admin store.');
        }
        $policy = null;
        $file = $input->getOption('policy-file');
        if ($file !== null) {
            if (!is_file($file) || !is_readable($file) || ($policy = file_get_contents($file)) === false) {
                throw new \InvalidArgumentException('The policy file must be a readable local file.');
            }
        }
        $this->state->emulateAreaCode(Area::AREA_FRONTEND, function () use ($store, $policy, $output): void {
            $this->emulation->startEnvironmentEmulation((int)$store->getId(), Area::AREA_FRONTEND, true);
            try {
                $snapshot = $this->evidence->snapshot($policy);
                $snapshot['store'] = [
                    'id' => (int)$store->getId(),
                    'code' => $store->getCode(),
                    'website_id' => (int)$store->getWebsiteId(),
                    'locale' => $this->scopeConfig->getValue('general/locale/code', ScopeInterface::SCOPE_STORE, $store->getId()),
                ];
                $output->writeln(
                    json_encode($snapshot, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
                    OutputInterface::OUTPUT_RAW
                );
            } finally {
                $this->emulation->stopEnvironmentEmulation();
            }
        });
        return 0;
    }
}
