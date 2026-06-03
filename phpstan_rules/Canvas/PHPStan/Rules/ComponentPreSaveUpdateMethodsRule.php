<?php

declare(strict_types=1);

namespace Canvas\PHPStan\Rules;

use Drupal\canvas\Attribute\ComponentPreSaveUpdate;
use Drupal\canvas\CanvasConfigUpdater;
use Drupal\canvas\Entity\Component;
use PhpParser\Node;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Identifier;
use PhpParser\Node\Name;
use PhpParser\Node\Stmt\ClassMethod;
use PhpParser\NodeFinder;
use PHPStan\Analyser\Scope;
use PHPStan\Reflection\ReflectionProvider;
use PHPStan\Rules\Rule;
use PHPStan\Rules\RuleErrorBuilder;

/**
 * Enforces the wiring of #[ComponentPreSaveUpdate] CanvasConfigUpdater methods.
 *
 * A CanvasConfigUpdater update-path method runs from its post_update once per
 * site, but post-updates never run a second time (nor for imported config), so
 * each one must ALSO be invoked from Component::preSave() to heal a component
 * that is re-saved outside the update path. This rule checks, for every method
 * marked #[ComponentPreSaveUpdate], that:
 * - it is invoked from Component::preSave() (freshly-saved config heals), and
 * - the `postUpdate` it names exists (already-stored config heals on update).
 *
 * @see \Drupal\canvas\Attribute\ComponentPreSaveUpdate
 * @see \Drupal\canvas\Entity\Component::preSave()
 *
 * @implements Rule<ClassMethod>
 */
final class ComponentPreSaveUpdateMethodsRule implements Rule {

  public function __construct(
    private readonly ReflectionProvider $reflectionProvider,
  ) {}

  public function getNodeType(): string {
    return ClassMethod::class;
  }

  public function processNode(Node $node, Scope $scope): array {
    \assert($node instanceof ClassMethod);

    // Only the body of Component::preSave() is relevant.
    if ($node->name->toString() !== 'preSave') {
      return [];
    }
    $class = $scope->getClassReflection();
    if ($class === NULL || $class->getName() !== Component::class) {
      return [];
    }

    // The method names actually invoked inside preSave().
    $called = [];
    foreach ((new NodeFinder())->findInstanceOf($node->stmts ?? [], MethodCall::class) as $call) {
      if ($call->name instanceof Identifier) {
        $called[$call->name->toString()] = TRUE;
      }
    }

    $errors = [];
    foreach ((new \ReflectionClass(CanvasConfigUpdater::class))->getMethods() as $method) {
      $attributes = $method->getAttributes(ComponentPreSaveUpdate::class);
      if ($attributes === []) {
        continue;
      }
      $methodName = $method->getName();

      // 1. It must be wired into Component::preSave().
      if (!isset($called[$methodName])) {
        $errors[] = RuleErrorBuilder::message(\sprintf(
          '%s::%s() is marked #[ComponentPreSaveUpdate] but is not invoked from %s::preSave(); a component re-saved outside the update path would silently keep its outdated data.',
          CanvasConfigUpdater::class,
          $methodName,
          Component::class,
        ))->identifier('canvas.componentPreSaveUpdate')->build();
      }

      // 2. The post_update it names must exist (so already-stored config on
      // existing sites is healed, not only freshly-saved config).
      $postUpdate = $attributes[0]->newInstance()->postUpdate;
      if (!$this->reflectionProvider->hasFunction(new Name($postUpdate), NULL)) {
        $errors[] = RuleErrorBuilder::message(\sprintf(
          '%s::%s() names post_update "%s()" via #[ComponentPreSaveUpdate], but no such function exists; existing sites would never get this update.',
          CanvasConfigUpdater::class,
          $methodName,
          $postUpdate,
        ))->identifier('canvas.componentPreSaveUpdatePostUpdate')->build();
      }
    }
    return $errors;
  }

}
