<?php
declare(strict_types=1);

namespace CommerceKings\Consent\Model;

use CommerceKings\Consent\Api\SourceInterface;
use CommerceKings\Consent\Api\BannerConfigInterface;

/**
 * Consent sources a shop can choose from (admin: Source). Source modules register here through
 * the di.xml array argument "sources":
 *
 *   <item name="vanilla-cookieconsent" xsi:type="array">
 *       <item name="label" xsi:type="string">Cookie banner (vanilla-cookieconsent)</item>
 *       <item name="provider" xsi:type="object">Vendor\Module\Model\Source</item>
 *       <item name="adapter" xsi:type="string">Vendor_Module/js/adapter</item>
 *   </item>
 */
class SourcePool
{
    /** @param array<string, array{label: string, provider: SourceInterface, adapter: string, banner?: BannerConfigInterface}> $sources */
    public function __construct(
        private readonly array $sources = []
    ) {
    }

    /** code => label */
    public function getLabels(): array
    {
        return array_map(fn (array $source) => (string)__((string)$source['label']), $this->sources);
    }

    /** The source with this code, null for an unknown code (no consent - fail closed). */
    public function getProvider(string $code): ?SourceInterface
    {
        return $this->sources[$code]['provider'] ?? null;
    }

    public function getAdapter(string $code): string
    {
        return (string)($this->sources[$code]['adapter'] ?? '');
    }

    public function getBanner(string $code): ?BannerConfigInterface
    {
        return $this->sources[$code]['banner'] ?? null;
    }
}
