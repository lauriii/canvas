<?php

declare(strict_types=1);

namespace Drupal\Tests\canvas_ssr\Kernel;

use Drupal\canvas_ssr\JsRunner\Deno\DenoRunner;
use Drupal\Core\DependencyInjection\ContainerBuilder;
use Drupal\Tests\canvas\Kernel\CanvasKernelTestBase;
use Drupal\Tests\canvas_ssr\Traits\DenoWarmUp;
use PHPUnit\Framework\Attributes\BeforeClass;

/**
 * Base class for canvas_ssr kernel tests.
 *
 * Installs canvas_ssr on top of what CanvasKernelTestBase installs, and the
 * entity schemas every test needs. It also gives tests the helpers most of
 * them share: fixtures, rendering and scenarios.
 *
 * It installs no runner. Some tests deliberately have none, so a test that
 * renders calls installRunner() in its own setUp(), after parent::setUp().
 *
 * @see \Drupal\Tests\canvas_ssr\Kernel\RunnerTestTrait
 */
abstract class CanvasSsrKernelTestBase extends CanvasKernelTestBase {

  use ComponentFixtureTrait;
  use RenderTrait;
  use RunnerTestTrait;
  use ScenariosTrait;

  /**
   * {@inheritdoc}
   *
   * The parent's modules are inherited, so only canvas_ssr is listed.
   */
  protected static $modules = ['canvas_ssr'];

  /**
   * Skips the class on Drupal before 11.4.
   *
   * The module needs core services that Drupal 11.4 added. Skipping here
   * runs no test's setUp() or tearDown(), which need the container.
   */
  #[BeforeClass(100)]
  public static function skipBeforeDrupal114(): void {
    if (\version_compare(\Drupal::VERSION, '11.4', '<')) {
      self::markTestSkipped('canvas_ssr requires Drupal 11.4 or later.');
    }
  }

  /**
   * Warms Deno up first, when it's the runner under test.
   *
   * Tests that render do it through the production time limits, which a
   * Deno start on a host that just booted can exceed. A class that runs
   * Deno whatever the runner under test is uses WarmsUpDenoTrait instead.
   *
   * @see \Drupal\Tests\canvas_ssr\Traits\WarmsUpDenoTrait
   */
  #[BeforeClass]
  public static function warmUpDenoUnderTest(): void {
    if (self::runnerClassUnderTest() === DenoRunner::class) {
      DenoWarmUp::run();
    }
  }

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    // Naming and rendering components generates URLs.
    $this->installEntitySchema('user');
    $this->installEntitySchema('path_alias');
  }

  /**
   * Keeps the default cache bin through container rebuilds.
   *
   * The memory backend empties when the container is rebuilt. The database
   * one lasts, so a rebuilt container acts like a later request. Call this
   * from register().
   *
   * @param \Drupal\Core\DependencyInjection\ContainerBuilder $container
   *   The container being built.
   */
  protected static function persistDefaultCache(ContainerBuilder $container): void {
    $definition = $container->getDefinition('cache.default');
    $tags = $definition->getTags();
    $tags['cache.bin'][0]['default_backend'] = 'cache.backend.database';
    $definition->setTags($tags);
  }

}
