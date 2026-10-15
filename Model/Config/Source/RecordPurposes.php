<?php
declare(strict_types=1);

namespace CommerceKings\Consent\Model\Config\Source;

use CommerceKings\Consent\Model\PurposePool;
use Magento\Framework\Data\OptionSourceInterface;

/** Recording choices, including purposes whose features require a consent record. */
class RecordPurposes implements OptionSourceInterface
{
    public function __construct(private readonly PurposePool $purposes)
    {
    }

    public function toOptionArray(): array
    {
        $options = [];
        foreach ($this->purposes->getAllCodes() as $code) {
            $required = !$this->purposes->isRecordOptional($code);
            $options[] = [
                'value' => $code,
                'label' => __($this->purposes->getLabel($code)) . ' (' . $code . ')'
                    . ($required ? ' — ' . __('required') : ''),
                'required' => $required,
            ];
        }
        return $options;
    }
}
