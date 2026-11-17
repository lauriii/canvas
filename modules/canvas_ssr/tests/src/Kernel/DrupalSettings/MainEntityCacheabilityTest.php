<?php

declare(strict_types=1);

namespace Drupal\Tests\canvas_ssr\Kernel\DrupalSettings;

use Drupal\canvas\Entity\Component;
use Drupal\canvas\Entity\JavaScriptComponent;
use Drupal\canvas\Plugin\Canvas\ComponentSource\JsComponent;
use Drupal\content_translation\BundleTranslationSettingsInterface;
use Drupal\content_translation\ContentTranslationManagerInterface;
use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Core\Extension\ModuleInstallerInterface;
use Drupal\Core\Language\LanguageInterface;
use Drupal\Core\Render\RendererInterface;
use Drupal\Core\Routing\RouteProviderInterface;
use Drupal\language\Entity\ConfigurableLanguage;
use Drupal\node\Entity\Node;
use Drupal\node\Entity\NodeType;
use Drupal\Tests\canvas_ssr\Kernel\CanvasSsrKernelTestBase;
use Drupal\Tests\user\Traits\UserCreationTrait;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use Symfony\Component\HttpFoundation\ParameterBag;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Tests the cacheability of v0.mainEntity.
 *
 * For each translation, the value says whether the current user may view
 * it. Canvas bubbles that access result's cacheability for a component that
 * declares the key itself. SSR gives the key to a component whose
 * dependency declares it, too, so the page data carries it.
 *
 * @legacy-covers \Drupal\canvas_ssr\DrupalSettings\Provider\CanvasPageDataProvider
 */
#[RunTestsInSeparateProcesses]
#[Group('canvas')]
#[Group('JavaScriptComponents')]
#[Group('canvas_ssr_requires_runner')]
final class MainEntityCacheabilityTest extends CanvasSsrKernelTestBase {

  use UserCreationTrait;

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['node', 'serialization', 'jsonapi'];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installRunner();
    $this->installEntitySchema('canvas_page');
    $this->installEntitySchema('file');
    $this->installEntitySchema('node');
    $this->installSchema('node', ['node_access']);
    NodeType::create(['type' => 'article', 'name' => 'Article'])->save();
    $module_installer = $this->container->get(ModuleInstallerInterface::class);
    \assert($module_installer instanceof ModuleInstallerInterface);
    $module_installer->install(['language', 'content_translation']);
    ConfigurableLanguage::createFromLangcode('de')->save();
    $manager = $this->container->get(BundleTranslationSettingsInterface::class);
    \assert($manager instanceof ContentTranslationManagerInterface);
    $manager->setEnabled('node', 'article', TRUE);
  }

  /**
   * Tests a dependency's declaration carries what the root's would.
   */
  public function testAccessToTranslationsIsCarried(): void {
    $owner = $this->setUpCurrentUser([], ['access content', 'view own unpublished content']);
    $node = Node::create([
      'type' => 'article',
      'title' => 'Public English',
      'status' => 1,
      'uid' => $owner->id(),
    ]);
    $node->addTranslation('de', ['title' => 'Geheimer Entwurf', 'status' => 0, 'uid' => $owner->id()]);
    $node->save();

    $reader = <<<'JS'
    import { h } from "preact";
    import { getPageData } from "drupal-canvas";
    export default function Reader() {
      const translations = getPageData().mainEntity.translations.map((t) => `${t.langcode}:${t.translationAvailable}`);
      return h("div", null, translations.join(" "));
    }
    JS;
    $dependency = $this->randomMachineName();
    $this->createComponent('declares mainEntity', $reader, data_dependencies: ['drupalSettings' => ['v0.mainEntity']], machine_name: $dependency);
    $root = $this->createComponent(
      'declares nothing',
      \sprintf('import { h } from "preact"; import Reader from "@/components/%s"; export default function Root() { return h("section", null, h(Reader)); }', $dependency),
      imports: [$dependency],
    );
    $declaring_root = $this->createComponent('root declares mainEntity', $reader, data_dependencies: ['drupalSettings' => ['v0.mainEntity']]);

    $stack = $this->pushNodeRequest($node);
    try {
      foreach ([$root, $declaring_root] as $component) {
        [$output, $cacheability] = $this->renderThroughComponentSource($component);
        self::assertStringNotContainsString(self::CLIENT_ONLY_ATTRIBUTE, $output);
        // The owner may view the unpublished translation.
        self::assertStringContainsString('de:true', $this->extractSsrMarkup($output));
        // So it isn't cached for someone else with the same permissions.
        self::assertContains('user', $cacheability->getCacheContexts());
        self::assertContains('languages:' . LanguageInterface::TYPE_CONTENT, $cacheability->getCacheContexts());
        self::assertContains('config:configurable_language_list', $cacheability->getCacheTags());
        self::assertContains('config:language.negotiation', $cacheability->getCacheTags());
      }
    }
    finally {
      $stack->pop();
    }
  }

  /**
   * Makes the node's page the current request.
   */
  private function pushNodeRequest(Node $node): RequestStack {
    $request = Request::create('/node/' . $node->id());
    $route = $this->container->get(RouteProviderInterface::class)->getRouteByName('entity.node.canonical');
    $request->attributes->set('_route', 'entity.node.canonical');
    $request->attributes->set('_route_object', $route);
    $request->attributes->set('node', $node);
    $request->attributes->set('_raw_variables', new ParameterBag(['node' => (string) $node->id()]));
    $stack = $this->container->get(RequestStack::class);
    \assert($stack instanceof RequestStack);
    $stack->push($request);
    return $stack;
  }

  /**
   * Renders a component the way Canvas does on a page.
   *
   * @return array{string, \Drupal\Core\Cache\CacheableMetadata}
   *   The output and its cacheability.
   */
  private function renderThroughComponentSource(JavaScriptComponent $js_component): array {
    $component = Component::load(JsComponent::componentIdFromJavascriptComponentId((string) $js_component->id()));
    \assert($component instanceof Component);
    $build = $component->getComponentSource()->renderComponent([], [], $this->container->get('uuid')->generate(), FALSE);
    $renderer = $this->container->get('renderer');
    \assert($renderer instanceof RendererInterface);
    $output = (string) $renderer->renderInIsolation($build);

    return [$output, CacheableMetadata::createFromRenderArray($build)];
  }

}
