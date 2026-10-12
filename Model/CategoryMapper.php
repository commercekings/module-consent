<?php
declare(strict_types=1);

namespace CommerceKings\Consent\Model;

use CommerceKings\Consent\Api\PurposeRegistryInterface;

/** Shared processing of source categories and the feature purposes they grant. */
class CategoryMapper
{
    /** @param array<string, array> $defaultCategories Optional preset, used without explicit source categories. */
    public function __construct(
        private readonly PurposeRegistryInterface $purposes,
        private readonly array $defaultCategories = []
    ) {
    }

    /** Each source supplies its own category-key restrictions. */
    public function normalize(array $categories, string $invalidKeyPattern): array
    {
        $normalized = [];
        $preset = !$categories;
        foreach ($categories ?: $this->defaultCategories as $key => $category) {
            $key = (string)preg_replace($invalidKeyPattern, '', (string)$key);
            if ($key === '' || !is_array($category)) {
                continue;
            }
            $required = !empty($category['required']);
            $listed = array_map('strval', (array)($category['purposes'] ?? []));
            if ($preset) {
                // integrations put their purpose into a preset category themselves (purpose "category")
                foreach ($this->purposes->getCodes() as $purpose) {
                    if ($this->purposes->getDefaultCategory($purpose) === $key) {
                        $listed[] = $purpose;
                    }
                }
            }
            // An always-on switch cannot express permission for an optional feature purpose.
            $purposes = $required ? [] : array_values(array_unique(array_filter(
                $listed,
                fn ($purpose) => $this->purposes->isDeclared($purpose)
            )));
            if (!empty($category['hide_if_unmapped']) && !$purposes) {
                continue;
            }
            $normalized[$key] = [
                'label' => (string)__((string)($category['label'] ?? $key)),
                'description' => (string)__((string)($category['description'] ?? '')),
                'required' => $required,
                'purposes' => $purposes,
                'sort_order' => (int)($category['sort_order'] ?? 0),
            ];
        }
        uasort($normalized, fn ($a, $b) => $a['sort_order'] <=> $b['sort_order']);
        return $normalized;
    }

    /** @return string[] Purposes granted by the accepted categories, without duplicates. */
    public function acceptedPurposes(array $categories, array $accepted): array
    {
        $purposes = [];
        foreach ($categories as $key => $category) {
            if (in_array($key, $accepted, true)) {
                array_push($purposes, ...$category['purposes']);
            }
        }
        return array_values(array_unique($purposes));
    }

    /** @return array<string, string> Purpose => first granting category in display order. */
    public function purposeMap(array $categories): array
    {
        $map = [];
        foreach ($categories as $key => $category) {
            foreach ($category['purposes'] as $purpose) {
                $map[$purpose] ??= $key;
            }
        }
        return $map;
    }
}
