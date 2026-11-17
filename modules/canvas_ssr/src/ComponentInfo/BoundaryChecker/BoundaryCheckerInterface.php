<?php

declare(strict_types=1);

namespace Drupal\canvas_ssr\ComponentInfo\BoundaryChecker;

use Drupal\canvas\Entity\JavaScriptComponent;

/**
 * Finds whether a component's graph has a client boundary.
 *
 * Each framework marks client boundaries its own way, such as React's
 * "use client". An implementation follows one convention and lists the
 * frameworks that use it.
 *
 * A checker looks only at the entities it's given. The caller picks them,
 * including whether drafts stand in for published components.
 *
 * @see \Drupal\canvas_ssr\ComponentInfo\BoundaryCheckers
 * @see docs/adr/0003-a-boundary-checker-decides-hydration.md
 */
interface BoundaryCheckerInterface {

  /**
   * Gets the frameworks this checker handles.
   *
   * These are the names islands use for #framework, such as "preact".
   *
   * @return array<int, string>
   *   The framework names.
   */
  public function getFrameworks(): array;

  /**
   * Decides whether an island of the component is a hydrated island.
   *
   * If not, it's a static island: the markup is final and ships no
   * component code.
   *
   * @param \Drupal\canvas\Entity\JavaScriptComponent $component
   *   The root component.
   * @param array<string, \Drupal\canvas\Entity\JavaScriptComponent> $dependencies
   *   All of its dependencies, indirect ones included, keyed by machine
   *   name.
   *
   * @return bool|null
   *   TRUE if the graph has a client boundary, so the markup hydrates, and
   *   FALSE if it has none. NULL if the checker can't tell, as when a
   *   component's compiled JS can't be parsed. The island then falls back
   *   to CSR, since rendering it might ship markup that never hydrates.
   */
  public function hydrates(JavaScriptComponent $component, array $dependencies): ?bool;

}
