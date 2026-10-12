<?php
declare(strict_types=1);

namespace CommerceKings\Consent\Model\Config\Source;

use CommerceKings\Consent\Model\SourcePool;
use Magento\Framework\Data\OptionSourceInterface;

/** Admin dropdown "Source" - every registered source, or none (no consent for any purpose). */
class Sources implements OptionSourceInterface
{
    public function __construct(
        private readonly SourcePool $pool
    ) {
    }

    public function toOptionArray(): array
    {
        $options = [['value' => '', 'label' => __('-- None --')]];
        foreach ($this->pool->getLabels() as $code => $label) {
            $options[] = ['value' => $code, 'label' => __($label)];
        }
        return $options;
    }
}
