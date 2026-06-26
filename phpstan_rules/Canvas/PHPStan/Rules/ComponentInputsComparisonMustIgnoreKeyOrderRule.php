<?php

declare(strict_types=1);

namespace Canvas\PHPStan\Rules;

use Drupal\canvas\Plugin\Field\FieldType\ComponentTreeItem;
use Drupal\Tests\canvas\Kernel\CanvasKernelTestBase;
use PhpParser\Node;
use PhpParser\Node\Expr;
use PhpParser\Node\Expr\CallLike;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Expr\NullsafeMethodCall;
use PhpParser\Node\Expr\StaticCall;
use PhpParser\Node\Identifier;
use PHPStan\Analyser\Scope;
use PHPStan\Rules\Rule;
use PHPStan\Rules\RuleErrorBuilder;
use PHPStan\Type\ObjectType;
use PHPStan\Type\Type;
use PHPStan\Type\TypeCombinator;

/**
 * Forbids order-sensitive assertSame() comparisons of component inputs.
 *
 * Component instance `inputs` are stored in a JSON column whose object key
 * order is not preserved by every database backend (MySQL `json` and
 * PostgreSQL `jsonb` reorder keys; MariaDB and SQLite keep the source order).
 * Input key order is not semantically meaningful, so comparing the result of
 * ComponentTreeItem::getInputs() with the order-sensitive assertSame() produces
 * tests that pass on some database engines and fail on others — and even
 * intermittently. assertSameInputs() (in CanvasKernelTestBase) must be used
 * instead.
 *
 * Detection is type-based: the receiver of getInputs() must be a
 * ComponentTreeItem, so the unrelated AdapterInterface::getInputs() does not
 * trigger this rule.
 *
 * Only a getInputs() call that is *directly* an assertSame() argument is
 * flagged — i.e. the inputs array itself is being compared. This intentionally
 * excludes:
 * - extracting a single value, e.g. assertSame('_self', $i->getInputs()['x']);
 * - comparing inputs against the derived `inputs_resolved` (both share the same
 *   key order, so that comparison is not backend-dependent).
 *
 * Known limitation: a getInputs() call that reaches the assertion indirectly
 * (e.g. stored in a variable, or returned from an array_map() closure) is not
 * detected. This matches the pragmatic scope of the other Canvas rules.
 *
 * @see \Drupal\Tests\canvas\Kernel\CanvasKernelTestBase::assertSameInputs()
 *
 * @implements Rule<CallLike>
 */
final class ComponentInputsComparisonMustIgnoreKeyOrderRule implements Rule {

  public function getNodeType(): string {
    return CallLike::class;
  }

  public function processNode(Node $node, Scope $scope): array {
    if (!$node instanceof StaticCall && !$node instanceof MethodCall) {
      return [];
    }

    if (!$node->name instanceof Identifier || $node->name->name !== 'assertSame') {
      return [];
    }

    // Only enforce in CanvasKernelTestBase subclasses. isSubclassOf() returns
    // FALSE for CanvasKernelTestBase itself (where assertSameInputs() is
    // defined and uses assertSame() internally), which is exactly what we want.
    $classReflection = $scope->getClassReflection();
    if ($classReflection === NULL || !$classReflection->isSubclassOf(CanvasKernelTestBase::class)) {
      return [];
    }

    foreach ($node->getArgs() as $arg) {
      if ($this->isComponentInputsGetterCall($arg->value, $scope)) {
        return [
          RuleErrorBuilder::message(
            'Use self::assertSameInputs($expected, $actual) instead of assertSame() to compare component instance inputs. Input key order is database-backend-dependent (MySQL/PostgreSQL reorder JSON keys), so an order-sensitive comparison is flaky. The assertSameInputs() method is provided by CanvasKernelTestBase.'
          )
            ->identifier('canvas.componentInputsComparisonMustIgnoreKeyOrder')
            ->build(),
        ];
      }
    }

    return [];
  }

  /**
   * Whether an expression is directly a ComponentTreeItem::getInputs() call.
   *
   * Matches `$item->getInputs()` and the null-safe `…?->getInputs()`, but not
   * an expression that merely contains such a call (e.g. a subscript of it, or
   * an array_map() over it).
   */
  private function isComponentInputsGetterCall(Expr $expr, Scope $scope): bool {
    return ($expr instanceof MethodCall || $expr instanceof NullsafeMethodCall)
      && $expr->name instanceof Identifier
      && $expr->name->name === 'getInputs'
      && $this->isComponentTreeItemType($scope->getType($expr->var));
  }

  /**
   * Whether a type is a ComponentTreeItem (ignoring nullability).
   *
   * The `?->getInputs()` null-safe form yields a `ComponentTreeItem|null`
   * receiver type, so null is removed before the check.
   */
  private function isComponentTreeItemType(Type $type): bool {
    return (new ObjectType(ComponentTreeItem::class))
      ->isSuperTypeOf(TypeCombinator::removeNull($type))
      ->yes();
  }

}
