<?php

declare(strict_types=1);

namespace Canvas\PHPStan\Rules;

use PhpParser\Node;
use PHPStan\Analyser\Scope;
use PHPStan\Node\ClosureReturnStatementsNode;
use PHPStan\Node\ReturnStatementsNode;
use PHPStan\Reflection\MethodReflection;
use PHPStan\Rules\Rule;
use PHPStan\Rules\RuleErrorBuilder;
use PHPStan\Type\NeverType;

/**
 * Requires the `never` return type on functions and methods that never return.
 *
 * A function whose every code path ends by throwing or calling exit()/die(),
 * and that never returns a value, should declare `never` as its return type, so
 * callers and static analysis know it never returns.
 *
 * @see https://git.drupalcode.org/project/canvas/-/merge_requests/1513#note_1743239
 *
 * @implements Rule<ReturnStatementsNode>
 */
final class RequireNeverReturnTypeRule implements Rule {

  public function getNodeType(): string {
    return ReturnStatementsNode::class;
  }

  public function processNode(Node $node, Scope $scope): array {
    \assert($node instanceof ReturnStatementsNode);

    // Only named functions and methods can meaningfully declare `never`; skip
    // closures and arrow functions.
    if ($node instanceof ClosureReturnStatementsNode) {
      return [];
    }

    // Enforce this on production code only: test methods routinely end by
    // throwing (for example, the expected exception after expectException()).
    if (str_contains($scope->getFile(), '/tests/')) {
      return [];
    }

    // A generator returns a Generator, not `never`.
    if ($node->getYieldStatements() !== []) {
      return [];
    }

    // A function that returns anywhere (even a bare return;) is not never.
    if ($node->getReturnStatements() !== []) {
      return [];
    }

    // With no return statements, a function is `never` only when it cannot fall
    // through to its end: every path throws or exits.
    if (!$node->getStatementResult()->isAlwaysTerminating()) {
      return [];
    }

    $function = $scope->getFunction();
    if ($function === NULL) {
      return [];
    }

    // Constructors, destructors and clone handlers cannot declare a return
    // type, so they cannot be `never` even when they always throw.
    $no_return_type_methods = ['__construct', '__destruct', '__clone'];
    if ($function instanceof MethodReflection
      && \in_array($function->getName(), $no_return_type_methods, TRUE)) {
      return [];
    }

    // Already declared `never`: nothing to flag.
    if ($function->getVariants()[0]->getNativeReturnType() instanceof NeverType) {
      return [];
    }

    $name = $function instanceof MethodReflection
      ? \sprintf('Method %s::%s()', $function->getDeclaringClass()->getName(), $function->getName())
      : \sprintf('Function %s()', $function->getName());

    return [
      RuleErrorBuilder::message(
        \sprintf('%s never returns; declare `never` as its return type.', $name)
      )
        ->identifier('canvas.requireNeverReturnType')
        ->build(),
    ];
  }

}
