<?php

declare(strict_types=1);

namespace Drupal\Tests\canvas_style_polyfill\Kernel;

use Drupal\canvas\Entity\StyleLibrary;
use Drupal\canvas\Style\CoreStyleSource;
use Drupal\canvas\Style\StyleSourceInterface;
use Drupal\canvas_style_polyfill\PolyfillStyleSource;
use Drupal\Tests\canvas\Kernel\CanvasKernelTestBase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests that the polyfill applies core's defaults, and steps aside for core.
 *
 * The rendering contract is covered by ComponentStylesKernelTest, which runs
 * on whichever source is in use.
 *
 * @see \Drupal\Tests\canvas\Kernel\Style\ComponentStylesKernelTest
 */
#[CoversClass(PolyfillStyleSource::class)]
#[Group('canvas')]
#[Group('canvas_style_polyfill')]
#[RunTestsInSeparateProcesses]
final class PolyfillStyleSourceTest extends CanvasKernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'canvas_style_polyfill',
    'canvas_test_styles',
  ];

  public function testSourceSelection(): void {
    $source = $this->container->get(StyleSourceInterface::class);
    self::assertInstanceOf(
      $this->container->has('plugin.manager.style') ? CoreStyleSource::class : PolyfillStyleSource::class,
      $source,
    );
  }

  public function testCoreDefaults(): void {
    if ($this->container->has('plugin.manager.style')) {
      $this->markTestSkipped('Core provides the Style API, so the polyfill is not in use.');
    }
    $definitions = $this->container->get(StyleSourceInterface::class)->definitions();

    // The style's attribute is every option's default.
    self::assertSame('style', $definitions['gap']['options']['tight']['attribute']);
    self::assertSame('any', $definitions['gap']['options']['tight']['target']);
    self::assertSame('stable', $definitions['gap']['options']['tight']['status']);
    self::assertSame('canvas_test_styles', $definitions['gap']['provider']);
    self::assertTrue($definitions['gap']['enabled']);

    // themes.yml (page-level styles) is not polyfilled.
    self::assertArrayNotHasKey('page_scheme', $definitions);

    // Style libraries are included, and the value defaults to the option ID.
    StyleLibrary::create([
      'id' => 'brand',
      'label' => 'Brand',
      'styles' => ['surface' => ['label' => 'Surface', 'options' => ['dark' => ['label' => 'Dark']]]],
    ])->save();
    $surface = $this->container->get(StyleSourceInterface::class)->definitions()['surface'];
    self::assertSame('brand', $surface['canvas_library']);
    self::assertSame('canvas', $surface['provider']);
    self::assertSame('dark', $surface['options']['dark']['value']);
    self::assertSame('class', $surface['options']['dark']['attribute']);
    self::assertSame('Other', $surface['category']);
  }

}
