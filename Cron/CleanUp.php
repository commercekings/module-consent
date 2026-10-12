<?php
declare(strict_types=1);

namespace CommerceKings\Consent\Cron;

use CommerceKings\Consent\Model\Config;
use CommerceKings\Consent\Model\ConsentStore;
use Psr\Log\LoggerInterface;
use Magento\Store\Model\StoreManagerInterface;

/** Daily: consents withdrawn or expired, and withdrawal marks, older than "Keep records for (days)". */
class CleanUp
{
    public function __construct(
        private readonly ConsentStore $store,
        private readonly Config $config,
        private readonly LoggerInterface $logger,
        private readonly StoreManagerInterface $stores
    ) {
    }

    public function execute(): int
    {
        $days = $this->config->getKeepDays();
        $withdrawalDays = $days;
        foreach ($this->stores->getWebsites() as $website) {
            // An old accepted cookie must not regain permission after its withdrawal mark is deleted.
            $withdrawalDays = max($withdrawalDays, $this->config->getCookieLifetimeDays((int)$website->getId()) + 1);
        }
        $deleted = $this->store->cleanUp($days, $withdrawalDays);
        if ($deleted) {
            $this->logger->info(sprintf('CommerceKings_Consent: deleted %d old consent record(s)', $deleted));
        }
        return $deleted;
    }
}
