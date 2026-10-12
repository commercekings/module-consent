<?php
declare(strict_types=1);

namespace CommerceKings\Consent\ViewModel;

use CommerceKings\Consent\Model\ConfiguredSource;
use Magento\Framework\View\Element\Block\ArgumentInterface;

/** #commercekings-consent-config: the chosen source, its JS adapter, the purposes and the source's settings. */
class ConsentConfig implements ArgumentInterface
{
    public function __construct(
        private readonly ConfiguredSource $source
    ) {
    }

    public function getJson(): string
    {
        return (string)json_encode(
            $this->source->getClientConfig(),
            JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_SLASHES
        );
    }
}
