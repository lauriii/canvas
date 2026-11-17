<?php

declare(strict_types=1);

namespace Drupal\Tests\canvas_ssr\Kernel\DrupalSettings;

use Drupal\canvas\Entity\JavaScriptComponent;
use Drupal\canvas_ssr\ComponentInfo\ComponentGraph;
use Drupal\canvas_ssr\DrupalSettings\DrupalSettingsResolver;
use Drupal\canvas_ssr\DrupalSettings\GraphDrupalSettings;
use Drupal\Tests\canvas_ssr\Kernel\CanvasSsrKernelTestBase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests which site and page data a component graph gets.
 *
 * CanvasSiteAndPageDataTest tests the values through renders. A declared key no
 * provider gives needs different wiring, so it has its own class.
 *
 * @see \Drupal\Tests\canvas_ssr\Kernel\DrupalSettings\CanvasSiteAndPageDataTest
 * @see \Drupal\Tests\canvas_ssr\Kernel\DrupalSettings\DrupalSettingsResolverUncoveredKeyTest
 * @see \Drupal\Tests\canvas_ssr\Kernel\ScenariosTrait
 */
#[RunTestsInSeparateProcesses]
#[CoversClass(DrupalSettingsResolver::class)]
#[CoversClass(GraphDrupalSettings::class)]
#[Group('canvas')]
#[Group('JavaScriptComponents')]
final class DrupalSettingsResolverTest extends CanvasSsrKernelTestBase {

  /**
   * Tests a graph gets the keys it declares, and no others.
   */
  public function testGraphIsGivenWhatItDeclares(): void {
    $this->assertScenarios([
      // Not NULL, which makes the render bail.
      'a graph declaring nothing is given nothing, and does not bail' => function (): void {
        self::assertSame([[], []], $this->keysFor($this->createComponent('root declaring nothing', self::INERT_JS)));
      },

      // Another key would add cacheability for data the island never reads,
      // so unrelated edits would invalidate it.
      'only the declared keys are given' => function (): void {
        $site_only = $this->createComponent('root declaring site data', self::INERT_JS, data_dependencies: ['drupalSettings' => ['v0.baseUrl']]);
        $page_only = $this->createComponent('root declaring page data', self::INERT_JS, data_dependencies: ['drupalSettings' => ['v0.pageTitle']]);
        $both = $this->createComponent('root declaring both', self::INERT_JS, data_dependencies: ['drupalSettings' => ['v0.themeAssets', 'v0.breadcrumbs']]);

        self::assertSame([['v0.baseUrl'], []], $this->keysFor($site_only));
        self::assertSame([[], ['v0.pageTitle']], $this->keysFor($page_only));
        self::assertSame([['v0.themeAssets'], ['v0.breadcrumbs']], $this->keysFor($both));
      },

      // A dependency's declarations count, since it runs in the same realm.
      "a dependency's declaration counts as the graph's" => function (): void {
        $dependency = $this->createComponent('dependency declaring page data', self::INERT_JS, data_dependencies: ['drupalSettings' => ['v0.pageTitle']]);
        $root = $this->createComponent('root declaring nothing', self::INERT_JS, imports: [(string) $dependency->id()]);

        self::assertSame([[], ['v0.pageTitle']], $this->keysFor($root));
      },

      // The realm keeps what the last island set, so a graph that declares
      // nothing must have drupalSettings unset. Otherwise the island could
      // render data it never declared.
      //
      // @see docs/adr/0007-never-render-without-declared-data.md
      'drupalSettings is unset for a graph declaring nothing' => function (): void {
        self::assertNull($this->settingsOf(
          $this->createComponent('root declaring nothing', self::INERT_JS),
        )->value());
      },

      // Each key lands where Canvas puts it in the browser, and only the
      // declared ones are there.
      'each key is set under drupalSettings.canvasData' => function (): void {
        $value = $this->settingsOf(
          $this->createComponent('root declaring page data', self::INERT_JS, data_dependencies: ['drupalSettings' => ['v0.pageTitle']]),
        )->value();
        self::assertIsArray($value);
        self::assertSame(['canvasData'], \array_keys($value));
        self::assertSame(['pageTitle'], \array_keys($value['canvasData']['v0']));
      },

      // The drupalSettings module evaluates once per realm, and a realm
      // outlives the page. So it only sets site data, and page data comes
      // with each payload. That keeps the module the same on every page.
      'the drupalSettings module sets only site data' => function (): void {
        $both = $this->settingsOf(
          $this->createComponent('root declaring both', self::INERT_JS, data_dependencies: ['drupalSettings' => ['v0.baseUrl', 'v0.pageTitle']]),
        );
        self::assertStringContainsString('"baseUrl":', $both->module());
        self::assertStringNotContainsString('"pageTitle":', $both->module());
        self::assertArrayHasKey('pageTitle', $both->value()['canvasData']['v0'] ?? []);

        $page_only = $this->settingsOf(
          $this->createComponent('root declaring page data', self::INERT_JS, data_dependencies: ['drupalSettings' => ['v0.pageTitle']]),
        );
        self::assertStringContainsString('setDrupalSettings(null);', $page_only->module());
      },
    ]);
  }

  /**
   * Returns the site and page data keys a root's graph gets.
   *
   * @param \Drupal\canvas\Entity\JavaScriptComponent $root
   *   The root to walk from.
   *
   * @return array{list<string>, list<string>}
   *   The site data keys, then the page data keys.
   */
  private function keysFor(JavaScriptComponent $root): array {
    $settings = $this->settingsOf($root);

    return [\array_keys($settings->siteData), \array_keys($settings->pageData)];
  }

  /**
   * Returns the drupalSettings a root's graph gets.
   *
   * @param \Drupal\canvas\Entity\JavaScriptComponent $root
   *   The root to walk from.
   *
   * @return \Drupal\canvas_ssr\DrupalSettings\GraphDrupalSettings
   *   The graph's drupalSettings.
   */
  private function settingsOf(JavaScriptComponent $root): GraphDrupalSettings {
    $settings = $this->resolver()->forGraph(ComponentGraph::of($root));
    self::assertNotNull($settings);

    return $settings;
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
