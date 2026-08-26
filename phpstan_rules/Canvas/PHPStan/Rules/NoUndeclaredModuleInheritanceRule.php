<?php

declare(strict_types=1);

namespace Canvas\PHPStan\Rules;

use PhpParser\Node;
use PhpParser\Node\Stmt\Class_;
use PhpParser\Node\Stmt\Enum_;
use PhpParser\Node\Stmt\Interface_;
use PHPStan\Analyser\Scope;
use PHPStan\Node\InClassNode;
use PHPStan\Rules\Rule;

/**
 * Flags `extends`/`implements` of a class/interface from an undeclared module.
 *
 * Loading a class fatals if its parent class or an implemented interface
 * belongs to a module that is not enabled — the same "Class … not found"
 * failure as a hard expression dereference, but at class-load time. This is
 * the belt-and-suspenders counterpart to the expression rule: it catches an
 * always-loaded class that `extends` an optional module's base and is
 * instantiated directly (a `new` the expression rule cannot flag, because the
 * subclass belongs to the analysed module itself).
 *
 * The plugin-ownership suppression in ModuleDependencyReference applies here
 * too: a `Plugin\<module>\…` class extending <module>'s plugin base is only
 * loaded by <module>'s manager, so it is safe. Non-plugin optional-integration
 * base classes (form elements, synchronizers, processors) are reported and
 * must carry an explicit `@drupalOptionalDependency`.
 *
 * Runs on InClassNode so the class reflection is in scope for that suppression.
 *
 * @see \Canvas\PHPStan\Rules\NoUndeclaredModuleHardReferenceRule
 *
 * @implements Rule<InClassNode>
 */
final class NoUndeclaredModuleInheritanceRule implements Rule {

  public function getNodeType(): string {
    return InClassNode::class;
  }

  public function processNode(Node $node, Scope $scope): array {
    $original = $node->getOriginalNode();
    $parents = [];
    if ($original instanceof Class_) {
      if ($original->extends !== NULL) {
        $parents[] = $original->extends;
      }
      $parents = \array_merge($parents, $original->implements);
    }
    elseif ($original instanceof Interface_) {
      $parents = $original->extends;
    }
    elseif ($original instanceof Enum_) {
      $parents = $original->implements;
    }

    $errors = [];
    foreach ($parents as $parent) {
      foreach (UndeclaredModuleReference::check($parent, $scope) as $error) {
        $errors[] = $error;
      }
    }
    return $errors;
  }

}
