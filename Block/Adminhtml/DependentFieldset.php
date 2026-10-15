<?php
declare(strict_types=1);

namespace CommerceKings\Consent\Block\Adminhtml;

use Magento\Config\Block\System\Config\Form\Fieldset;
use Magento\Framework\Data\Form\Element\AbstractElement;

/** Let Magento's dependency controller hide the section border as well as its contents. */
class DependentFieldset extends Fieldset
{
    public function render(AbstractElement $element)
    {
        return '<div id="row_' . $this->escapeHtmlAttr($element->getHtmlId()) . '">'
            . parent::render($element) . '</div>';
    }
}
