<?php

declare(strict_types=1);

namespace Drupal\canvas_ssr\ComponentInfo\BoundaryChecker;

use Drupal\canvas\Entity\JavaScriptComponent;
use Drupal\canvas_ssr\JsParser\JsModuleParser;

/**
 * Applies React's "use client" rule, for React and Preact.
 *
 * A component that needs browser APIs, state or effects starts with "use
 * client", which makes it a client boundary. One anywhere in the graph makes
 * the whole island hydrate.
 *
 * @see https://react.dev/reference/rsc/use-client
 */
final class ReactBoundaryChecker implements BoundaryCheckerInterface {

  /**
   * The frameworks this checker handles.
   */
  private const array FRAMEWORKS = ['react', 'preact'];

  /**
   * The directive that marks a client boundary.
   */
  private const string USE_CLIENT_DIRECTIVE = 'use client';

  public function __construct(
    private readonly JsModuleParser $jsModuleParser,
  ) {}

  /**
   * {@inheritdoc}
   */
  public function getFrameworks(): array {
    return self::FRAMEWORKS;
  }

  /**
   * {@inheritdoc}
   */
  public function hydrates(JavaScriptComponent $component, array $dependencies): ?bool {
    $hydrates = FALSE;
    // Every component is parsed, even after a boundary is found, since one
    // that can't be parsed can't have its imports rewritten either.
    foreach ([$component, ...\array_values($dependencies)] as $entity) {
      $declares = $this->declaresUseClient($entity);
      if ($declares === NULL) {
        return NULL;
      }
      $hydrates = $hydrates || $declares;
    }

    return $hydrates;
  }

  /**
   * Checks whether a component's compiled JS starts with "use client".
   *
   * This reads the compiled JS because Peast can't parse the TypeScript
   * components are written in. That relies on the compiler keeping the
   * directive. SWC, which Canvas uses, does. If it stopped, components
   * would silently stop hydrating.
   *
   * @param \Drupal\canvas\Entity\JavaScriptComponent $component
   *   The component.
   *
   * @return bool|null
   *   TRUE if the directive is in the module's directive prologue, or NULL
   *   if the compiled JS can't be parsed.
   *
   * @see \Drupal\canvas_ssr\JsParser\JsModuleParser
   */
  private function declaresUseClient(JavaScriptComponent $component): ?bool {
    if (!$component->hasJs()) {
      return FALSE;
    }

    return $this->jsModuleParser->parse($component->getJs(), (string) $component->id())?->hasDirective(self::USE_CLIENT_DIRECTIVE);
  }

}
