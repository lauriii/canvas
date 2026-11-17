<?php

declare(strict_types=1);

namespace Drupal\Tests\canvas_ssr\Kernel\Fetch;

use Drupal\Core\DependencyInjection\ContainerBuilder;
use Drupal\Core\Extension\ModuleInstallerInterface;
use Drupal\node\Entity\Node;
use Drupal\node\Entity\NodeType;
use Drupal\Tests\canvas_ssr\Kernel\CanvasSsrKernelTestBase;
use Drupal\Tests\user\Traits\UserCreationTrait;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests SSR works with a non-default JSON:API base path.
 *
 * A site may change the prefix, as jsonapi_extras can, to "/api". The
 * jsonapi.base_path parameter feeds both getSiteData().jsonapiSettings, which
 * JsonApiClient builds URLs from, and JsonApiPathPolicy, which allows them. A
 * client using "/jsonapi" would be denied and fall back to CSR.
 *
 * @legacy-covers \Drupal\canvas_ssr\Fetch\Policy\JsonApiPathPolicy
 * @legacy-covers \Drupal\canvas_ssr\CanvasSsrServiceProvider
 */
#[RunTestsInSeparateProcesses]
#[Group('canvas')]
#[Group('JavaScriptComponents')]
#[Group('canvas_ssr_requires_runner')]
final class JsonApiBasePathSsrTest extends CanvasSsrKernelTestBase {

  use UserCreationTrait;

  /**
   * The base path the site uses instead of "/jsonapi".
   */
  private const string CUSTOM_BASE_PATH = '/api';

  /**
   * {@inheritdoc}
   */
  public function register(ContainerBuilder $container): void {
    parent::register($container);
    // Stands in for jsonapi_extras. This runs on every container build, so
    // the override survives installing jsonapi mid-test.
    $container->setParameter('jsonapi.base_path', self::CUSTOM_BASE_PATH);
  }

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installRunner();
  }

  /**
   * Tests a custom JSON:API base path flows to components through site data.
   */
  public function testCustomJsonApiBasePathFlowsToComponentsViaSiteData(): void {
    $this->installJsonApi();

    // Otherwise the rest of the test proves nothing.
    self::assertSame(
      self::CUSTOM_BASE_PATH,
      $this->container->getParameter('jsonapi.base_path'),
    );

    $this->setUpCurrentUser([], ['access content']);
    $node = Node::create([
      'type' => 'article',
      'title' => 'Custom Base Path Article',
      'status' => 1,
    ]);
    $node->save();

    // The client gets no prefix, so it must read it from site data. The
    // fetch only succeeds if it uses "/api".
    $component_source = <<<'JS'
import { h } from "preact";
import { JsonApiClient } from "drupal-canvas";
import useSWR from "swr";
const client = new JsonApiClient();
export default function Root() {
  const { data } = useSWR(
    ["node--article", {}],
    ([type, options]) => client.getCollection(type, options),
  );
  if (!Array.isArray(data) || data.length === 0) {
    return h("div", null, "no-data");
  }
  return h("div", null, data[0].title);
}
JS;
    $root = $this->createComponent(
      'custom base path consumer',
      $component_source,
      data_dependencies: ['drupalSettings' => ['v0.baseUrl', 'v0.jsonapiSettings']],
    );

    $output = $this->renderComponent($root);
    self::assertStringNotContainsString(self::CLIENT_ONLY_ATTRIBUTE, $output);
    self::assertSame(
      '<div>Custom Base Path Article</div>',
      $this->extractSsrMarkup($output),
    );
  }

  /**
   * Installs JSON:API and node, and creates the article node type.
   *
   * Not in $modules, because the test checks that the base path override
   * survives the container rebuild this install causes.
   */
  private function installJsonApi(): void {
    $module_installer = $this->container->get(ModuleInstallerInterface::class);
    \assert($module_installer instanceof ModuleInstallerInterface);
    // Installing node creates node_access, but not the node tables.
    $module_installer->install(['jsonapi', 'node']);
    $this->installEntitySchema('node');
    NodeType::create(['type' => 'article', 'name' => 'Article'])->save();
  }

}
