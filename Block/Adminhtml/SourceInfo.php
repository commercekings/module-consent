<?php
declare(strict_types=1);

namespace CommerceKings\Consent\Block\Adminhtml;

use CommerceKings\Consent\Api\SourceInterface;
use CommerceKings\Consent\Model\Config;
use CommerceKings\Consent\Model\PurposePool;
use CommerceKings\Consent\Model\SourcePool;
use Magento\Backend\Block\Template\Context;
use Magento\Config\Block\System\Config\Form\Field;
use Magento\Framework\Data\Form\Element\AbstractElement;
use Magento\Framework\View\Helper\SecureHtmlRenderer;

/**
 * Read-only source version and categories, as defined in code (di.xml). Rendered for every registered
 * source; the one picked in the Source dropdown is shown, including before saving. Tables use
 * Magento's static admin grid, plain text.
 */
class SourceInfo extends Field
{
    /** cell spacing inside the grids (the static grid has none outside Magento's own grid templates) */
    private const CELL = ' style="padding:1rem 1.2rem;vertical-align:top"';

    public function __construct(
        Context $context,
        private readonly SourcePool $sources,
        private readonly PurposePool $purposes,
        private readonly SecureHtmlRenderer $htmlRenderer,
        private readonly Config $config,
        array $data = []
    ) {
        parent::__construct($context, $data, $htmlRenderer);
    }

    protected function _getElementHtml(AbstractElement $element)
    {
        $field = (string)$element->getOriginalData('id');
        // admin CSS sets font-size: 0 on a value cell with a tooltip (it expects an input) - set the text size
        $style = ' style="font-size:1.4rem"';
        $html = '<div class="ck-source-info" data-source=""' . $style . '>'
            . $this->value((string)__('No banner selected'))
            . '</div>';
        foreach (array_keys($this->sources->getLabels()) as $code) {
            $source = $this->sources->getProvider((string)$code);
            if ($source) {
                $html .= '<div class="ck-source-info" data-source="' . $this->escapeHtmlAttr((string)$code) . '"' . $style . ' hidden>'
                    . $this->forSource($source, $field) . '</div>';
            }
        }
        return $html . $this->toggleScript($element);
    }

    protected function _isInheritCheckboxRequired(AbstractElement $element)
    {
        return false;
    }

    private function forSource(SourceInterface $source, string $field): string
    {
        return match ($field) {
            'source_version' => $this->value($source->getVersion()),
            'source_categories' => $this->categoriesHtml($source->getCategories()),
            default => '',
        };
    }

    private function value(string $value): string
    {
        // Magento's own read-only value markup (as Form\Element\Label)
        return '<div class="control-value">' . $this->escapeHtml($value !== '' ? $value : '-') . '</div>';
    }

    private function categoriesHtml(array $categories): string
    {
        $rows = [];
        $websiteId = (int)$this->getRequest()->getParam('website') ?: null;
        foreach ($categories as $key => $category) {
            $cookies = [];
            foreach ($category['purposes'] as $purpose) {
                array_push($cookies, ...$this->purposes->getCookiePatterns($purpose));
            }
            $rows[] = [
                $this->escapeHtml($category['label']) . '<br/><small>' . $this->escapeHtml($key) . '</small>',
                $this->escapeHtml($category['required'] ? __('Yes') : __('No')),
                $this->escapeHtml(implode(', ', array_map(
                    fn (string $purpose) => $purpose . ' (' . $this->recording($purpose, $websiteId) . ')',
                    $category['purposes']
                ))) ?: '&mdash;',
                $this->escapeHtml(implode(', ', array_unique($cookies))) ?: '&mdash;',
            ];
        }
        return $this->grid([__('Category'), __('Always on'), __('Purposes'), __('Cookies cleared')], $rows);
    }

    /** required (always recorded) / recorded / not recorded (Record consent for) */
    private function recording(string $purpose, ?int $websiteId): string
    {
        if (!$this->purposes->isRecordOptional($purpose)) {
            return (string)__('recorded, required');
        }
        return (string)($this->config->isRecorded($purpose, $websiteId) ? __('recorded') : __('not recorded'));
    }

    /** Magento's static admin grid. */
    private function grid(array $headers, array $rows): string
    {
        $head = '';
        foreach ($headers as $header) {
            $head .= '<th class="data-grid-th"' . self::CELL . '>' . $this->escapeHtml((string)$header) . '</th>';
        }
        $body = '';
        foreach (array_values($rows) as $i => $cells) {
            $body .= '<tr class="data-row' . ($i % 2 ? ' _odd-row' : '') . '"><td' . self::CELL . '>'
                . implode('</td><td' . self::CELL . '>', $cells) . '</td></tr>';
        }
        if ($body === '') {
            $body = '<tr class="data-grid-tr-no-data"><td class="empty-text" colspan="' . count($headers) . '">'
                . $this->escapeHtml(__('Nothing defined')) . '</td></tr>';
        }
        return '<div class="admin__data-grid-wrap admin__data-grid-wrap-static"><table class="data-grid">'
            . '<thead><tr>' . $head . '</tr></thead><tbody>' . $body . '</tbody></table></div>';
    }

    /** Shows the block of the source picked in the dropdown (on load and on change). */
    private function toggleScript(AbstractElement $element): string
    {
        $id = $this->escapeJs($element->getHtmlId());
        $js = "(function () {"
            . "var row = document.getElementById('row_{$id}'), select = document.getElementById('commercekings_consent_general_source');"
            . "if (!row || !select) { return; }"
            . "function show() { row.querySelectorAll('.ck-source-info').forEach(function (el) {"
            . "el.hidden = el.getAttribute('data-source') !== select.value; }); }"
            . "select.addEventListener('change', show); show(); }());";
        return $this->htmlRenderer->renderTag('script', [], $js, false);
    }
}
