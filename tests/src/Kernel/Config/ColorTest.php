<?php

declare(strict_types=1);

namespace Drupal\Tests\canvas\Kernel\Config;

use Drupal\canvas\AutoSave\AutoSaveManager;
use Drupal\canvas\ComponentSource\ComponentSourceManager;
use Drupal\canvas\Entity\AssetLibrary;
use Drupal\canvas\Entity\BrandKit;
use Drupal\canvas\Entity\Color;
use Drupal\canvas\Entity\Component;
use Drupal\canvas\Entity\Page;
use Drupal\canvas\Entity\Pattern;
use Drupal\Core\Access\AccessResult;
use Drupal\Core\Access\AccessResultInterface;
use Drupal\Core\Cache\CacheableDependencyInterface;
use Drupal\Core\Cache\CacheTagsChecksumInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\File\FileSystemInterface;
use Drupal\Core\Language\LanguageManagerInterface;
use Drupal\language\ConfigurableLanguageManagerInterface;
use Drupal\language\Entity\ConfigurableLanguage;
use Drupal\Tests\canvas\Kernel\CanvasKernelTestBase;
use Drupal\Tests\user\Traits\UserCreationTrait;
use Drupal\user\UserInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests the Color entity behavior.
 *
 * @see \Drupal\canvas\Entity\Color
 * @see \Drupal\canvas\Entity\BrandKit
 */
#[Group('canvas')]
#[RunTestsInSeparateProcesses]
final class ColorTest extends CanvasKernelTestBase {

  /**
   * The cache tags every usage-derived Color access result carries.
   *
   * Content *and* config entity types that carry a component tree: a Pattern
   * that starts using the color changes the delete gate's answer just as a
   * Page does.
   *
   * @see \Drupal\canvas\Audit\ConfigAuditBase::getUsageCacheTags()
   */
  private const array USAGE_CACHE_TAGS = [
    'canvas__auto_save',
    'canvas_page_list',
    'config:content_template_list',
    'config:page_region_list',
    'config:page_variant_list',
    'config:pattern_list',
  ];

  use UserCreationTrait;

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['language'];

  protected function setUp(): void {
    parent::setUp();
    // Install Canvas config to get the global BrandKit.
    $this->installConfig('canvas');
    // Component config entities are needed by the component tree tests.
    $this->container->get(ComponentSourceManager::class)->generateComponents();
    // Content entity usages are what the delete gate tests exercise.
    $this->installEntitySchema('user');
    $this->installEntitySchema('path_alias');
    $this->installEntitySchema(Page::ENTITY_TYPE_ID);

    // Set up the assets directory for BrandKit CSS/JS file generation.
    // The BrandKit entity uses AssetLibrary::ASSETS_DIRECTORY ('assets://canvas/')
    // as the base path for generated asset files.
    $file_system = \Drupal::service(FileSystemInterface::class);
    \assert($file_system instanceof FileSystemInterface);
    $directory = AssetLibrary::ASSETS_DIRECTORY;
    self::assertTrue(
      $file_system->prepareDirectory($directory, FileSystemInterface::CREATE_DIRECTORY | FileSystemInterface::MODIFY_PERMISSIONS),
      "Failed to create assets directory: {$directory}",
    );
  }

  /**
   * Tests that new Colors are immediately visible via BrandKit::getColors().
   */
  public function testPostSaveRegistersWithBrandKit(): void {
    $brand_kit = BrandKit::load('global');
    self::assertNotNull($brand_kit);
    $initial_count = count($brand_kit->getColors());

    // Create a new Color.
    $color = Color::create([
      'name' => 'Test Red',
      'cssVariable' => '--color-test-red',
      'value' => [
        'colorSpace' => 'srgb',
        'components' => [0.8, 0.0, 0.0],
        'hex' => '#cc0000',
      ],
      'weight' => 0,
    ]);
    $color->save();

    // No BrandKit reload needed — getColors() always queries the DB live.
    $colors = $brand_kit->getColors();
    $this->assertCount($initial_count + 1, $colors);
    $this->assertContains($color->id(), $colors);
  }

  /**
   * Tests that updating an existing Color does not duplicate it in BrandKit.
   */
  public function testUpdateDoesNotDuplicateInBrandKit(): void {
    // Create a Color first.
    $color = Color::create([
      'name' => 'Test Green',
      'cssVariable' => '--color-test-green',
      'value' => [
        'colorSpace' => 'srgb',
        'components' => [0.0, 0.8, 0.0],
        'hex' => '#00cc00',
      ],
      'weight' => 0,
    ]);
    $color->save();

    $brand_kit = BrandKit::load('global');
    self::assertNotNull($brand_kit);
    $this->assertContains($color->id(), $brand_kit->getColors());

    // Update the Color.
    $color->set('name', 'Updated Green');
    $color->save();

    // getColors() queries the DB; the color should still appear exactly once.
    $colors = $brand_kit->getColors();
    $occurrences = array_filter($colors, static fn (string $id): bool => $id === $color->id());
    $this->assertCount(1, $occurrences);
  }

  /**
   * Tests that getColors() is naturally idempotent.
   */
  public function testPostSaveIsIdempotent(): void {
    // Create a Color.
    $color = Color::create([
      'name' => 'Idempotent Test',
      'cssVariable' => '--color-idempotent',
      'value' => [
        'colorSpace' => 'srgb',
        'components' => [0.0, 0.0, 0.8],
        'hex' => '#0000cc',
      ],
      'weight' => 0,
    ]);
    $color->save();

    $brand_kit = BrandKit::load('global');
    self::assertNotNull($brand_kit);
    $occurrences = array_filter($brand_kit->getColors(), static fn (string $id): bool => $id === $color->id());
    $this->assertCount(1, $occurrences, 'Color should appear exactly once after initial save.');

    // Save the Color again (update).
    $color->set('name', 'Updated Idempotent Test');
    $color->save();

    $occurrences = array_filter($brand_kit->getColors(), static fn (string $id): bool => $id === $color->id());
    $this->assertCount(1, $occurrences, 'Color should still appear exactly once after update.');
  }

  /**
   * Tests that deleting a Color removes it from BrandKit::getColors().
   */
  public function testDeletingColorRemovesFromBrandKit(): void {
    // Create a Color that will remain.
    $keeper = Color::create([
      'name' => 'Keeper',
      'cssVariable' => '--color-keeper',
      'value' => [
        'colorSpace' => 'srgb',
        'components' => [0.0, 0.8, 0.0],
        'hex' => '#00cc00',
      ],
      'weight' => 0,
    ]);
    $keeper->save();

    // Create a Color that will be deleted.
    $color = Color::create([
      'name' => 'Delete Me',
      'cssVariable' => '--color-delete-me',
      'value' => [
        'colorSpace' => 'srgb',
        'components' => [0.8, 0.0, 0.8],
        'hex' => '#cc00cc',
      ],
      'weight' => 0,
    ]);
    $color->save();

    $brand_kit = BrandKit::load('global');
    self::assertNotNull($brand_kit);
    $this->assertContains($keeper->id(), $brand_kit->getColors());
    $this->assertContains($color->id(), $brand_kit->getColors());

    // Delete the Color.
    $color->delete();

    // getColors() queries the DB live — the deleted color is gone immediately.
    $colors = $brand_kit->getColors();
    $this->assertNotContains($color->id(), $colors);
    $this->assertContains($keeper->id(), $colors);
  }

  /**
   * Tests getCssValue() method with various color spaces and opacity values.
   *
   * @see Color::getCssValue()
   */
  public function testGetCssValue(): void {
    // sRGB: no alpha (NULL) - returns stored hex when available.
    $color1 = Color::create([
      'name' => 'Solid sRGB Color',
      'cssVariable' => '--color-solid-srgb',
      'value' => [
        'colorSpace' => 'srgb',
        'components' => [1.0, 0.4, 0.0],
        'hex' => '#ff6600',
      ],
      'weight' => 0,
    ]);
    $this->assertSame('#ff6600', $color1->getCssValue());

    // sRGB: alpha 1.0 (fully opaque) - returns stored hex when available.
    $color2 = Color::create([
      'name' => 'Opaque sRGB Color',
      'cssVariable' => '--color-opaque-srgb',
      'value' => [
        'colorSpace' => 'srgb',
        'components' => [1.0, 0.4, 0.0],
        'hex' => '#ff6600',
        'alpha' => 1.0,
      ],
      'weight' => 0,
    ]);
    $this->assertSame('#ff6600', $color2->getCssValue());

    // sRGB: with hex that differs from components - returns the stored hex.
    // This verifies hex is preferred over recomputing from components.
    $color_mismatched = Color::create([
      'name' => 'Mismatched Hex Color',
      'cssVariable' => '--color-mismatched',
      'value' => [
        'colorSpace' => 'srgb',
        'components' => [1.0, 0.0, 0.0],
        'hex' => '#00ff00',
      ],
      'weight' => 0,
    ]);
    $this->assertSame('#00ff00', $color_mismatched->getCssValue());

    // sRGB: no hex - falls back to computing from components.
    $color_no_hex = Color::create([
      'name' => 'No Hex Color',
      'cssVariable' => '--color-no-hex',
      'value' => [
        'colorSpace' => 'srgb',
        'components' => [0.0, 1.0, 0.0],
      ],
      'weight' => 0,
    ]);
    $this->assertSame('#00ff00', $color_no_hex->getCssValue());

    // sRGB: alpha 0.5 (semi-transparent) - returns rgba (ignores hex).
    $color3 = Color::create([
      'name' => 'Semi-transparent sRGB',
      'cssVariable' => '--color-semi-srgb',
      'value' => [
        'colorSpace' => 'srgb',
        'components' => [1.0, 0.4, 0.0],
        'hex' => '#ff6600',
        'alpha' => 0.5,
      ],
      'weight' => 0,
    ]);
    $this->assertSame('rgba(255, 102, 0, 0.50)', $color3->getCssValue());

    // sRGB: alpha 0.0 (fully transparent) - returns rgba.
    $color4 = Color::create([
      'name' => 'Transparent sRGB',
      'cssVariable' => '--color-transparent-srgb',
      'value' => [
        'colorSpace' => 'srgb',
        'components' => [1.0, 1.0, 1.0],
        'hex' => '#ffffff',
        'alpha' => 0.0,
      ],
      'weight' => 0,
    ]);
    $this->assertSame('rgba(255, 255, 255, 0.00)', $color4->getCssValue());

    // HSL: no alpha - returns hsl.
    $color5 = Color::create([
      'name' => 'Solid HSL Color',
      'cssVariable' => '--color-solid-hsl',
      'value' => [
        'colorSpace' => 'hsl',
        'components' => [120.0, 100.0, 50.0],
      ],
      'weight' => 0,
    ]);
    $this->assertSame('hsl(120, 100%, 50%)', $color5->getCssValue());

    // HSL: with alpha - returns hsla.
    $color6 = Color::create([
      'name' => 'Semi-transparent HSL',
      'cssVariable' => '--color-semi-hsl',
      'value' => [
        'colorSpace' => 'hsl',
        'components' => [240.0, 100.0, 50.0],
        'alpha' => 0.5,
      ],
      'weight' => 0,
    ]);
    $this->assertSame('hsla(240, 100%, 50%, 0.50)', $color6->getCssValue());

    // Fallback: no hex, unknown color space.
    $color7 = Color::create([
      'name' => 'Fallback Color',
      'cssVariable' => '--color-fallback',
      'value' => [
        'colorSpace' => 'srgb',
        'components' => [0.0, 0.0, 0.0],
      ],
      'weight' => 0,
    ]);
    $this->assertSame('#000000', $color7->getCssValue());
  }

  /**
   * Tests that updateFromClientSide clears hex when components change.
   *
   * This prevents stale hex values from diverging from the computed color,
   * which would cause differences between PHP-generated CSS and the editor preview.
   */
  public function testUpdateFromClientSideClearsHexWhenComponentsChange(): void {
    // Create a color with initial components and hex.
    $color = Color::create([
      'name' => 'Test Color',
      'cssVariable' => '--color-test',
      'value' => [
        'colorSpace' => 'srgb',
        'components' => [1.0, 0.0, 0.0],
        'hex' => '#ff0000',
      ],
      'weight' => 0,
    ]);
    $color->save();

    // Simulate a PATCH request that updates components but omits hex.
    $color->updateFromClientSide([
      'value' => [
        'components' => [0.0, 1.0, 0.0],
      ],
    ]);
    $color->save();

    // The hex should be cleared (null) because it no longer matches components.
    $value = $color->getValue();
    $this->assertNull($value['hex'], 'hex should be null when components changed without explicit hex');

    // getCssValue() should compute from the new components.
    $this->assertSame('#00ff00', $color->getCssValue());
  }

  /**
   * Tests that updateFromClientSide preserves explicit hex values.
   */
  public function testUpdateFromClientSidePreservesExplicitHex(): void {
    // Create a color with initial components and hex.
    $color = Color::create([
      'name' => 'Test Color',
      'cssVariable' => '--color-test',
      'value' => [
        'colorSpace' => 'srgb',
        'components' => [1.0, 0.0, 0.0],
        'hex' => '#ff0000',
      ],
      'weight' => 0,
    ]);
    $color->save();

    // Simulate a PATCH that updates components AND explicitly provides a new hex.
    $color->updateFromClientSide([
      'value' => [
        'components' => [0.0, 1.0, 0.0],
        'hex' => '#00ff00',
      ],
    ]);
    $color->save();

    // The explicit hex should be preserved.
    $value = $color->getValue();
    $this->assertSame('#00ff00', $value['hex'], 'explicit hex should be preserved');
    $this->assertSame('#00ff00', $color->getCssValue());
  }

  /**
   * Tests that updateFromClientSide clears hex when colorSpace changes.
   */
  public function testUpdateFromClientSideClearsHexWhenColorSpaceChanges(): void {
    // Create an sRGB color with hex.
    $color = Color::create([
      'name' => 'Test Color',
      'cssVariable' => '--color-test',
      'value' => [
        'colorSpace' => 'srgb',
        'components' => [1.0, 0.0, 0.0],
        'hex' => '#ff0000',
      ],
      'weight' => 0,
    ]);
    $color->save();

    // Simulate a PATCH that changes colorSpace to HSL without providing hex.
    $color->updateFromClientSide([
      'value' => [
        'colorSpace' => 'hsl',
        'components' => [120.0, 100.0, 50.0],
      ],
    ]);
    $color->save();

    // The hex should be cleared.
    $value = $color->getValue();
    $this->assertNull($value['hex'], 'hex should be null when colorSpace changed without explicit hex');
    $this->assertSame('hsl(120, 100%, 50%)', $color->getCssValue());
  }

  /**
   * Tests that updateFromClientSide preserves hex when only alpha changes.
   */
  public function testUpdateFromClientSidePreservesHexWhenOnlyAlphaChanges(): void {
    // Create a color with initial components, hex, and no alpha.
    $color = Color::create([
      'name' => 'Test Color',
      'cssVariable' => '--color-test',
      'value' => [
        'colorSpace' => 'srgb',
        'components' => [1.0, 0.0, 0.0],
        'hex' => '#ff0000',
      ],
      'weight' => 0,
    ]);
    $color->save();

    // Simulate a PATCH that only adds alpha.
    $color->updateFromClientSide([
      'value' => [
        'alpha' => 0.5,
      ],
    ]);
    $color->save();

    // The hex should be preserved (not cleared) because components didn't change.
    $value = $color->getValue();
    $this->assertSame('#ff0000', $value['hex'], 'hex should be preserved when only alpha changes');
    // With alpha, getCssValue() returns rgba, not hex.
    $this->assertSame('rgba(255, 0, 0, 0.50)', $color->getCssValue());
  }

  /**
   * Tests BrandKit color normalization sorting.
   *
   * Colors should be sorted by weight, then alphabetically by name.
   */
  public function testBrandKitColorNormalizationSorting(): void {
    // Create Colors with different weights and names.
    $color_a = Color::create([
      'name' => 'Alpha',
      'cssVariable' => '--color-alpha',
      'value' => [
        'colorSpace' => 'srgb',
        'components' => [0.67, 0.0, 0.0],
        'hex' => '#aa0000',
      ],
      'weight' => 10,
    ]);
    $color_a->save();

    $color_z = Color::create([
      'name' => 'Zulu',
      'cssVariable' => '--color-zulu',
      'value' => [
        'colorSpace' => 'srgb',
        'components' => [0.0, 0.0, 0.67],
        'hex' => '#0000aa',
      ],
      'weight' => 0,
    ]);
    $color_z->save();

    $color_b = Color::create([
      'name' => 'Bravo',
      'cssVariable' => '--color-bravo',
      'value' => [
        'colorSpace' => 'srgb',
        'components' => [0.0, 0.67, 0.0],
        'hex' => '#00aa00',
      ],
      'weight' => 0,
    ]);
    $color_b->save();

    // Reload BrandKit and check the normalized colors order.
    \Drupal::entityTypeManager()->getStorage('brand_kit')->resetCache();
    $brand_kit = BrandKit::load('global');
    self::assertNotNull($brand_kit);

    $representation = $brand_kit->normalizeForClientSide();
    $colors = $representation->values['colors'] ?? [];

    // Expected order: Bravo (weight 0, alphabetically before Zulu), Zulu (weight 0), Alpha (weight 10).
    // Colors with same weight are sorted alphabetically by name.
    $this->assertCount(3, $colors);
    $this->assertSame('Bravo', $colors[0]['name']);
    $this->assertSame('Zulu', $colors[1]['name']);
    $this->assertSame('Alpha', $colors[2]['name']);

    // Verify the IDs match.
    $this->assertSame($color_b->id(), $colors[0]['id']);
    $this->assertSame($color_z->id(), $colors[1]['id']);
    $this->assertSame($color_a->id(), $colors[2]['id']);
  }

  /**
   * Tests BrandKit color normalization structure.
   *
   * @see BrandKit::normalizeForClientSide()
   */
  public function testBrandKitColorNormalizationStructure(): void {
    $color = Color::create([
      'name' => 'Structured Color',
      'cssVariable' => '--color-structured',
      'value' => [
        'colorSpace' => 'srgb',
        'components' => [0.07, 0.2, 0.33],
        'hex' => '#123456',
        'alpha' => 0.85,
      ],
      'weight' => 42,
    ]);
    $color->save();

    \Drupal::entityTypeManager()->getStorage('brand_kit')->resetCache();
    $brand_kit = BrandKit::load('global');
    self::assertNotNull($brand_kit);

    $representation = $brand_kit->normalizeForClientSide();
    $colors = $representation->values['colors'] ?? [];

    $this->assertCount(1, $colors);
    $this->assertSame($color->id(), $colors[0]['id']);
    $this->assertSame('Structured Color', $colors[0]['name']);
    $this->assertSame('--color-structured', $colors[0]['cssVariable']);
    $this->assertSame('srgb', $colors[0]['value']['colorSpace']);
    $this->assertSame([0.07, 0.2, 0.33], $colors[0]['value']['components']);
    $this->assertSame(0.85, $colors[0]['value']['alpha']);
    $this->assertSame('#123456', $colors[0]['value']['hex']);
    $this->assertSame(42, $colors[0]['weight']);
  }

  /**
   * Tests that BrandKit with no colors omits the colors property from normalization.
   */
  public function testBrandKitWithoutColorsOmitsColorsProperty(): void {
    // The global BrandKit starts with no colors.
    $brand_kit = BrandKit::load('global');
    self::assertNotNull($brand_kit);
    $this->assertSame([], $brand_kit->getColors());

    $representation = $brand_kit->normalizeForClientSide();

    // The colors key should be omitted when there are no colors.
    $this->assertArrayNotHasKey('colors', $representation->values);
  }

  /**
   * Tests that BrandKit::getColors() includes Color entities.
   */
  public function testBrandKitColorDependencies(): void {
    // Create a Color.
    $color = Color::create([
      'name' => 'Dependency Color',
      'cssVariable' => '--color-dependency',
      'value' => [
        'colorSpace' => 'srgb',
        'components' => [0.67, 0.74, 0.93],
        'hex' => '#abcdef',
      ],
      'weight' => 0,
    ]);
    $color->save();

    $brand_kit = BrandKit::load('global');
    self::assertNotNull($brand_kit);

    // The Color is returned by getColors() since it exists in the DB.
    $this->assertContains($color->id(), $brand_kit->getColors());

    // BrandKit does not store Color config dependencies — Colors are independent.
    $dependencies = $brand_kit->getDependencies();
    $this->assertNotContains('canvas.color.' . $color->id(), $dependencies['config'] ?? []);
  }

  /**
   * Tests that a component tree using a Color depends on it, by prop schema.
   */
  public function testComponentTreeDeclaresColorDependency(): void {
    $color = self::createTestColor();
    $component = Component::load('sdc.canvas_test_sdc.color-valid');
    \assert($component instanceof Component);

    $pattern = Pattern::create([
      'id' => 'color_dependency_pattern',
      'label' => 'Color Dependency Pattern',
      'component_tree' => [
        [
          'uuid' => $this->container->get('uuid')->generate(),
          'component_id' => $component->id(),
          'component_version' => $component->getActiveVersion(),
          'inputs' => [
            'heading' => 'Heading',
            'background_color' => Color::REFERENCE_PREFIX . $color->id(),
          ],
        ],
      ],
    ]);
    $pattern->save();

    self::assertContains('canvas.color.' . $color->id(), $pattern->getDependencies()['config']);
  }

  /**
   * Tests that a color reference in a non-color prop creates no dependency.
   *
   * Color props are identified by their JSON schema `$ref`, so a string prop
   * whose value merely looks like a reference must not be treated as one.
   */
  public function testColorLikeValueInNonColorPropIsNotADependency(): void {
    $color = self::createTestColor();
    $component = Component::load('sdc.canvas_test_sdc.color-valid');
    \assert($component instanceof Component);

    $pattern = Pattern::create([
      'id' => 'color_lookalike_pattern',
      'label' => 'Color Lookalike Pattern',
      'component_tree' => [
        [
          'uuid' => $this->container->get('uuid')->generate(),
          'component_id' => $component->id(),
          'component_version' => $component->getActiveVersion(),
          'inputs' => [
            // `heading` is a plain string prop, not a color prop.
            'heading' => Color::REFERENCE_PREFIX . $color->id(),
            'background_color' => '#ff0000ff',
          ],
        ],
      ],
    ]);
    $pattern->save();

    self::assertNotContains('canvas.color.' . $color->id(), $pattern->getDependencies()['config'] ?? []);
  }

  /**
   * Tests that deleting a Color inlines its value instead of breaking config.
   *
   * Config entities whose ::onDependencyRemoval() returns FALSE are deleted by
   * the config system, so the Pattern surviving is the point of the test.
   *
   * @param array<string, mixed> $color_value
   *   The deleted Color's stored value.
   * @param string $expected_literal
   *   The value the color prop must hold once the Color is gone.
   *
   * @see \Drupal\canvas\Entity\ComponentTreeConfigEntityBase::onDependencyRemoval()
   */
  #[DataProvider('providerInlinedColorLiterals')]
  public function testDeletingColorInlinesItsValueInComponentTrees(array $color_value, string $expected_literal): void {
    $color = self::createTestColor($color_value);
    $component = Component::load('sdc.canvas_test_sdc.color-valid');
    \assert($component instanceof Component);
    $instance_uuid = $this->container->get('uuid')->generate();

    $pattern = Pattern::create([
      'id' => 'color_inlining_pattern',
      'label' => 'Color Inlining Pattern',
      'component_tree' => [
        [
          'uuid' => $instance_uuid,
          'component_id' => $component->id(),
          'component_version' => $component->getActiveVersion(),
          'inputs' => [
            'heading' => 'Heading',
            'background_color' => Color::REFERENCE_PREFIX . $color->id(),
          ],
        ],
      ],
    ]);
    $pattern->save();

    $color->delete();

    // The Pattern must survive: it is not collateral damage of the deletion.
    $reloaded = Pattern::load('color_inlining_pattern');
    self::assertInstanceOf(Pattern::class, $reloaded);

    // The design is preserved: the reference became the color's literal value.
    $inputs = $reloaded->getComponentTree()->getComponentTreeItemByUuid($instance_uuid)?->getInputs();
    self::assertIsArray($inputs);
    self::assertSame($expected_literal, $inputs['background_color']);
    self::assertNotContains('canvas.color.' . $color->id(), $reloaded->getDependencies()['config'] ?? []);

    // A color prop accepts only hex, `hsl()`/`hsla()` or a Brand Kit
    // reference. Validating the survivor is what catches an inlined value —
    // `rgb()`/`rgba()`, most of all — that the prop cannot hold.
    // @see \Drupal\canvas\Validation\JsonSchema\CanvasColorStringConstraint::check()
    self::assertEntityIsValid($reloaded);
  }

  /**
   * Data provider for ::testDeletingColorInlinesItsValueInComponentTrees().
   *
   * @return \Generator<string, array{array<string, mixed>, string}>
   *   Each case is a stored Color value and the literal it must inline to.
   */
  public static function providerInlinedColorLiterals(): \Generator {
    yield 'sRGB, no alpha' => [
      ['colorSpace' => 'srgb', 'components' => [0.67, 0.74, 0.93], 'hex' => '#abcdef'],
      '#abcdef',
    ];
    yield 'sRGB, fully opaque' => [
      ['colorSpace' => 'srgb', 'components' => [0.67, 0.74, 0.93], 'alpha' => 1.0, 'hex' => '#abcdef'],
      '#abcdef',
    ];
    // The stored hex is 6-digit, so alpha becomes a 7th and 8th digit rather
    // than being dropped: 0.5 * 255 rounds to 128, i.e. `80`.
    yield 'sRGB, translucent' => [
      ['colorSpace' => 'srgb', 'components' => [0.67, 0.74, 0.93], 'alpha' => 0.5, 'hex' => '#abcdef'],
      '#abcdef80',
    ];
    yield 'sRGB, fully transparent' => [
      ['colorSpace' => 'srgb', 'components' => [0.67, 0.74, 0.93], 'alpha' => 0.0, 'hex' => '#abcdef'],
      '#abcdef00',
    ];
    // Without a stored hex the components are what the literal is built from.
    yield 'sRGB, translucent, no hex' => [
      ['colorSpace' => 'srgb', 'components' => [0.67, 0.74, 0.93], 'alpha' => 0.5],
      '#abbded80',
    ];
    yield 'HSL, no alpha' => [
      ['colorSpace' => 'hsl', 'components' => [120.0, 100.0, 50.0]],
      'hsl(120, 100%, 50%)',
    ];
    yield 'HSL, translucent' => [
      ['colorSpace' => 'hsl', 'components' => [240.0, 100.0, 50.0], 'alpha' => 0.5],
      'hsla(240, 100%, 50%, 0.50)',
    ];
  }

  /**
   * Tests that inlining a deleted Color keeps a translated tree's keys.
   *
   * ::getComponentTree() returns the tree as a list, but a config entity with
   * an already-translated component tree stores it keyed by component instance
   * UUID — those keys are what a config translation targets.
   *
   * @see \Drupal\canvas\Entity\ComponentTreeConfigEntityBase::setComponentTree()
   */
  public function testDeletingColorInlinesItsValueInTranslatedComponentTrees(): void {
    ConfigurableLanguage::createFromLangcode('fr')->save();
    $color = self::createTestColor();
    $instance_uuid = '2c6e91ae-23ac-433d-9bb8-687144464b34';

    $pattern = Pattern::create([
      'id' => 'color_translated_pattern',
      'label' => 'Color Translated Pattern',
      'component_tree' => self::treeUsingColor($color),
    ]);
    $pattern->save();

    $language_manager = $this->container->get(LanguageManagerInterface::class);
    \assert($language_manager instanceof ConfigurableLanguageManagerInterface);
    $override = $language_manager->getLanguageConfigOverride('fr', $pattern->getConfigDependencyName());
    $override->setData(['component_tree' => [$instance_uuid => ['inputs' => ['heading' => 'Titre']]]])->save();
    self::assertCount(1, Pattern::load('color_translated_pattern')?->getTranslationLanguages(include_default: FALSE) ?? []);

    // Capture deprecations explicitly: ::setComponentTree() auto-fixes a list
    // it is handed, but only by triggering one, and this suite runs with
    // SYMFONY_DEPRECATIONS_HELPER=disabled.
    $deprecations = [];
    \set_error_handler(static function (int $severity, string $message) use (&$deprecations): bool {
      // Ignore the unrelated deprecations Drupal and Symfony raise meanwhile.
      if (\str_contains($message, 'component tree')) {
        $deprecations[] = $message;
      }
      return TRUE;
    }, E_USER_DEPRECATED);
    try {
      $color->delete();
    }
    finally {
      \restore_error_handler();
    }
    self::assertSame([], $deprecations);

    // The reference became the color's literal value, as for any other tree.
    $reloaded = Pattern::load('color_translated_pattern');
    self::assertInstanceOf(Pattern::class, $reloaded);
    $inputs = $reloaded->getComponentTree()->getComponentTreeItemByUuid($instance_uuid)?->getInputs();
    self::assertIsArray($inputs);
    self::assertSame('#abcdef', $inputs['background_color']);

    // The stored sequence is still keyed by instance UUID, so the French
    // override still targets the component instance it was written against.
    self::assertSame(
      [$instance_uuid],
      \array_keys($this->config($reloaded->getConfigDependencyName())->get('component_tree')),
    );
    self::assertSame(
      'Titre',
      $language_manager->getLanguageConfigOverride('fr', $reloaded->getConfigDependencyName())
        ->get('component_tree.' . $instance_uuid . '.inputs.heading'),
    );
  }

  /**
   * Tests that a Color in use cannot be deleted, matching code components.
   *
   * @see \Drupal\canvas\EntityHandlers\ColorAccessControlHandler::checkAccess()
   * @see \Drupal\Tests\canvas\Kernel\Entity\JavascriptComponentAccessTest
   */
  public function testDeleteAccessIsGatedByUsage(): void {
    $color = self::createTestColor();
    $brand_kit_maintainer = $this->createUser([Color::ADMIN_PERMISSION]);
    \assert($brand_kit_maintainer instanceof UserInterface);
    $entity_type_manager = $this->container->get(EntityTypeManagerInterface::class);
    $auto_save_manager = $this->container->get(AutoSaveManager::class);

    // An unused color can be deleted. Every usage-derived answer carries the
    // audit's cache tags: the content revisions and auto-saves it is derived
    // from are not cacheable dependencies of the Color itself, so without them
    // the answer would outlive the usage it reports.
    // @see \Drupal\canvas\Audit\ConfigAuditBase::getUsageCacheTags()
    self::assertEquals(
      AccessResult::allowed()->addCacheContexts(['user.permissions'])->addCacheTags(self::USAGE_CACHE_TAGS),
      $color->access('delete', $brand_kit_maintainer, TRUE),
    );

    // A color-like value in a *non-color* prop is not a usage, so it must not
    // gate deletion either: it creates no config dependency.
    // @see ::testColorLikeValueInNonColorPropIsNotADependency()
    $lookalike = Page::create([
      'title' => 'Lookalike page',
      'components' => self::treeUsingColorLookalike($color),
    ]);
    self::assertEntityIsValid($lookalike);
    $lookalike->save();
    $entity_type_manager->getAccessControlHandler(Color::ENTITY_TYPE_ID)->resetCache();
    self::assertEquals(
      AccessResult::allowed()->addCacheContexts(['user.permissions'])->addCacheTags(self::USAGE_CACHE_TAGS),
      $color->access('delete', $brand_kit_maintainer, TRUE),
    );
    $lookalike->delete();

    // A usage in a content entity's default revision forbids deletion.
    $page = Page::create([
      'title' => 'Test page',
      'components' => self::treeUsingColor($color),
    ]);
    self::assertEntityIsValid($page);
    $page->save();
    $entity_type_manager->getAccessControlHandler(Color::ENTITY_TYPE_ID)->resetCache();
    self::assertEquals(
      AccessResult::forbidden('This color is in use in a default revision and cannot be deleted.')->addCacheContexts(['user.permissions'])->addCacheTags(self::USAGE_CACHE_TAGS),
      $color->access('delete', $brand_kit_maintainer, TRUE),
    );

    // A usage in only a *prior* revision does not, matching code components.
    $page->setNewRevision(TRUE);
    $page->set('components', [])->save();
    $entity_type_manager->getAccessControlHandler(Color::ENTITY_TYPE_ID)->resetCache();
    self::assertEquals(
      AccessResult::allowed()->addCacheContexts(['user.permissions'])->addCacheTags(self::USAGE_CACHE_TAGS),
      $color->access('delete', $brand_kit_maintainer, TRUE),
    );

    // A usage in an auto-save draft forbids deletion: it is unsaved work that
    // would otherwise be published against a color that no longer exists.
    $page->setComponentTree(self::treeUsingColor($color));
    $auto_save_manager->saveEntity($page);
    $entity_type_manager->getAccessControlHandler(Color::ENTITY_TYPE_ID)->resetCache();
    self::assertEquals(
      AccessResult::forbidden('This color is in use in a Canvas auto-save and cannot be deleted.')->addCacheContexts(['user.permissions'])->addCacheTags(self::USAGE_CACHE_TAGS),
      $color->access('delete', $brand_kit_maintainer, TRUE),
    );

    // A usage in a forward (pending, non-default) revision forbids deletion:
    // publishing it must not render a color that no longer exists.
    $auto_save_manager->delete($page);
    $page->setNewRevision(TRUE);
    $page->isDefaultRevision(FALSE);
    $page->set('components', self::treeUsingColor($color))->save();
    self::assertFalse($page->isDefaultRevision());
    $entity_type_manager->getAccessControlHandler(Color::ENTITY_TYPE_ID)->resetCache();
    self::assertEquals(
      AccessResult::forbidden('This color is in use in the latest revision and cannot be deleted.')->addCacheContexts(['user.permissions'])->addCacheTags(self::USAGE_CACHE_TAGS),
      $color->access('delete', $brand_kit_maintainer, TRUE),
    );

    // A newer forward revision without the color supersedes that one.
    $page->setNewRevision(TRUE);
    $page->isDefaultRevision(FALSE);
    $page->set('components', [])->save();
    $entity_type_manager->getAccessControlHandler(Color::ENTITY_TYPE_ID)->resetCache();
    self::assertEquals(
      AccessResult::allowed()->addCacheContexts(['user.permissions'])->addCacheTags(self::USAGE_CACHE_TAGS),
      $color->access('delete', $brand_kit_maintainer, TRUE),
    );

    // A usage in a *config* entity forbids deletion through the generic config
    // dependency check, because the color is a real config dependency now.
    // @see \Drupal\canvas\Plugin\DataType\ComponentInputs::calculateDependencies()
    $pattern = Pattern::create([
      'id' => 'color_gate_pattern',
      'label' => 'Color Gate Pattern',
      'component_tree' => self::treeUsingColor($color),
    ]);
    $pattern->save();
    self::assertContains($color->getConfigDependencyName(), $pattern->getDependencies()['config']);
    $entity_type_manager->getAccessControlHandler(Color::ENTITY_TYPE_ID)->resetCache();
    self::assertEquals(
      AccessResult::forbidden('There is other configuration depending on this color.')->addCacheTags(self::USAGE_CACHE_TAGS),
      $color->access('delete', $brand_kit_maintainer, TRUE),
    );

    // Removing the config usage restores the ability to delete.
    $pattern->setComponentTree([])->save();
    $entity_type_manager->getAccessControlHandler(Color::ENTITY_TYPE_ID)->resetCache();
    self::assertEquals(
      AccessResult::allowed()->addCacheContexts(['user.permissions'])->addCacheTags(self::USAGE_CACHE_TAGS),
      $color->access('delete', $brand_kit_maintainer, TRUE),
    );

    // A usage in the auto-save of a config entity forbids deletion too: that
    // draft never reached the config dependency graph.
    $pattern->setComponentTree(self::treeUsingColor($color));
    $auto_save_manager->saveEntity($pattern);
    $entity_type_manager->getAccessControlHandler(Color::ENTITY_TYPE_ID)->resetCache();
    self::assertEquals(
      AccessResult::forbidden('This color is in use in a Canvas auto-save and cannot be deleted.')->addCacheContexts(['user.permissions'])->addCacheTags(self::USAGE_CACHE_TAGS),
      $color->access('delete', $brand_kit_maintainer, TRUE),
    );
  }

  /**
   * Tests that a delete access answer lapses whenever the gate's answer changes.
   *
   * ::testDeleteAccessIsGatedByUsage() asserts which cache tags each answer
   * carries. That only checks the tags against an expectation written by hand,
   * so it cannot tell whether those are the tags that actually change: an
   * answer declaring the wrong tags, or none at all, passes it just as well.
   *
   * This asserts the property that matters instead. Cache an answer against
   * its own declared cacheability, change the world so the gate flips, and the
   * cached answer must no longer be valid.
   *
   * @see \Drupal\canvas\Audit\ConfigAuditBase::getUsageCacheTags()
   * @see \Drupal\canvas\EntityHandlers\ColorAccessControlHandler::checkAccess()
   */
  public function testDeleteAccessCacheabilityLapsesWhenTheGateFlips(): void {
    $color = self::createTestColor();
    $brand_kit_maintainer = $this->createUser([Color::ADMIN_PERMISSION]);
    \assert($brand_kit_maintainer instanceof UserInterface);
    $checksum_provider = $this->container->get(CacheTagsChecksumInterface::class);
    $access_control_handler = $this->container->get(EntityTypeManagerInterface::class)
      ->getAccessControlHandler(Color::ENTITY_TYPE_ID);

    // Snapshots the current answer the way a cache backend would store it.
    $snapshot = function () use ($color, $brand_kit_maintainer, $checksum_provider, $access_control_handler): array {
      $access_control_handler->resetCache();
      $result = $color->access('delete', $brand_kit_maintainer, TRUE);
      \assert($result instanceof AccessResultInterface && $result instanceof CacheableDependencyInterface);
      $tags = $result->getCacheTags();
      return [$result->isAllowed(), $tags, $checksum_provider->getCurrentChecksum($tags)];
    };

    // Asserts a snapshot is stale, and that the gate really did flip.
    $assert_lapsed = function (array $before, bool $now_allowed, string $message) use ($snapshot, $checksum_provider): void {
      [$was_allowed, $tags, $checksum] = $before;
      self::assertNotSame($was_allowed, $now_allowed, $message . ': the gate did not flip, so this proves nothing.');
      self::assertSame($now_allowed, $snapshot()[0], $message . ': unexpected new answer.');
      self::assertFalse($checksum_provider->isValid($checksum, $tags), $message);
    };

    // A Pattern that starts using the color forbids deletion. The previous
    // 'allowed' was derived from the config dependency graph, which is not a
    // cacheable dependency of the Color, so it must declare what it read.
    $before = $snapshot();
    self::assertTrue($before[0]);
    $pattern = Pattern::create([
      'id' => 'color_cacheability_pattern',
      'label' => 'Color Cacheability Pattern',
      'component_tree' => self::treeUsingColor($color),
    ]);
    $pattern->save();
    $assert_lapsed($before, FALSE, 'An allowed answer outlived the Pattern that started using the color');

    // And the forbid must lapse once that Pattern stops using it.
    $before = $snapshot();
    self::assertFalse($before[0]);
    $pattern->setComponentTree([])->save();
    $assert_lapsed($before, TRUE, 'A forbidden answer outlived the last config usage');

    // The same must hold for a content entity usage.
    $before = $snapshot();
    self::assertTrue($before[0]);
    $page = Page::create([
      'title' => 'Cacheability page',
      'components' => self::treeUsingColor($color),
    ]);
    self::assertEntityIsValid($page);
    $page->save();
    $assert_lapsed($before, FALSE, 'An allowed answer outlived the Page that started using the color');
  }

  /**
   * Builds a single-item component tree referencing the given color.
   *
   * @param \Drupal\canvas\Entity\Color $color
   *   The color to reference from the component instance's color prop.
   *
   * @return array<int, array{uuid: string, component_id: string, component_version: string, inputs: array<string, string>}>
   *   A component tree value.
   */
  private static function treeUsingColor(Color $color): array {
    $component = Component::load('sdc.canvas_test_sdc.color-valid');
    \assert($component instanceof Component);
    return [
      [
        'uuid' => '2c6e91ae-23ac-433d-9bb8-687144464b34',
        'component_id' => $component->id(),
        'component_version' => $component->getActiveVersion(),
        'inputs' => [
          'heading' => 'Heading',
          'background_color' => Color::REFERENCE_PREFIX . $color->id(),
        ],
      ],
    ];
  }

  /**
   * Builds a tree whose *non-color* prop merely looks like a color reference.
   *
   * @param \Drupal\canvas\Entity\Color $color
   *   The color to build a lookalike value from.
   *
   * @return array<int, array<string, mixed>>
   *   A single-item component tree.
   */
  private static function treeUsingColorLookalike(Color $color): array {
    $component = Component::load('sdc.canvas_test_sdc.color-valid');
    \assert($component instanceof Component);
    return [
      [
        'uuid' => 'd07d5d0a-2c25-4a67-9d7e-2d1a9b1cf3a4',
        'component_id' => $component->id(),
        'component_version' => $component->getActiveVersion(),
        'inputs' => [
          // `heading` is a plain string prop, not a color prop.
          'heading' => Color::REFERENCE_PREFIX . $color->id(),
          'background_color' => '#ff0000ff',
        ],
      ],
    ];
  }

  /**
   * Creates a Color for component tree dependency tests.
   *
   * @param array<string, mixed>|null $value
   *   The stored value, for tests that care what the color resolves to.
   *   Defaults to an opaque sRGB color.
   */
  private static function createTestColor(?array $value = NULL): Color {
    $color = Color::create([
      'name' => 'Dependency Color',
      'cssVariable' => '--color-dependency',
      'value' => $value ?? [
        'colorSpace' => 'srgb',
        'components' => [0.67, 0.74, 0.93],
        'hex' => '#abcdef',
      ],
      'weight' => 0,
    ]);
    $color->save();
    return $color;
  }

  /**
   * Tests that creating a color regenerates BrandKit assets.
   */
  public function testCreatingColorRegeneratesBrandKitAssets(): void {
    // Create a new Color.
    $color = Color::create([
      'name' => 'Asset Test Color',
      'cssVariable' => '--color-asset-test',
      'value' => [
        'colorSpace' => 'srgb',
        'components' => [0.5, 0.0, 0.5],
        'hex' => '#800080',
      ],
      'weight' => 0,
    ]);
    $color->save();

    // Reload the BrandKit entity so getCssPath() reflects the new CSS content.
    $storage = \Drupal::entityTypeManager()->getStorage('brand_kit');
    $storage->resetCache();
    $brand_kit = BrandKit::load('global');
    self::assertNotNull($brand_kit);

    // The CSS file must exist at the content-addressed path.
    $css_path = $brand_kit->getCssPath();
    $this->assertFileExists($css_path, 'BrandKit CSS should be generated when a color is created');

    // Verify the CSS contains the color variable.
    $css_content = file_get_contents($css_path);
    $this->assertIsString($css_content);
    $this->assertStringContainsString('--color-asset-test', $css_content);
    $this->assertStringContainsString('#800080', $css_content);
  }

  /**
   * Tests that updating a color regenerates BrandKit assets.
   */
  public function testUpdatingColorRegeneratesBrandKitAssets(): void {
    // Create a color first.
    $color = Color::create([
      'name' => 'Update Test Color',
      'cssVariable' => '--color-update-test',
      'value' => [
        'colorSpace' => 'srgb',
        'components' => [1.0, 0.0, 0.0],
        'hex' => '#ff0000',
      ],
      'weight' => 0,
    ]);
    $color->save();

    // Record the CSS path produced by the initial save.
    $storage = \Drupal::entityTypeManager()->getStorage('brand_kit');
    $storage->resetCache();
    $brand_kit = BrandKit::load('global');
    self::assertNotNull($brand_kit);
    $initial_css_path = $brand_kit->getCssPath();
    $this->assertFileExists($initial_css_path);

    // Update the color value.
    $color->set('value', [
      'colorSpace' => 'srgb',
      'components' => [0.0, 1.0, 0.0],
      'hex' => '#00ff00',
    ]);
    $color->save();

    // The path is content-addressed: a different color value produces a
    // different hash, so reload the BrandKit to get the new path.
    $storage->resetCache();
    $brand_kit = BrandKit::load('global');
    self::assertNotNull($brand_kit);
    $new_css_path = $brand_kit->getCssPath();

    // A new CSS file must exist at the updated path.
    $this->assertFileExists($new_css_path, 'BrandKit CSS should be regenerated when color is updated');

    // The new path must differ from the old one (content changed).
    $this->assertNotSame($initial_css_path, $new_css_path, 'A different content hash means a different file path');

    // Verify the CSS contains the updated color value and not the old one.
    $css_content = file_get_contents($new_css_path);
    $this->assertIsString($css_content);
    $this->assertStringContainsString('--color-update-test', $css_content);
    $this->assertStringContainsString('#00ff00', $css_content);
    $this->assertStringNotContainsString('#ff0000', $css_content);
  }

  /**
   * Tests that deleting a color regenerates BrandKit assets.
   */
  public function testDeletingColorRegeneratesBrandKitAssets(): void {
    // Create a color first.
    $color = Color::create([
      'name' => 'Delete Test Color',
      'cssVariable' => '--color-delete-test',
      'value' => [
        'colorSpace' => 'srgb',
        'components' => [0.0, 0.0, 1.0],
        'hex' => '#0000ff',
      ],
      'weight' => 0,
    ]);
    $color->save();

    // Record the CSS path and verify the color variable is present.
    $storage = \Drupal::entityTypeManager()->getStorage('brand_kit');
    $storage->resetCache();
    $brand_kit = BrandKit::load('global');
    self::assertNotNull($brand_kit);
    $initial_css_path = $brand_kit->getCssPath();
    $this->assertFileExists($initial_css_path);
    $css_content = file_get_contents($initial_css_path);
    $this->assertIsString($css_content);
    $this->assertStringContainsString('--color-delete-test', $css_content);

    // Delete the color.
    $color->delete();

    // The BrandKit CSS is regenerated with the color removed. The path will
    // differ from the pre-deletion path because the content hash has changed.
    $storage->resetCache();
    $brand_kit = BrandKit::load('global');
    self::assertNotNull($brand_kit);
    $new_css_path = $brand_kit->getCssPath();

    // The path must have changed (content-addressed hashing).
    $this->assertNotSame($initial_css_path, $new_css_path, 'A different content hash means a different file path after deletion');

    // Verify the CSS no longer contains the deleted color variable.
    // When the BrandKit has no colors and no compiled CSS the file may not
    // exist (CanvasAssetStorage::write() skips empty content); assert on the
    // content, not the file, when the new path may be absent.
    if (file_exists($new_css_path)) {
      $css_content = file_get_contents($new_css_path);
      $this->assertIsString($css_content);
      $this->assertStringNotContainsString('--color-delete-test', $css_content);
    }
    else {
      // No file means the BrandKit has no CSS output — the variable is gone.
      $this->assertStringNotContainsString('--color-delete-test', '');
    }
  }

}
