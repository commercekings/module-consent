<?php
declare(strict_types=1);

namespace CommerceKings\Consent\Block\Adminhtml\Form\Element;

use Magento\Framework\Data\Form\Element\Multiselect;

/** Native multiselect with selected, locked options for mandatory consent recording. */
class RecordPurposes extends Multiselect
{
    public function getElementHtml()
    {
        // System config only applies can_be_empty automatically to the built-in multiselect type.
        $this->setCanBeEmpty(true);
        return parent::getElementHtml();
    }

    protected function _optionToHtml($option, $selected)
    {
        if (empty($option['required'])) {
            return parent::_optionToHtml($option, $selected);
        }
        return '<option value="' . $this->_escape($option['value']) . '" selected="selected" disabled="disabled">'
            . $this->_escape($option['label']) . '</option>' . "\n";
    }
}
