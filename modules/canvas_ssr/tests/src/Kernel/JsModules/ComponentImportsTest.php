<?php

declare(strict_types=1);

namespace Drupal\Tests\canvas_ssr\Kernel\JsModules;

use Drupal\canvas\AutoSave\AutoSaveManager;
use Drupal\canvas\Entity\JavaScriptComponent;
use Drupal\Tests\canvas_ssr\Kernel\CanvasSsrKernelTestBase;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests the "@/components/*" modules a render registers from its graph.
 *
 * No provider supplies these. Each render adds them from its own graph, so a
 * component can only import what its graph includes.
 *
 * ComponentModulesTest covers their module URLs and how they're rewritten.
 *
 * ComponentGraphTest covers whether a node is the published component or a
 * draft. The draft scenarios here check the render uses the graph's entity
 * rather than loading its own.
 *
 * @legacy-covers \Drupal\canvas_ssr\ComponentInfo\ComponentGraph
 * @legacy-covers \Drupal\canvas_ssr\ComponentInfo\ComponentModules
 * @legacy-covers \Drupal\canvas_ssr\JsModules\AllProvidedModules
 * @legacy-covers \Drupal\canvas_ssr\Renderer
 *
 * @see \Drupal\Tests\canvas_ssr\Kernel\ComponentInfo\ComponentGraphTest
 * @see \Drupal\Tests\canvas_ssr\Kernel\ComponentInfo\ComponentModulesTest
 * @see \Drupal\Tests\canvas_ssr\Kernel\ScenariosTrait
 */
#[RunTestsInSeparateProcesses]
#[Group('canvas')]
#[Group('JavaScriptComponents')]
#[Group('canvas_ssr_requires_runner')]
final class ComponentImportsTest extends CanvasSsrKernelTestBase {

  /**
   * A child that renders a marker, to show which version ran.
   */
  private const string CHILD_JS = <<<'JS'
  import { createElement } from "preact";
  export default function Child() {
    return createElement("span", null, "%s");
  }
  JS;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installRunner();
  }

  /**
   * Tests what a component import resolves to, if anything.
   */
  public function testSsrResolvesComponentImportsFromRendersOwnGraph(): void {
    $this->assertScenarios([
      // Only components listed in the parent's config are registered. Canvas
      // builds the island's scoped import map from the same list.
      'a declared import resolves to the component it names' => function (): void {
        $child_machine_name = $this->randomMachineName();
        $this->createComponent('child component', \sprintf(self::CHILD_JS, 'child-marker'), machine_name: $child_machine_name);
        $parent = $this->parentImporting($child_machine_name, [$child_machine_name]);

        $output = $this->renderComponent($parent);

        self::assertStringContainsString('child-marker', $output);
        self::assertStringNotContainsString(self::CLIENT_ONLY_ATTRIBUTE, $output);
      },

      // The same pair renders above. Here the child isn't listed, so the
      // import fails to resolve, as it would in the browser.
      'an undeclared import fails to resolve rather than being looked up' => function (): void {
        $child_machine_name = $this->randomMachineName();
        $this->createComponent('child component', \sprintf(self::CHILD_JS, 'child-marker'), machine_name: $child_machine_name);
        $parent = $this->parentImporting($child_machine_name);

        $output = $this->renderComponent($parent);

        self::assertStringContainsString(self::CLIENT_ONLY_ATTRIBUTE, $output);
        self::assertStringNotContainsString('child-marker', $output);
      },

      // Auto-saves are keyed by entity, not editor. Using one outside a
      // preview would show someone's draft to every visitor.
      'outside a preview it resolves to the published component' => function (): void {
        $parent = $this->componentImportingDraftedChild();

        $output = $this->renderComponent($parent);

        self::assertStringContainsString('published-marker', $output);
        self::assertStringNotContainsString('draft-marker', $output);
      },

      'a preview resolves it to the draft' => function (): void {
        $parent = $this->componentImportingDraftedChild();

        $output = $this->renderComponent($parent, preview: TRUE);

        self::assertStringContainsString('draft-marker', $output);
        self::assertStringNotContainsString('published-marker', $output);
      },
    ]);
  }

  /**
   * Creates a component importing a child that has a draft.
   *
   * The published child and its draft render different markers.
   *
   * @return \Drupal\canvas\Entity\JavaScriptComponent
   *   The parent, which has no draft of its own.
   */
  private function componentImportingDraftedChild(): JavaScriptComponent {
    $child_machine_name = $this->randomMachineName();
    $this->createComponent('child component', \sprintf(self::CHILD_JS, 'published-marker'), machine_name: $child_machine_name);

    $draft_source = \sprintf(self::CHILD_JS, 'draft-marker');
    $draft = JavaScriptComponent::load($child_machine_name);
    self::assertInstanceOf(JavaScriptComponent::class, $draft);
    $draft->set('js', ['original' => $draft_source, 'compiled' => $draft_source]);
    $auto_save_manager = $this->container->get(AutoSaveManager::class);
    \assert($auto_save_manager instanceof AutoSaveManager);
    $auto_save_manager->saveEntity($draft);

    return $this->parentImporting($child_machine_name, [$child_machine_name]);
  }

  /**
   * Creates a component whose source imports one other component.
   *
   * @param string $child_machine_name
   *   The machine name the source imports.
   * @param array<int, string> $declared
   *   The imports its config lists. SSR registers modules from these.
   */
  private function parentImporting(string $child_machine_name, array $declared = []): JavaScriptComponent {
    $parent_source = <<<JS
    import Child from "@/components/{$child_machine_name}";
    import { createElement } from "preact";
    export default function Parent() {
      return createElement("div", null, createElement(Child, null));
    }
    JS;

    return $this->createComponent(
      $declared === [] ? 'parent component importing an undeclared child' : 'parent component',
      $parent_source,
      imports: $declared,
    );
  }

}
