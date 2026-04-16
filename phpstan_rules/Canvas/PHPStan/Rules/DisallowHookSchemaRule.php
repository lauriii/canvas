<?php

declare(strict_types=1);

namespace Canvas\PHPStan\Rules;

use PhpParser\Node;
use PhpParser\Node\Stmt\Function_;
use PHPStan\Analyser\Scope;
use PHPStan\Rules\Rule;
use PHPStan\Rules\RuleErrorBuilder;

/**
 * Disallows hook_schema() implementations.
 *
 * Tables should be created on demand via ::ensureTableExists() instead.
 *
 * @implements Rule<Function_>
 *
 * @see https://www.drupal.org/project/drupal/issues/3221051
 */
final class DisallowHookSchemaRule implements Rule {

  public function getNodeType(): string {
    return Function_::class;
  }

  public function processNode(Node $node, Scope $scope): array {
    if (!str_ends_with($node->name->toString(), '_schema')) {
      return [];
    }

    return [
      RuleErrorBuilder::message(
        'hook_schema() must not be implemented. Use ::ensureTableExists() with a static schemaDefinition() method instead. See https://www.drupal.org/project/drupal/issues/3221051.',
      )
        ->identifier('canvas.disallowHookSchema')
        ->build(),
    ];
  }

}
