<?php

declare(strict_types=1);

namespace Drupal\Tests\canvas_ssr\Kernel\DrupalSettings;

use Drupal\canvas\Entity\JavaScriptComponent;
use Drupal\Core\DependencyInjection\ContainerBuilder;
use Drupal\Tests\canvas_ssr\Kernel\CanvasSsrKernelTestBase;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;

/**
 * Tests site data read at module scope is the current request's.
 *
 * Site data is set while modules evaluate, as in the browser, so a
 * component can read it at module scope, as in
 * `const client = new JsonApiClient()`. A runner can keep what it builds from
 * a base across requests, evaluated modules included. So the base id must
 * vary with the site data, or a request gets another's.
 *
 * @legacy-covers \Drupal\canvas_ssr\JsModules\BaseModules
 * @legacy-covers \Drupal\canvas_ssr\DrupalSettings\DrupalSettingsResolver
 */
#[RunTestsInSeparateProcesses]
#[Group('canvas')]
#[Group('JavaScriptComponents')]
#[Group('canvas_ssr_requires_runner')]
final class SiteDataAtModuleScopeTest extends CanvasSsrKernelTestBase {

  /**
   * {@inheritdoc}
   */
  public function register(ContainerBuilder $container): void {
    parent::register($container);
    self::persistDefaultCache($container);
  }

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installRunner();
  }

  /**
   * Tests a later request with other site config gets its own site data.
   */
  public function testSiteConfigChange(): void {
    $this->config('system.site')->set('name', 'Before')->save();
    $component = $this->createSiteDataComponent();
    self::assertStringStartsWith('Before ', $this->renderSiteData($component));

    $this->config('system.site')->set('name', 'After')->save();
    $this->laterRequest();

    self::assertStringStartsWith('After ', $this->renderSiteData($component));
  }

  /**
   * Tests a later request on another host gets its own site data.
   *
   * A site can be served under several hosts with the same base path.
   */
  public function testHostChange(): void {
    $component = $this->createSiteDataComponent();
    self::assertStringEndsWith(' http://localhost', $this->renderSiteData($component));

    $this->laterRequest();
    $request_stack = $this->container->get(RequestStack::class);
    \assert($request_stack instanceof RequestStack);
    $request = Request::create('http://other.example/');
    // The test base reads the session from the current request.
    $request->setSession(new Session(new MockArraySessionStorage()));
    $request_stack->push($request);

    self::assertStringEndsWith(' http://other.example', $this->renderSiteData($component));
  }

  /**
   * Tests module scope sees site data after an island unset it.
   *
   * Every island without site data unsets it. If two components with the
   * same site data shared a drupalSettings module, it would evaluate once per
   * realm, for the first, and the second's module scope would run with the
   * data unset.
   *
   * @see \Drupal\canvas_ssr\ComponentInfo\MainModule
   */
  public function testModuleScopeSeesSiteDataAnotherIslandUnset(): void {
    $first = $this->createSiteDataComponent('first');
    $plain_source = 'import { h } from "preact"; export default function P(){ return h("p", null, "p"); }';
    $plain = $this->createComponent('Plain', $plain_source, machine_name: 'plain');
    $second = $this->createSiteDataComponent('second');

    $this->renderSiteData($first);
    $this->renderComponent($plain);

    self::assertStringEndsWith(' http://localhost', $this->renderSiteData($second));
  }

  /**
   * Creates a component that renders site data it read at module scope.
   */
  private function createSiteDataComponent(string $machine_name = 'site_data'): JavaScriptComponent {
    $source = <<<'JS'
      import { h } from "preact";
      import { getSiteData } from "drupal-canvas";
      const { branding, baseUrl } = getSiteData();
      export default function Root() {
        return h("div", null, `${branding.siteName} ${baseUrl}`);
      }
      JS;

    return $this->createComponent(
      'Site data',
      $source,
      data_dependencies: ['drupalSettings' => ['v0.branding', 'v0.baseUrl']],
      machine_name: $machine_name,
    );
  }

  /**
   * Renders a component and returns its SSR markup's text.
   */
  private function renderSiteData(JavaScriptComponent $component): string {
    $output = $this->renderComponent($component);
    self::assertStringNotContainsString(self::CLIENT_ONLY_ATTRIBUTE, $output);

    return \strip_tags($this->extractSsrMarkup($output));
  }

  /**
   * Rebuilds the container, as a later request would start with.
   *
   * What runners keep outside it, such as what they built from a base,
   * stays.
   */
  private function laterRequest(): void {
    // The test base sets the rebuilt container in register().
    $this->container->get('kernel')->rebuildContainer();
  }

}
