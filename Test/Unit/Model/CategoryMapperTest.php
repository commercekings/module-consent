<?php
declare(strict_types=1);

namespace CommerceKings\Consent\Test\Unit\Model;

use CommerceKings\Consent\Model\CategoryMapper;
use CommerceKings\Consent\Model\PurposePool;
use PHPUnit\Framework\TestCase;

class CategoryMapperTest extends TestCase
{
    private function mapper(): CategoryMapper
    {
        return new CategoryMapper(new PurposePool([
            'cart_reminder' => ['label' => 'Reminder'],
            'external_content' => ['label' => 'External content'],
        ]));
    }

    /** @dataProvider sourceKeyRules */
    public function testNormalizationPreservesSourceKeyRulesAndFiltersUndeclaredPurposes(
        string $pattern,
        string $externalKey
    ): void {
        $categories = $this->mapper()->normalize([
            'external-content!' => ['purposes' => ['external_content', 'unknown'], 'sort_order' => 20],
            'necessary' => ['label' => 'Necessary', 'required' => true, 'sort_order' => 10],
            '@@' => ['purposes' => ['cart_reminder']],
            'invalid' => 'not a category',
        ], $pattern);

        $this->assertSame(['necessary', $externalKey], array_keys($categories));
        $this->assertTrue($categories['necessary']['required']);
        $this->assertSame([], $categories['necessary']['purposes']);
        $this->assertSame([
            'label' => $externalKey,
            'description' => '',
            'required' => false,
            'purposes' => ['external_content'],
            'sort_order' => 20,
        ], $categories[$externalKey]);
    }

    public function testAlwaysOnCategoryCannotGrantAnOptionalFeaturePurpose(): void
    {
        $mapper = $this->mapper();
        $categories = $mapper->normalize([
            'necessary' => ['required' => true, 'purposes' => ['cart_reminder']],
            'emails' => ['purposes' => ['cart_reminder']],
        ], '/[^a-z0-9_]/i');
        $this->assertSame([], $mapper->acceptedPurposes($categories, ['necessary']));
        $this->assertSame(['cart_reminder' => 'emails'], $mapper->purposeMap($categories));
    }

    public static function sourceKeyRules(): array
    {
        return [
            'Vanilla' => ['/[^a-z0-9_]/i', 'externalcontent'],
            'hyphens allowed' => ['/[^a-z0-9_-]/i', 'external-content'],
        ];
    }

    public function testOnlyAcceptedCategoriesGrantPurposesAndSharedGrantsAreDeduplicated(): void
    {
        $mapper = $this->mapper();
        $categories = $mapper->normalize([
            'emails' => ['purposes' => ['cart_reminder']],
            'preferences' => ['purposes' => ['cart_reminder']],
            'maps' => ['purposes' => ['external_content']],
            'unknown' => ['purposes' => ['unknown']],
        ], '/[^a-z0-9_]/i');

        $this->assertSame(['cart_reminder'], $mapper->acceptedPurposes($categories, ['emails', 'preferences', 'unknown']));
        $this->assertSame(['external_content'], $mapper->acceptedPurposes($categories, ['maps']));
        $this->assertSame([], $mapper->acceptedPurposes($categories, ['missing']));
        $this->assertSame([], $mapper->acceptedPurposes($categories, []));
    }

    public function testFrontendMappingUsesFirstGrantingCategoryWithoutSharingSourceState(): void
    {
        $mapper = $this->mapper();
        $first = $mapper->normalize([
            'later' => ['purposes' => ['cart_reminder'], 'sort_order' => 20],
            'earlier' => ['purposes' => ['cart_reminder'], 'sort_order' => 10],
        ], '/[^a-z0-9_]/i');
        $second = $mapper->normalize(['maps' => ['purposes' => ['external_content']]], '/[^a-z0-9_-]/i');

        $this->assertSame(['cart_reminder' => 'earlier'], $mapper->purposeMap($first));
        $this->assertSame(['external_content' => 'maps'], $mapper->purposeMap($second));
        $this->assertSame(['cart_reminder' => 'earlier'], $mapper->purposeMap($first));
        $this->assertSame([], $mapper->purposeMap([]));
    }

    public function testPresetOnlyFillsUnconfiguredSourcesAndHidesUnmappedCategories(): void
    {
        $mapper = new CategoryMapper(new PurposePool(['known' => ['label' => 'Known']]), [
            'necessary' => ['required' => true],
            'available' => ['purposes' => ['known'], 'hide_if_unmapped' => true],
            'unavailable' => ['purposes' => ['missing'], 'hide_if_unmapped' => true],
        ]);
        $defaults = $mapper->normalize([], '/[^a-z0-9_]/i');
        $this->assertSame(['necessary', 'available'], array_keys($defaults));
        $this->assertSame(['known' => 'available'], $mapper->purposeMap($defaults));
        $explicit = $mapper->normalize(['custom' => ['label' => 'Site category']], '/[^a-z0-9_]/i');
        $this->assertSame(['custom'], array_keys($explicit), 'explicit categories replace the whole preset');
        $this->assertSame([], $mapper->purposeMap($explicit));
        $this->assertSame(['necessary', 'available'], array_keys($mapper->normalize([], '/[^a-z0-9_]/i')));
        $this->assertSame([], $this->mapper()->normalize([], '/[^a-z0-9_]/i'), 'core alone remains unconfigured');
    }
}
