<?php

declare(strict_types=1);

namespace Canvas\PHPStan\Rules;

use Drupal\Core\Routing\Access\AccessInterface;
use PhpParser\Node;
use PhpParser\Node\Expr\Variable;
use PhpParser\Node\Stmt\ClassMethod;
use PhpParser\NodeFinder;
use PHPStan\Analyser\Scope;
use PHPStan\Reflection\ClassReflection;
use PHPStan\Rules\Rule;
use PHPStan\Rules\RuleErrorBuilder;
use PHPUnit\Framework\TestCase;

/**
 * Detects non-static methods that do not use $this.
 *
 * Such methods should be declared static, as documented at
 * https://web.archive.org/web/20260215134806/https://www.drupal4hu.com/node/416.html.
 *
 * Excludes:
 * - Methods that are already static.
 * - Abstract methods (no body to analyze).
 * - Methods inside traits (may need $this in consuming classes).
 * - Methods inside anonymous classes.
 * - Methods that override/implement a parent or interface method.
 * - Magic methods (__construct, __destruct, __clone, etc.).
 * - Test data provider methods (must be static in PHPUnit 10+, but flagged
 *   separately).
 *
 * @implements Rule<ClassMethod>
 */
final class RequireStaticMethodsRule implements Rule {

  public function getNodeType(): string {
    return ClassMethod::class;
  }

  public function processNode(Node $node, Scope $scope): array {
    \assert($node instanceof ClassMethod);

    // Already static: nothing to flag.
    if ($node->isStatic()) {
      return [];
    }

    // Abstract methods have no body to analyze.
    if ($node->isAbstract()) {
      return [];
    }

    // Skip trait methods: they may rely on $this in consuming classes.
    if ($scope->isInTrait()) {
      return [];
    }

    $classReflection = $scope->getClassReflection();
    if ($classReflection === NULL) {
      return [];
    }

    // Skip anonymous classes.
    if ($classReflection->isAnonymous()) {
      return [];
    }

    $methodName = $node->name->toString();

    // Skip magic methods.
    if (str_starts_with($methodName, '__')) {
      return [];
    }

    // Skip PHPUnit test methods in actual test classes.
    if (str_starts_with($methodName, 'test') && $classReflection->isSubclassOf(TestCase::class)) {
      return [];
    }

    // Skip methods that override a parent method or implement an interface
    // method — their signature is dictated externally.
    if ($this->isOverrideOrImplementation($classReflection, $methodName)) {
      return [];
    }

    // Skip the access() method on AccessInterface implementations: this
    // interface does not define any methods, but everyone assumes the
    // access() signature by convention.
    // @see https://www.drupal.org/node/2266817
    if ($methodName === 'access' && $classReflection->implementsInterface(AccessInterface::class)) {
      return [];
    }

    // Skip overridable methods in abstract classes: subclasses may override
    // with an implementation that uses $this.
    if ($classReflection->isAbstract() && !$node->isPrivate() && !$node->isFinal()) {
      return [];
    }

    // Check whether $this is used anywhere in the method body.
    if ($node->stmts === NULL) {
      return [];
    }

    $nodeFinder = new NodeFinder();
    $thisUsages = $nodeFinder->find($node->stmts, static function (Node $n): bool {
      return $n instanceof Variable && $n->name === 'this';
    });

    if ($thisUsages !== []) {
      return [];
    }

    return [
      RuleErrorBuilder::message(
        \sprintf(
          'Method %s::%s() does not use $this and should be declared static.',
          $classReflection->getName(),
          $methodName,
        )
      )
        ->identifier('canvas.requireStaticMethods')
        ->build(),
    ];
  }

  /**
   * Checks if a method overrides a parent method or implements an interface.
   */
  private function isOverrideOrImplementation(ClassReflection $classReflection, string $methodName): bool {
    // Check all ancestor classes and interfaces.
    foreach ($classReflection->getAncestors() as $ancestor) {
      if ($ancestor === $classReflection) {
        continue;
      }
      if ($ancestor->hasMethod($methodName)) {
        return TRUE;
      }
    }

    // Check traits used by this class.
    foreach ($classReflection->getTraits() as $trait) {
      if ($trait->hasMethod($methodName)) {
        return TRUE;
      }
    }

    return FALSE;
  }

}
