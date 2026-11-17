<?php

declare(strict_types=1);

namespace Drupal\canvas_ssr\ComponentInfo;

use Drupal\canvas\AutoSave\AutoSaveManager;
use Drupal\canvas\AutoSaveEntity;
use Drupal\canvas\Entity\JavaScriptComponent;

/**
 * A component and every component it depends on, directly or not.
 *
 * The dependencies come from the components' config, as Canvas's scoped
 * import map does, so no source needs parsing.
 *
 * Each node is the entity that gets rendered: in a preview, the draft if
 * there is one.
 *
 * @see \Drupal\canvas_ssr\Renderer
 */
final readonly class ComponentGraph {

  /**
   * Constructs a ComponentGraph.
   *
   * @param \Drupal\canvas\Entity\JavaScriptComponent $root
   *   The root component.
   * @param array<string, \Drupal\canvas\Entity\JavaScriptComponent> $dependencies
   *   Its dependencies, keyed by machine name. Never includes the root, even
   *   in a cycle.
   * @param array<string, list<string>> $edges
   *   Each component's direct dependencies, by machine name, keyed by its
   *   own. They can form a cycle.
   */
  private function __construct(
    public JavaScriptComponent $root,
    public array $dependencies,
    public array $edges,
  ) {}

  /**
   * Builds a component's graph.
   *
   * @param \Drupal\canvas\Entity\JavaScriptComponent $root
   *   The root component.
   * @param \Drupal\canvas\AutoSave\AutoSaveManager|null $auto_save_manager
   *   The manager, for a preview, where drafts stand in for published
   *   components. NULL for any other render. Never pass it for one: drafts
   *   aren't per editor, so visitors would see someone's draft.
   *
   * @return self
   *   The graph.
   */
  public static function of(
    JavaScriptComponent $root,
    ?AutoSaveManager $auto_save_manager = NULL,
  ): self {
    $collected = [];
    $edges = [];
    $pending = [$root];
    while ($pending !== []) {
      $current = \array_shift($pending);
      $auto_save = $auto_save_manager?->getAutoSaveEntity($current) ?? AutoSaveEntity::empty();
      $dependencies = $current->getComponentDependencies($auto_save, $auto_save_manager !== NULL);
      $edges[(string) $current->id()] = [];
      foreach ($dependencies as $dependency) {
        $machine_name = $dependency->id();
        if ($machine_name !== NULL) {
          $edges[(string) $current->id()][] = $machine_name;
        }
        // $collected doubles as the visited set, so cycles end and shared
        // dependencies are walked once.
        if ($machine_name === NULL || isset($collected[$machine_name])) {
          continue;
        }
        $collected[$machine_name] = self::renderedEntity($dependency, $auto_save_manager);
        $pending[] = $collected[$machine_name];
      }
    }
    // A cycle can collect the root too.
    unset($collected[(string) $root->id()]);

    return new self(self::renderedEntity($root, $auto_save_manager), $collected, $edges);
  }

  /**
   * Returns every component in the graph, root first.
   *
   * @return list<\Drupal\canvas\Entity\JavaScriptComponent>
   *   The components.
   */
  public function all(): array {
    return [$this->root, ...\array_values($this->dependencies)];
  }

  /**
   * Returns the entity that gets rendered for a component.
   *
   * @param \Drupal\canvas\Entity\JavaScriptComponent $component
   *   The published component.
   * @param \Drupal\canvas\AutoSave\AutoSaveManager|null $auto_save_manager
   *   The manager, or NULL if this isn't a preview.
   *
   * @return \Drupal\canvas\Entity\JavaScriptComponent
   *   The draft if there is one, otherwise the published component.
   */
  private static function renderedEntity(
    JavaScriptComponent $component,
    ?AutoSaveManager $auto_save_manager,
  ): JavaScriptComponent {
    if ($auto_save_manager === NULL) {
      return $component;
    }
    // getComponentDependencies() reads the draft's dependencies but returns
    // published entities, so swap each one for its draft here.
    $auto_save = $auto_save_manager->getAutoSaveEntity($component);
    if ($auto_save->isEmpty()) {
      return $component;
    }
    \assert($auto_save->entity instanceof JavaScriptComponent);

    return $auto_save->entity;
  }

}
