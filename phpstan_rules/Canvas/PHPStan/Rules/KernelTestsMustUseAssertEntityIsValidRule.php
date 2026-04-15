<?php

declare(strict_types=1);

namespace Canvas\PHPStan\Rules;

use Drupal\Core\Config\Entity\ConfigEntityInterface;
use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Tests\canvas\Kernel\CanvasKernelTestBase;
use PhpParser\Node;
use PhpParser\Node\Arg;
use PhpParser\Node\Expr;
use PhpParser\Node\Expr\Array_;
use PhpParser\Node\Expr\CallLike;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Expr\StaticCall;
use PhpParser\Node\Expr\Variable;
use PhpParser\Node\Identifier;
use PhpParser\Node\Scalar\LNumber;
use PHPStan\Analyser\Scope;
use PHPStan\Rules\Rule;
use PHPStan\Rules\RuleErrorBuilder;
use PHPStan\Type\ObjectType;
use PHPStan\Type\Type;

/**
 * Requires assertEntityIsValid() instead of various one-off implementations.
 *
 * Detects the following anti-patterns in CanvasKernelTestBase subclasses:
 * - assertSame([], self::violationsToArray(…))
 * - assertCount(0, $entity->validate())
 * - assertCount(0, $entity->getTypedData()->validate())
 * - assertCount(0, $violations, …getMessage…)
 *
 * Uses PHPStan's type information to distinguish entity validate() calls from
 * non-entity typed data validate() calls (e.g. field item lists), eliminating
 * the need for variable-name heuristics.
 *
 * @implements Rule<CallLike>
 */
final class KernelTestsMustUseAssertEntityIsValidRule implements Rule {

  public function getNodeType(): string {
    return CallLike::class;
  }

  public function processNode(Node $node, Scope $scope): array {
    if (!$node instanceof StaticCall && !$node instanceof MethodCall) {
      return [];
    }

    $methodName = $this->getCallName($node);
    if ($methodName !== 'assertSame' && $methodName !== 'assertCount') {
      return [];
    }

    // Only enforce in CanvasKernelTestBase subclasses. isSubclassOf() returns
    // FALSE for CanvasKernelTestBase itself (where assertEntityIsValid() is
    // defined), which is exactly what we want.
    $classReflection = $scope->getClassReflection();
    if ($classReflection === NULL || !$classReflection->isSubclassOf(CanvasKernelTestBase::class)) {
      return [];
    }

    return match ($methodName) {
      'assertSame' => $this->checkAssertSamePattern($node, $scope),
      'assertCount' => $this->checkAssertCountPattern($node, $scope),
    };
  }

  /**
   * Checks for assertSame([], self::violationsToArray(…)).
   *
   * @return list<\PHPStan\Rules\RuleError>
   */
  private function checkAssertSamePattern(CallLike $node, Scope $scope): array {
    $args = $node->getArgs();
    if (\count($args) < 2) {
      return [];
    }

    // First argument must be `[]`.
    if (!$this->isEmptyArray($args[0])) {
      return [];
    }

    // Second argument must be self::violationsToArray(…) or
    // static::violationsToArray(…).
    $secondArg = $args[1]->value;
    if (!$secondArg instanceof StaticCall) {
      return [];
    }
    if (!$secondArg->name instanceof Identifier || $secondArg->name->name !== 'violationsToArray') {
      return [];
    }

    // Check what is inside violationsToArray(…).
    $innerArgs = $secondArg->getArgs();
    if (\count($innerArgs) !== 1) {
      return [];
    }
    $innerExpr = $innerArgs[0]->value;

    // If the argument to violationsToArray() is a ->validate() call, check if
    // it is on an entity.
    if ($this->isEntityValidateCall($innerExpr, $scope)) {
      return [
        RuleErrorBuilder::message(
          'Use self::assertEntityIsValid($entity) instead of assertSame([], self::violationsToArray(…)). The assertEntityIsValid() method is provided by CanvasKernelTestBase.'
        )
          ->identifier('canvas.requireAssertEntityIsValid')
          ->build(),
      ];
    }

    return [];
  }

  /**
   * Checks for assertCount(0, $entity->validate()) and assertCount(0, $v).
   *
   * @return list<\PHPStan\Rules\RuleError>
   */
  private function checkAssertCountPattern(CallLike $node, Scope $scope): array {
    $args = $node->getArgs();
    if (\count($args) < 2) {
      return [];
    }

    // First argument must be `0`.
    if (!$this->isZeroLiteral($args[0])) {
      return [];
    }

    $secondArg = $args[1]->value;

    // If the second argument is a ->validate() call on an entity, flag it.
    if ($this->isEntityValidateCall($secondArg, $scope)) {
      return [
        RuleErrorBuilder::message(
          'Use self::assertEntityIsValid($entity) instead of assertCount(0, …->validate()). The assertEntityIsValid() method is provided by CanvasKernelTestBase.'
        )
          ->identifier('canvas.requireAssertEntityIsValid')
          ->build(),
      ];
    }

    // If the second argument is a variable and any subsequent argument contains
    // a getMessage() call, this strongly indicates an assertion on constraint
    // violations.
    if ($secondArg instanceof Variable && \count($args) >= 3) {
      for ($i = 2; $i < \count($args); $i++) {
        if ($this->containsGetMessageCall($args[$i]->value)) {
          return [
            RuleErrorBuilder::message(
              'Use self::assertEntityIsValid($entity) instead of assertCount(0, $violations, …). The assertEntityIsValid() method is provided by CanvasKernelTestBase.'
            )
              ->identifier('canvas.requireAssertEntityIsValid')
              ->build(),
          ];
        }
      }
    }

    return [];
  }

  /**
   * Determines whether an expression is an entity's validate() call.
   *
   * Recognises two forms:
   * - $entity->validate()
   * - $entity->getTypedData()->validate()
   *
   * Uses PHPStan type information to distinguish entity validate() calls from
   * non-entity typed data validate() calls (field item lists, etc.).
   */
  private function isEntityValidateCall(Expr $expr, Scope $scope): bool {
    if (!$expr instanceof MethodCall) {
      return FALSE;
    }
    if (!$expr->name instanceof Identifier || $expr->name->name !== 'validate') {
      return FALSE;
    }

    // $entity->getTypedData()->validate(): check the type of $entity.
    if ($expr->var instanceof MethodCall
      && $expr->var->name instanceof Identifier
      && $expr->var->name->name === 'getTypedData'
    ) {
      return $this->isEntityType($scope->getType($expr->var->var));
    }

    // $entity->validate(): check the type of $entity.
    return $this->isEntityType($scope->getType($expr->var));
  }

  /**
   * Checks whether a type is a content entity or config entity type.
   */
  private function isEntityType(Type $type): bool {
    $contentEntityType = new ObjectType(ContentEntityInterface::class);
    $configEntityType = new ObjectType(ConfigEntityInterface::class);

    return $contentEntityType->isSuperTypeOf($type)->yes()
      || $configEntityType->isSuperTypeOf($type)->yes();
  }

  /**
   * Returns the called method name, or NULL for dynamic/unresolvable names.
   */
  private function getCallName(StaticCall|MethodCall $node): ?string {
    if ($node->name instanceof Identifier) {
      return $node->name->name;
    }
    return NULL;
  }

  /**
   * Checks whether an argument is an empty array literal `[]`.
   */
  private function isEmptyArray(Arg $arg): bool {
    return $arg->value instanceof Array_ && $arg->value->items === [];
  }

  /**
   * Checks whether an argument is the integer literal `0`.
   */
  private function isZeroLiteral(Arg $arg): bool {
    return $arg->value instanceof LNumber && $arg->value->value === 0;
  }

  /**
   * Recursively checks whether an expression tree contains a getMessage() call.
   */
  private function containsGetMessageCall(Expr $expr): bool {
    if ($expr instanceof MethodCall
      && $expr->name instanceof Identifier
      && $expr->name->name === 'getMessage'
    ) {
      return TRUE;
    }

    foreach ($expr->getSubNodeNames() as $name) {
      $subNode = $expr->$name;
      if ($subNode instanceof Expr && $this->containsGetMessageCall($subNode)) {
        return TRUE;
      }
      if (\is_array($subNode)) {
        foreach ($subNode as $item) {
          if ($item instanceof Expr && $this->containsGetMessageCall($item)) {
            return TRUE;
          }
          // Handle Arg nodes (which wrap Expr nodes).
          if ($item instanceof Arg && $this->containsGetMessageCall($item->value)) {
            return TRUE;
          }
        }
      }
    }

    return FALSE;
  }

}
