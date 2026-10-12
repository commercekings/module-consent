<?php
declare(strict_types=1);

namespace CommerceKings\Consent\Model;

use CommerceKings\Consent\Api\SourceInterface;

/**
 * The source chosen in admin for a website, out of the SourcePool - null when none is chosen or the
 * chosen one is not installed. Its client config also names its JS adapter and the declared purposes.
 */
class ConfiguredSource
{
    public function __construct(
        private readonly SourcePool $sources,
        private readonly PurposePool $purposes,
        private readonly Config $config
    ) {
    }

    public function get(?int $websiteId = null): ?SourceInterface
    {
        return $this->sources->getProvider($this->config->getSourceCode($websiteId));
    }

    public function getClientConfig(): array
    {
        $source = $this->get();
        if (!$source) {
            return ['source' => '', 'adapter' => '', 'purposes' => []];
        }
        return [
            'source' => $source->getCode(),
            'adapter' => $this->sources->getAdapter($this->config->getSourceCode()),
            'purposes' => $this->purposes->getCodes(),
            // the rest are optional purposes the shop does not record: no server calls for them
            'recorded' => $this->config->getRecordedPurposes(),
            'cookieLifetimeDays' => $this->config->getCookieLifetimeDays(),
        ] + $source->getClientConfig();
    }
}
