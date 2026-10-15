<?php
declare(strict_types=1);

namespace CommerceKings\Consent\Test\Unit\Block;

use CommerceKings\Consent\Block\Adminhtml\Form\Element\RecordPurposes;
use CommerceKings\Consent\Model\Config\Source\RecordPurposes as Options;
use CommerceKings\Consent\Model\PurposePool;
use Magento\Framework\Data\Form;
use Magento\Framework\Data\Form\Element\CollectionFactory;
use Magento\Framework\Data\Form\Element\Factory;
use Magento\Framework\Escaper;
use Magento\Framework\Math\Random;
use Magento\Framework\View\Helper\SecureHtmlRenderer;
use PHPUnit\Framework\TestCase;

class RecordPurposesTest extends TestCase
{
    private function render(array $selected): \DOMXPath
    {
        $options = new Options(new PurposePool([
            'cart_reminder' => ['label' => 'Cart reminder'],
            'analytics' => ['label' => 'Analytics', 'record' => 'optional'],
            'external' => ['label' => '<script>External</script>', 'record' => 'optional'],
        ]));
        $element = new RecordPurposes($this->createStub(Factory::class), $this->createStub(CollectionFactory::class),
            new Escaper(), ['name' => 'record_purposes', 'html_id' => 'records', 'value' => $selected,
                'values' => $options->toOptionArray()], $this->createStub(SecureHtmlRenderer::class), new Random());
        $element->setForm($this->createStub(Form::class));
        $document = new \DOMDocument();
        $document->loadHTML($element->getElementHtml());
        return new \DOMXPath($document);
    }

    public function testRequiredPurposeIsSelectedAndLockedEvenWhenNotInSavedValues(): void
    {
        $html = $this->render([]);
        $this->assertSame(1, $html->query('//option[@value="cart_reminder"][@selected][@disabled]')->length);
        $this->assertSame(1, $html->query('//option[@value="analytics"][not(@selected)][not(@disabled)]')->length);
        $this->assertSame(0, $html->query('//script')->length);
    }

    public function testOptionalSelectionStaysEditableAndCanBeCleared(): void
    {
        $html = $this->render(['analytics']);
        $this->assertSame(1, $html->query('//option[@value="analytics"][@selected][not(@disabled)]')->length);
        $this->assertSame(1, $html->query('//input[@type="hidden"][@name="record_purposes"][@value=""]')->length);
        $this->assertSame(1, $html->query('//select[@multiple][@name="record_purposes[]"]')->length);
    }
}
