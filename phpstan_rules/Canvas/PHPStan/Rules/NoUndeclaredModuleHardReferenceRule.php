<?php

declare(strict_types=1);

namespace Canvas\PHPStan\Rules;

use PhpParser\Node;
use PhpParser\Node\Stmt\ClassMethod;
use PHPStan\Analyser\Scope;
use PHPStan\Rules\Rule;

/**
 * Flags an unguarded *hard* dereference of a class from an undeclared module.
 *
 * Covers the expression constructs that force PHP to autoload the referenced
 * class and therefore fatal ("Class … not found") when its module is not
 * enabled: `new X`, `X::CONST`, `X::method()`, `X::$prop`. `X::class` is
 * excluded (a compile-time string that never autoloads), as are `instanceof`,
 * type hints and docblocks (soft references that never fatal).
 *
 * Analysis is per-method so a `moduleHandler->moduleExists()` guard is honoured
 * — a properly-guarded reference is not reported.
 *
 * @see \Canvas\PHPStan\Rules\GuardedReferenceAnalyzer for the guard tracking.
 * @see \Canvas\PHPStan\Rules\ModuleDependencyResolver for how a reference is
 *   judged safe (own module, dependency closure, core, plugin ownership, or a
 *   `@drupalOptionalDependency` annotation).
 * @see \Canvas\PHPStan\Rules\NoUndeclaredModuleInheritanceRule for the
 *   `extends`/`implements` counterpart.
 *
 * @implements Rule<ClassMethod>
 */
final class NoUndeclaredModuleHardReferenceRule implements Rule {

  public function getNodeType(): string {
    return ClassMethod::class;
  }

  public function processNode(Node $node, Scope $scope): array {
    if ($node->stmts === NULL) {
      return [];
    }
    return GuardedReferenceAnalyzer::analyze($node->stmts, $scope);
  }

}
