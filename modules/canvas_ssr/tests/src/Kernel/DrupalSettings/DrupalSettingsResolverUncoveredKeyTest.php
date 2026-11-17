<?php

declare(strict_types=1);

namespace Drupal\Tests\canvas_ssr\Kernel\DrupalSettings;

use Drupal\canvas_ssr\ComponentInfo\ComponentGraph;
use Drupal\canvas_ssr\DrupalSettings\DrupalSettingsResolver;
use Drupal\canvas_ssr\DrupalSettings\Provider\CanvasPageDataProvider;
use Drupal\canvas_ssr\DrupalSettings\Provider\CanvasSiteDataProvider;
use Drupal\Core\DependencyInjection\ContainerBuilder;
use Drupal\Tests\canvas_ssr\Kernel\CanvasSsrKernelTestBase;
use Drupal\Tests\canvas_ssr\Kernel\SpyRunner;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests SSR bails for a graph declaring a key no provider gives.
 *
 * The Canvas site and page data providers give every drupalSettings key the
 * Canvas schema allows, so no component can declare one they don't. It
 * could happen if Canvas adds a key canvas_ssr doesn't know yet. To simulate
 * that, this class removes the providers' tags, so it needs its own container.
 *
 * @see docs/adr/0007-never-render-without-declared-data.md
 * @see \Drupal\Tests\canvas_ssr\Kernel\ScenariosTrait
 */
#[RunTestsInSeparateProcesses]
#[CoversClass(DrupalSettingsResolver::class)]
#[Group('canvas')]
#[Group('JavaScriptComponents')]
final class DrupalSettingsResolverUncoveredKeyTest extends CanvasSsrKernelTestBase {

  /**
   * {@inheritdoc}
   */
  public function register(ContainerBuilder $container): void {
    parent::register($container);
    // Untag rather than remove, since other services may depend on them.
    // With no provider tagged, no declared key is given.
    $container
      ->getDefinition(CanvasSiteDataProvider::class)
      ->clearTag('canvas_ssr.site_data_provider');
    $container
      ->getDefinition(CanvasPageDataProvider::class)
      ->clearTag('canvas_ssr.page_data_provider');
    // A runner that renders anything it's asked to, so only the bail stops
    // a render.
    $container
      ->register(SpyRunner::class, SpyRunner::class)
      ->addTag('canvas_ssr.runner', ['priority' => 1000]);
  }

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    SpyRunner::clear();
    SpyRunner::$output = '{"html":"<p>rendered</p>"}';
  }

  /**
   * Tests a graph bails wherever in it the uncovered key is.
   */
  public function testGraphDeclaringUncoveredKeyBails(): void {
    $this->assertScenarios([
      'the root declares it' => function (): void {
        $root = $this->createComponent(
          'root declaring a key no provider gives',
          self::INERT_JS,
          data_dependencies: ['drupalSettings' => ['v0.baseUrl']],
        );

        self::assertNull($this->resolver()->forGraph(ComponentGraph::of($root)));
      },

      // The root declares nothing, but the render still needs the key.
      'a dependency declares it' => function (): void {
        $dependency = $this->createComponent(
          'dependency declaring a key no provider gives',
          self::INERT_JS,
          data_dependencies: ['drupalSettings' => ['v0.baseUrl']],
        );
        $root = $this->createComponent('root declaring nothing', self::INERT_JS, imports: [(string) $dependency->id()]);

        self::assertNull($this->resolver()->forGraph(ComponentGraph::of($root)));
      },

      // The resolver's NULL is what Renderer bails on: nothing is registered
      // or invoked.
      'an island of such a graph falls back to CSR without an invoke' => function (): void {
        $root = $this->createComponent(
          'rendered root declaring a key no provider gives',
          self::INERT_JS,
          data_dependencies: ['drupalSettings' => ['v0.baseUrl']],
        );
        $renderer = $this->ssrRenderer();

        $result = $renderer->render($root, [], [], 'preact');

        self::assertNull($result->html);
        self::assertSame([], SpyRunner::$invoked);
        self::assertSame([], SpyRunner::$modules);
      },

      // Without this, the scenarios above would pass if the resolver
      // returned NULL for everything.
      'a graph declaring nothing renders' => function (): void {
        $root = $this->createComponent('root declaring nothing', self::INERT_JS);

        self::assertNull($this->resolver()->forGraph(ComponentGraph::of($root))?->value());
        self::assertNotNull($this->resolver()->forGraph(ComponentGraph::of($root)));
        $renderer = $this->ssrRenderer();
        self::assertSame('<p>rendered</p>', $renderer->render($root, [], [], 'preact')->html);
      },
    ]);
  }

  /**
   * Gets the resolver under test.
   */
  private function resolver(): DrupalSettingsResolver {
    $resolver = $this->container->get(DrupalSettingsResolver::class);
    \assert($resolver instanceof DrupalSettingsResolver);

    return $resolver;
  }

}
