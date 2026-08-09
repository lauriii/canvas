<?php

declare(strict_types=1);

namespace Drupal\Tests\canvas\Kernel\Config;

use Drupal\canvas\Entity\AssetLibrary;
use Drupal\canvas\Entity\BrandKit;
use Drupal\canvas\Entity\Color;
use Drupal\Core\File\FileSystemInterface;
use Drupal\Tests\canvas\Kernel\CanvasKernelTestBase;
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

  protected function setUp(): void {
    parent::setUp();
    // Install Canvas config to get the global BrandKit.
    $this->installConfig('canvas');

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
