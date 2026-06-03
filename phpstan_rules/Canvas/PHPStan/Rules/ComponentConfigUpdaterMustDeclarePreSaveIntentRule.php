<?php

declare(strict_types=1);

namespace Canvas\PHPStan\Rules;

use Drupal\canvas\Attribute\ComponentPreSaveUpdate;
use Drupal\canvas\Attribute\NotAppliedOnComponentPreSave;
use Drupal\canvas\CanvasConfigUpdater;
use Drupal\canvas\Entity\Component;
use PhpParser\Node;
use PhpParser\Node\Stmt\ClassMethod;
use PHPStan\Analyser\Scope;
use PHPStan\Rules\Rule;
use PHPStan\Rules\RuleErrorBuilder;

/**
 * Component-updating CanvasConfigUpdater methods must declare preSave intent.
 *
 * The dangerous, easy-to-forget mistake is adding a new CanvasConfigUpdater
 * method that mutates a Component but neglecting to wire it into
 * Component::preSave(). To make that impossible to do silently, every public
 * CanvasConfigUpdater method that takes a Component (and is not a `needs*`
 * predicate) must explicitly declare its intent with exactly one of:
 * - #[ComponentPreSaveUpdate]: it heals on save (and is checked to be wired in
 *   by \Canvas\PHPStan\Rules\ComponentPreSaveUpdateMethodsRule), or
 * - #[NotAppliedOnComponentPreSave]: it deliberately must not run on save.
 *
 * @see \Drupal\canvas\Attribute\ComponentPreSaveUpdate
 * @see \Drupal\canvas\Attribute\NotAppliedOnComponentPreSave
 *
 * @implements Rule<ClassMethod>
 */
final class ComponentConfigUpdaterMustDeclarePreSaveIntentRule implements Rule {

  public function getNodeType(): string {
    return ClassMethod::class;
  }

  public function processNode(Node $node, Scope $scope): array {
    \assert($node instanceof ClassMethod);

    $class = $scope->getClassReflection();
    if ($class === NULL || $class->getName() !== CanvasConfigUpdater::class) {
      return [];
    }
    if (!$node->isPublic()) {
      return [];
    }
    $methodName = $node->name->toString();
    // `needs*` methods are predicates, not mutators: they never change a
    // Component, so they need not run on save.
    if (\str_starts_with($methodName, 'needs')) {
      return [];
    }

    $reflectionMethod = new \ReflectionMethod(CanvasConfigUpdater::class, $methodName);
    if (!$this->takesComponent($reflectionMethod)) {
      return [];
    }

    $optIn = $reflectionMethod->getAttributes(ComponentPreSaveUpdate::class) !== [];
    $optOut = $reflectionMethod->getAttributes(NotAppliedOnComponentPreSave::class) !== [];

    if ($optIn && $optOut) {
      return [
        RuleErrorBuilder::message(\sprintf(
          '%s::%s() declares both #[ComponentPreSaveUpdate] and #[NotAppliedOnComponentPreSave]; it must declare exactly one.',
          CanvasConfigUpdater::class,
          $methodName,
        ))->identifier('canvas.componentPreSaveUpdateIntent')->build(),
      ];
    }

    if (!$optIn && !$optOut) {
      return [
        RuleErrorBuilder::message(\sprintf(
          '%s::%s() takes a Component but declares no preSave intent. Add #[ComponentPreSaveUpdate] (it must run on save — easy to forget!) or #[NotAppliedOnComponentPreSave] (it deliberately must not).',
          CanvasConfigUpdater::class,
          $methodName,
        ))->identifier('canvas.componentPreSaveUpdateIntent')->build(),
      ];
    }

    return [];
  }

  /**
   * Whether the method accepts a Component (directly or within a union type).
   */
  private function takesComponent(\ReflectionMethod $method): bool {
    foreach ($method->getParameters() as $parameter) {
      $type = $parameter->getType();
      $named = $type instanceof \ReflectionUnionType ? $type->getTypes() : [$type];
      foreach ($named as $candidate) {
        if ($candidate instanceof \ReflectionNamedType && \ltrim($candidate->getName(), '\\') === Component::class) {
          return TRUE;
        }
      }
    }
    return FALSE;
  }

}
