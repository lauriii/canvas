<?php

declare(strict_types=1);

namespace Canvas\PHPStan\Rules;

use Drupal\canvas\Utility\TypedDataHelper;
use Drupal\Core\TypedData\DataDefinitionInterface;
use PhpParser\Node;
use PhpParser\Node\Expr;
use PhpParser\Node\Expr\ArrayDimFetch;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Identifier;
use PhpParser\Node\Scalar\String_;
use PHPStan\Analyser\Scope;
use PHPStan\Rules\Rule;
use PHPStan\Rules\RuleErrorBuilder;
use PHPStan\Type\ObjectType;

/**
 * Requires TypedDataHelper for internal-property checks on typed data.
 *
 * `DataDefinitionInterface::isInternal()` cannot distinguish an explicit
 * `->setInternal(TRUE)` from the value it defaults to for computed
 * properties left unset — both return `TRUE`. This has already caused a real
 * bug twice: code that tried to detect "explicitly marked internal" via
 * `isInternal() && !isComputed()` silently ignored explicit marks on
 * computed properties (e.g. `DateTimeItemOverride` marking `date` internal).
 * Reading the raw definition array directly (e.g. `$definition['internal']`)
 * has the same pitfall if done carelessly outside the one sanctioned place.
 *
 * Both `->isInternal()` and `['internal']` access on a typed data definition
 * must go through `TypedDataHelper::isExplicitlyInternal()` or
 * `::isEffectivelyInternal()` instead, so the distinction is made correctly
 * in exactly one place.
 *
 * @implements Rule<Node>
 */
final class RequireTypedDataHelperForInternalCheckRule implements Rule {

  private const string MESSAGE = 'Do not access "internal" directly on a typed data definition; it cannot distinguish an explicit internal mark from the default for computed properties. Use TypedDataHelper::isExplicitlyInternal() or ::isEffectivelyInternal() instead.';

  public function getNodeType(): string {
    return Node::class;
  }

  public function processNode(Node $node, Scope $scope): array {
    // TypedDataHelper itself is exempt: it is the one sanctioned place to
    // touch these directly.
    $classReflection = $scope->getClassReflection();
    if ($classReflection !== NULL && $classReflection->getName() === TypedDataHelper::class) {
      return [];
    }

    if ($node instanceof MethodCall) {
      return $this->checkMethodCall($node, $scope);
    }

    if ($node instanceof ArrayDimFetch) {
      return $this->checkArrayDimFetch($node, $scope);
    }

    return [];
  }

  /**
   * Flags `$definition->isInternal()` on a DataDefinitionInterface.
   */
  private function checkMethodCall(MethodCall $node, Scope $scope): array {
    if (!$node->name instanceof Identifier || $node->name->toString() !== 'isInternal') {
      return [];
    }
    if (!$this->isDataDefinition($scope, $node->var)) {
      return [];
    }
    return [
      RuleErrorBuilder::message(self::MESSAGE)
        ->identifier('canvas.requireTypedDataHelperForInternalCheck')
        ->build(),
    ];
  }

  /**
   * Flags `$definition['internal']` on a DataDefinitionInterface.
   */
  private function checkArrayDimFetch(ArrayDimFetch $node, Scope $scope): array {
    if (!$node->dim instanceof String_ || $node->dim->value !== 'internal') {
      return [];
    }
    if (!$this->isDataDefinition($scope, $node->var)) {
      return [];
    }
    return [
      RuleErrorBuilder::message(self::MESSAGE)
        ->identifier('canvas.requireTypedDataHelperForInternalCheck')
        ->build(),
    ];
  }

  private function isDataDefinition(Scope $scope, Expr $expr): bool {
    $type = $scope->getType($expr);
    return (new ObjectType(DataDefinitionInterface::class))->isSuperTypeOf($type)->yes();
  }

}
