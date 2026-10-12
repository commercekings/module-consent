<?php
declare(strict_types=1);

namespace CommerceKings\Consent\Model\Config\Source;

use CommerceKings\Consent\Model\PurposePool;
use Magento\Framework\Data\OptionSourceInterface;

/** Admin "Record consent for" - the purposes whose recording is optional (required ones are always recorded). */
class OptionalPurposes implements OptionSourceInterface
{
    public function __construct(
        private readonly PurposePool $purposes
    ) {
    }

    public function toOptionArray(): array
    {
        $options = [];
        foreach ($this->purposes->getAllCodes() as $code) {
            if ($this->purposes->isRecordOptional($code)) {
                $options[] = ['value' => $code, 'label' => __($this->purposes->getLabel($code)) . ' (' . $code . ')'];
            }
        }
        return $options;
    }
}
