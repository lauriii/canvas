<?php

declare(strict_types=1);

namespace Drupal\Tests\canvas_ssr\Kernel\ComponentInfo;

use Drupal\canvas\AutoSave\AutoSaveManager;
use Drupal\canvas\Entity\JavaScriptComponent;
use Drupal\canvas_ssr\ComponentInfo\ComponentGraph;
use Drupal\Tests\canvas_ssr\Kernel\CanvasSsrKernelTestBase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests which components a graph reaches, and whether drafts fill it.
 *
 * A node is its component's draft only when an auto-save manager is passed.
 *
 * @see \Drupal\Tests\canvas_ssr\Kernel\ScenariosTrait
 */
#[RunTestsInSeparateProcesses]
#[CoversClass(ComponentGraph::class)]
#[Group('canvas')]
#[Group('JavaScriptComponents')]
final class ComponentGraphTest extends CanvasSsrKernelTestBase {

  /**
   * Tests what a graph reaches, and when drafts fill it.
   *
   * The scenarios share one site install.
   */
  public function testGraph(): void {
    $this->assertScenarios([
      ...$this->walkScenarios(),
      ...$this->draftScenarios(),
    ]);
  }

  /**
   * Scenarios for what a walk reaches, and how often each appears.
   *
   * @return array<string, callable():void>
   *   Scenarios keyed by the claim each makes.
   */
  private function walkScenarios(): array {
    return [
      'the walk follows dependencies all the way down' => function (): void {
        $leaf = $this->componentDependingOn('leaf', []);
        $middle = $this->componentDependingOn('middle', [(string) $leaf->id()]);
        $root = $this->componentDependingOn('root', [(string) $middle->id()]);

        $graph = ComponentGraph::of($root);

        self::assertSame($root->id(), $graph->root->id());
        self::assertEqualsCanonicalizing(
          [$middle->id(), $leaf->id()],
          \array_keys($graph->dependencies),
        );
      },

      'a component reached down two branches appears once' => function (): void {
        $leaf = $this->componentDependingOn('leaf', []);
        $a = $this->componentDependingOn('a', [(string) $leaf->id()]);
        $b = $this->componentDependingOn('b', [(string) $leaf->id()]);
        $root = $this->componentDependingOn('root above a and b', [(string) $a->id(), (string) $b->id()]);

        $graph = ComponentGraph::of($root);

        self::assertEqualsCanonicalizing(
          [$a->id(), $b->id(), $leaf->id()],
          \array_keys($graph->dependencies),
        );
      },

      'a cycle terminates and does not list the root as its own dependency' => function (): void {
        $a_name = $this->randomMachineName();
        $a = $this->createComponent('a', self::INERT_JS, machine_name: $a_name);
        $b = $this->componentDependingOn('b depends on a', [$a_name]);

        // Close the cycle: a -> b -> a.
        $a->updateFromClientSide([
          'sourceCodeJs' => self::INERT_JS,
          'compiledJs' => self::INERT_JS,
          'importedJsComponents' => [(string) $b->id()],
        ]);
        $a->save();

        $graph = ComponentGraph::of($a);

        self::assertSame([$b->id()], \array_keys($graph->dependencies));
        self::assertSame([$a->id(), $b->id()], \array_map(
          static fn(JavaScriptComponent $component): string => (string) $component->id(),
          $graph->all(),
        ));
      },
    ];
  }

  /**
   * Scenarios for drafts standing in for components only in previews.
   *
   * @return array<string, callable():void>
   *   Scenarios keyed by the claim each makes.
   */
  private function draftScenarios(): array {
    return [
      // Auto-saves are keyed by entity, not editor. Using one outside a
      // preview would show someone's draft to every visitor.
      'outside a preview, the graph has published components only' => function (): void {
        [$root] = $this->componentWithDraftedDependency();

        $graph = ComponentGraph::of($root);

        self::assertSame([], $graph->dependencies);
        self::assertSame('published-root', self::markerOf($graph->root));
      },

      'a preview renders what the editor is looking at' => function (): void {
        [$root, $dependency] = $this->componentWithDraftedDependency();

        $graph = ComponentGraph::of($root, $this->autoSaveManager());

        self::assertSame([$dependency->id()], \array_keys($graph->dependencies));
        self::assertSame('draft-root', self::markerOf($graph->root));
      },

      // Canvas reads the dependency list from the draft but returns
      // published components, so each node must be swapped for its draft,
      // not just the root.
      'a dependency reached in a preview is swapped for its own draft' => function (): void {
        $dependency = $this->markedComponent('published-dependency');
        $root = $this->componentDependingOn('root', [(string) $dependency->id()]);
        $this->draft($dependency, 'draft-dependency');

        $published = ComponentGraph::of($root);
        $preview = ComponentGraph::of($root, $this->autoSaveManager());

        self::assertSame('published-dependency', self::markerOf($published->dependencies[(string) $dependency->id()]));
        self::assertSame('draft-dependency', self::markerOf($preview->dependencies[(string) $dependency->id()]));
      },
    ];
  }

  /**
   * Creates a root whose draft adds a dependency the published root lacks.
   *
   * The draft also changes the root's marker.
   *
   * @return array{\Drupal\canvas\Entity\JavaScriptComponent, \Drupal\canvas\Entity\JavaScriptComponent}
   *   The published root and the dependency only its draft has.
   */
  private function componentWithDraftedDependency(): array {
    $dependency = $this->markedComponent('published-dependency');
    $root = $this->markedComponent('published-root');
    $this->draft($root, 'draft-root', [(string) $dependency->id()]);

    return [$root, $dependency];
  }

  /**
   * Creates a component whose source holds a marker.
   *
   * @param string $marker
   *   The marker.
   */
  private function markedComponent(string $marker): JavaScriptComponent {
    return $this->createComponent($marker, self::markerJs($marker));
  }

  /**
   * Builds component source holding a marker.
   */
  private static function markerJs(string $marker): string {
    return \sprintf('export default () => %s;', \json_encode($marker, JSON_THROW_ON_ERROR));
  }

  /**
   * Reads the marker from a component's compiled JS.
   */
  private static function markerOf(JavaScriptComponent $component): string {
    $js = $component->get('js');
    self::assertIsArray($js);
    $matched = \preg_match('/"([^"]+)"/', (string) ($js['compiled'] ?? ''), $matches);
    self::assertSame(1, $matched, 'Graph node carries a marker.');

    return $matches[1];
  }

  /**
   * Auto-saves a draft of a component.
   *
   * @param \Drupal\canvas\Entity\JavaScriptComponent $component
   *   The published component.
   * @param string $marker
   *   The draft's marker.
   * @param array<int, string> $imported_js_components
   *   Machine names of the draft's dependencies.
   */
  private function draft(
    JavaScriptComponent $component,
    string $marker,
    array $imported_js_components = [],
  ): void {
    $draft = JavaScriptComponent::load((string) $component->id());
    self::assertInstanceOf(JavaScriptComponent::class, $draft);
    $draft->updateFromClientSide([
      'sourceCodeJs' => self::markerJs($marker),
      'compiledJs' => self::markerJs($marker),
      'importedJsComponents' => $imported_js_components,
    ]);
    $this->autoSaveManager()->saveEntity($draft);
  }

  /**
   * Gets the auto-save manager.
   */
  private function autoSaveManager(): AutoSaveManager {
    $auto_save_manager = $this->container->get(AutoSaveManager::class);
    \assert($auto_save_manager instanceof AutoSaveManager);

    return $auto_save_manager;
  }

  /**
   * Creates a component whose config lists the given dependencies.
   *
   * The walk follows the config's list, not the imports in source.
   *
   * @param string $label
   *   The component's label.
   * @param array<int, string> $machine_names
   *   Machine names of the components it depends on.
   */
  private function componentDependingOn(string $label, array $machine_names): JavaScriptComponent {
    return $this->createComponent($label, self::INERT_JS, imports: $machine_names);
  }

}
